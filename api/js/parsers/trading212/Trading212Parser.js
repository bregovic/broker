
import BaseParser from '../BaseParser.js';

export default class Trading212Parser extends BaseParser {
    async parse(content) {
        let rows;
        if (Array.isArray(content)) {
            rows = content;
        } else {
            // content is { SheetName: [[row], [row]], ... }
            const sheetName = Object.keys(content)[0];
            rows = content[sheetName];
        }

        if (!rows || rows.length < 2) return [];

        const headers = rows[0];
        const map = this.mapHeaders(headers);
        const transactions = [];

        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            if (!row || row.length === 0) continue;

            const action = this.getVal(row, map, 'Action');
            if (!action) continue;

            // Map T212 actions
            let transType = 'Unknown';
            const actionLower = action.toLowerCase();
            if (actionLower.includes('buy')) transType = 'Buy';
            else if (actionLower.includes('sell')) transType = 'Sell';
            else if (actionLower.includes('deposit')) transType = 'Deposit';
            else if (actionLower.includes('withdraw')) transType = 'Withdrawal';
            else if (actionLower.includes('dividend')) transType = 'Dividend';

            // Extract Ticker
            const ticker = this.getVal(row, map, 'Ticker');
            const name = this.getVal(row, map, 'Name');
            const isin = this.getVal(row, map, 'ISIN');

            const noOfShares = this.parseNumber(this.getVal(row, map, 'No. of shares'));
            const price = this.parseNumber(this.getVal(row, map, 'Price / share'));

            // "Total" is usually in ACCOUNT currency (CZK)
            let totalAccountCur = this.parseNumber(this.getVal(row, map, 'Total'));
            let accountCurrency = this.getVal(row, map, 'Currency (Total)');

            // Determine Quote Currency (Asset Currency)
            // Ideally from "Currency (Price / share)"
            let quoteCurrency = this.getVal(row, map, 'Currency (Price / share)');

            // If explicit quote currency column missing, fallback to Total currency
            // But if Price/share exists and Total exists, we can try to guess or just use Account currency
            if (!quoteCurrency) {
                quoteCurrency = accountCurrency;
            }

            // Determine Transaction Currency
            // For Buy/Sell: We want the ASSET currency (e.g. EUR for ZPRV)
            // For Deposit/Withdraw: We want Account currency
            let currency = (transType === 'Buy' || transType === 'Sell' || transType === 'Dividend')
                ? quoteCurrency
                : accountCurrency;

            // Fallback
            if (!currency) currency = 'CZK';

            const dateStr = this.getVal(row, map, 'Time');
            let txId = this.getVal(row, map, 'ID') || this.getVal(row, map, 'Transaction ID');

            const exchangeRate = this.parseNumber(this.getVal(row, map, 'Exchange rate')) || 1;

            // Fees & Taxes (always in Account Currency in T212 CSV?)
            // Actually, "Currency (Withholding tax)" tells us.

            const stampDuty = this.parseNumber(this.getVal(row, map, 'Stamp duty reserve tax')) || 0;
            const charge = this.parseNumber(this.getVal(row, map, 'Charge amount')) || 0;
            const conversionFee = this.parseNumber(this.getVal(row, map, 'Currency conversion fee')) || 0;
            const depositFee = this.parseNumber(this.getVal(row, map, 'Deposit fee')) || 0;

            let wTax = this.parseNumber(this.getVal(row, map, 'Withholding tax')) || 0;
            const wTaxCurrency = this.getVal(row, map, 'Currency (Withholding tax)');

            // Normalize Tax to Transaction Currency if needed
            // If our MAIN currency is EUR, but Tax is reported in CZK (or vice versa), we might need conversion.
            // Usually we sum fees in Account Currency? No, the system expects 'fees' in 'amount_czk' logic if possible?
            // Actually TransactionNormalizer calculates fees in CZK.
            // But here we return "fees" as a number. Normalizer expects fees in... ? 
            // Normalizer line 76: "normalized.fees = parseFloat(tx.fees) || 0;"
            // It assumes fees are in the SAME currency as the transaction or handled specially.
            // Let's assume fees are small and just sum them.
            // Wait, correct logic for Withholding Tax:
            // If WHT is in a different currency than Account, we should convert it?
            // Simple approach: T212 usually reports everything converted to Account Currency in columns like "Total".
            // But WHT column might be in original currency.

            // Let's stick to the previous fix for WHT:
            if (wTax > 0 && wTaxCurrency && accountCurrency && wTaxCurrency !== accountCurrency) {
                // Convert WHT to Account Currency approx
                wTax = wTax * exchangeRate;
            }

            let fees = stampDuty + charge + conversionFee + depositFee + wTax;

            // Date
            const date = dateStr ? String(dateStr).split(' ')[0] : null;

            // Amount Logic
            let amount = 0;     // Quantity (Units)
            let amount_cur = 0; // Money Value in Transaction Currency

            if (transType === 'Buy' || transType === 'Sell') {
                amount = noOfShares || 0;
                // If currency is EUR, and Total is CZK.
                // Value in EUR = Total(CZK) * ExchangeRate (if Rate is CZK->EUR) OR Total(CZK) / Rate (if EUR->CZK).
                // T212 Rate is usually "Instrument currency / Account currency" or vice versa.
                // Example: ZPRV (EUR), Account (CZK). Rate ~0.04.  1 CZK = 0.04 EUR.
                // So Total(CZK) * Rate = Value(EUR).
                // Let's trust Price * Shares first as it is cleaner for Buy/Sell.
                amount_cur = Math.abs((price || 0) * (noOfShares || 0));
            } else {
                // Deposit / Dividend
                amount = Math.abs(totalAccountCur || 0); // Logic amount
                // For Dividend, if currency is EUR, we want Dividend Value in EUR.
                // If T212 gives us Dividend (CZK), we need to revert?
                // Or just trust "Amount" column if it exists? T212 doesn't have "Amount" for money.
                // If Dividend, check "Price / share" - usually empty.
                // If WHT was involved, Gross Dividend might be different.

                // For simplification: If Dividend in foreign currency, T212 CSV usually has "Total" in Account Currency.
                // We might need to recalculate to Foreign Currency if we flagged it as such.
                if (transType === 'Dividend' && currency !== accountCurrency) {
                    amount_cur = Math.abs(totalAccountCur * exchangeRate);
                } else {
                    amount_cur = Math.abs(totalAccountCur || 0);
                }
            }

            // Logic ID
            let logicId = ticker;
            if (!logicId) {
                if (transType === 'Deposit' || transType === 'Withdrawal') logicId = 'CASH_' + currency;
                else if (transType === 'Dividend') logicId = '';
            }

            const resultPl = this.parseNumber(this.getVal(row, map, 'Result'));

            transactions.push({
                date: date,
                id: logicId,
                external_id: String(txId),
                amount: amount,
                amount_cur: amount_cur, // Corrected value in Transaction Currency
                currency: currency,     // Corrected Currency (Asset Currency)
                trans_type: transType,
                platform: 'trading212',
                product_type: (transType === 'Deposit' || transType === 'Withdrawal') ? 'Cash' : 'Stock',
                price: price || 0,
                fees: fees,
                notes: `${action} ${ticker || ''} (${name || ''})`.trim() + (resultPl ? ` | Result: ${resultPl}` : ''),
                ex_rate: exchangeRate,
                isin: isin,
                company_name: name
            });
        }

        return transactions;
    }

    mapHeaders(headers) {
        const map = {};
        headers.forEach((h, i) => {
            if (h) map[String(h).trim()] = i;
        });
        return map;
    }

    getVal(row, map, colName) {
        if (map[colName] !== undefined) return row[map[colName]];
        const key = Object.keys(map).find(k => k.includes(colName));
        if (key) return row[map[key]];
        return null;
    }
}
