
import BaseParser, { type Transaction } from './BaseParser';

export default class Trading212Parser extends BaseParser {
    async parse(content: any): Promise<Transaction[]> {
        let rows: any[][];
        if (Array.isArray(content)) {
            // Already arrays (CSV parsed by PapaParse or similar)
            rows = content;
        } else if (content && typeof content === 'object') {
            // content is { SheetName: [[row], [row]], ... } for Excel
            const sheetName = Object.keys(content)[0];
            rows = content[sheetName];
        } else {
            return [];
        }

        if (!rows || rows.length < 2) return [];

        const headers = rows[0];
        const map = this.mapHeaders(headers);
        const transactions: Transaction[] = [];

        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            if (!row || row.length === 0) continue;

            const action = this.getVal(row, map, 'Action');
            if (!action) continue;

            // Map T212 actions
            let transType = 'Unknown';
            const actionLower = String(action).toLowerCase();
            if (actionLower.includes('buy')) transType = 'Buy';
            else if (actionLower.includes('sell')) transType = 'Sell';
            else if (actionLower.includes('deposit')) transType = 'Deposit';
            else if (actionLower.includes('withdraw')) transType = 'Withdrawal';
            else if (actionLower.includes('dividend')) transType = 'Dividend';

            // Extract Ticker
            const ticker = this.getVal(row, map, 'Ticker');

            // Numbers
            const noOfShares = this.parseNumber(this.getVal(row, map, 'No. of shares'));
            const price = this.parseNumber(this.getVal(row, map, 'Price / share'));

            // "Total" is usually in ACCOUNT currency
            let totalAccountCur = this.parseNumber(this.getVal(row, map, 'Total'));
            let accountCurrency = this.getVal(row, map, 'Currency (Total)');
            let quoteCurrency = this.getVal(row, map, 'Currency (Price / share)');

            if (!quoteCurrency) {
                quoteCurrency = accountCurrency;
            }

            // Determine Transaction Currency
            // For Buy/Sell/Div: We want the ASSET currency if possible (but T212 often gives Total in Acc Cur).
            // Logic moved from JS:
            let currency = (transType === 'Buy' || transType === 'Sell' || transType === 'Dividend')
                ? quoteCurrency
                : accountCurrency;

            if (!currency) currency = 'CZK';

            const dateStr = this.getVal(row, map, 'Time');
            let txId = this.getVal(row, map, 'ID') || this.getVal(row, map, 'Transaction ID');

            const exchangeRate = this.parseNumber(this.getVal(row, map, 'Exchange rate')) || 1;

            // Fees
            const stampDuty = this.parseNumber(this.getVal(row, map, 'Stamp duty reserve tax')) || 0;
            const charge = this.parseNumber(this.getVal(row, map, 'Charge amount')) || 0;
            const conversionFee = this.parseNumber(this.getVal(row, map, 'Currency conversion fee')) || 0;
            const depositFee = this.parseNumber(this.getVal(row, map, 'Deposit fee')) || 0;
            let wTax = this.parseNumber(this.getVal(row, map, 'Withholding tax')) || 0;
            const wTaxCurrency = this.getVal(row, map, 'Currency (Withholding tax)');

            // Fee Object for Normalizer
            const feesObj: any = {};
            // Sum basic fees (usually in Account Currency or Quote Currency depending on column?)
            // T212 usually reports "Currency conversion fee" in Account Currency? Or Quote?
            // Let's assume fees are in Account Currency for now unless proven otherwise (T212 is tricky).
            // But wTax might be different.

            // We store them in tmp object for Normalizer to recalc.
            // Simplified logic: Just use wTax.
            if (wTax > 0) {
                if (wTaxCurrency && wTaxCurrency !== currency && wTaxCurrency === accountCurrency) {
                    // Tax in Acc Cur, Tx in Asset Cur. 
                    // Store it raw
                    feesObj.feeFiat = wTax;
                    feesObj.feeFiatCurrency = wTaxCurrency;
                } else {
                    feesObj.feeCur = wTax;
                }
            }

            // Other fees
            let otherFees = (stampDuty || 0) + (charge || 0) + (conversionFee || 0) + (depositFee || 0);
            if (otherFees > 0) {
                // Usually in Account Currency?
                if (!feesObj.feeFiat) feesObj.feeFiat = 0;
                feesObj.feeFiat += otherFees;
                feesObj.feeFiatCurrency = accountCurrency; // Assuming other fees are in Acc Cur
            }

            // Amount
            let amount = 0;
            if (transType === 'Buy' || transType === 'Sell') {
                amount = noOfShares || 0;
            } else {
                amount = totalAccountCur || 0;
            }

            // Amount Cur (Total value in transaction currency)
            let amountCur = 0;
            if (transType === 'Buy' || transType === 'Sell') {
                // If currency is Quote Currency, amountCur = NoOfShares * Price ??
                // No, amountCur usually means "Total Cash Value affected".
                // If Buy 1 stock at 100 USD. amountCur = 100.
                // If T212 "Total" is in CZK (2300 CZK).
                // We want amount_cur in USD?
                if (price && noOfShares) {
                    amountCur = price * noOfShares;
                } else {
                    // Fallback
                    amountCur = totalAccountCur || 0;
                    if (currency !== accountCurrency && exchangeRate) {
                        amountCur = (totalAccountCur || 0) / exchangeRate;
                    }
                }
            } else {
                amountCur = totalAccountCur || 0;
                if (currency !== accountCurrency && exchangeRate) {
                    amountCur = (totalAccountCur || 0) / exchangeRate;
                }
            }

            // Handling Dividend Amount
            if (transType === 'Dividend') {
                // T212 reports "Amount" (Gross) usually? Or logic needs to check columns.
                // "Amount" column usually exists for dividends in some exports.
                // If not, use logic above.
            }

            // Creating Transaction
            const tx: Transaction = {
                id: txId,
                date: dateStr, // Will be normalized later
                platform: 'Trading 212',
                currency: currency,
                trans_type: transType,
                product_type: 'Stock', // Default
                ticker: ticker, // Custom prop for mapping later
                company_name: this.getVal(row, map, 'Name'),
                isin: this.getVal(row, map, 'ISIN'),
                notes: `T212 Import ${txId}`,

                amount: amount,
                price: price,
                amount_cur: amountCur,

                __tmp: feesObj
            } as any; // Using any because ticker is not in Interface but Import logic uses it to map to 'id' (which is ticker in DB usually, or ticker is separate?)

            // Wait, standard Transaction interface (Step 1117) has:
            // id (DB ID of ticker?), isin, company_name. 
            // In Import logic: `id` usually maps to TICkER for Stocks. 
            // `normalizeTransaction` line 103: `id: (tx.id || 'UNKNOWN')`. This 'id' IS THE TICKER!
            // But here `tx.id` is Transaction ID.
            // I should map `ticker` -> `id` for the Normalizer!

            tx.id = ticker; // Map ticker to ID field for normalizer
            tx.notes += ` (TxID: ${txId})`;

            transactions.push(tx);
        }

        return transactions;
    }
}
