// /broker/js/parsers/etoro/EtoroParser.js
import BaseParser from '../BaseParser.js';

export default class EtoroParser extends BaseParser {
    async parse(content) {
        // content is { SheetName: [[row], [row]], ... }
        const transactions = [];

        // eToro usually has "Closed Positions" and "Transactions Report"
        // We prioritize "Closed Positions" for trades and "Transactions Report" for deposits/withdrawals/fees

        const closedPositions = content['Closed Positions'] || [];
        const transactionsReport = content['Transactions Report'] || [];

        // 1. Parse Closed Positions (Trades)
        if (closedPositions.length > 0) {
            const headers = closedPositions[0];
            const map = this.mapHeaders(headers);

            for (let i = 1; i < closedPositions.length; i++) {
                const row = closedPositions[i];
                if (!row || row.length === 0) continue;

                const action = this.getVal(row, map, 'Action');
                if (!action) continue; // Skip footer/empty

                // eToro format: "Buy Bitcoin", "Sell Apple"
                const type = action.startsWith('Buy') ? 'Buy' : (action.startsWith('Sell') ? 'Sell' : action);
                const amount = parseFloat(this.getVal(row, map, 'Amount') || 0);
                const units = parseFloat(this.getVal(row, map, 'Units') || 0);
                const openRate = parseFloat(this.getVal(row, map, 'Open Rate') || 0);
                const closeRate = parseFloat(this.getVal(row, map, 'Close Rate') || 0);
                const profit = parseFloat(this.getVal(row, map, 'Profit') || 0); // USD
                const openDate = this.parseDate(this.getVal(row, map, 'Open Date'));
                const closeDate = this.parseDate(this.getVal(row, map, 'Close Date'));
                const isReal = String(this.getVal(row, map, 'Is Real')).toLowerCase() === 'real';

                if (!isReal) continue; // Skip virtual trades

                // Construct transaction
                // eToro is tricky because one line is a closed position (entry + exit)
                // We will record the CLOSE event as the realization of P&L

                transactions.push({
                    date: closeDate,
                    id: String(this.getVal(row, map, 'Position ID')),
                    amount: profit, // For P&L tracking, or use total value? 
                    // Standard broker format usually wants:
                    // Buy: amount = -cost
                    // Sell: amount = +proceeds
                    // Here we have a "Closed Position" line.
                    // Let's treat it as a SELL (or Cover)

                    // Actually, for portfolio tracking, we might want individual legs.
                    // But eToro export is position-based.
                    // Let's stick to the "Closed Position" logic:
                    // We gained 'Profit' on top of 'Amount' (invested).
                    // So proceeds = Amount + Profit.

                    amount_cur: amount + profit, // Total returned
                    currency: 'USD', // eToro account is usually USD
                    product_type: this.detectProduct(action),
                    trans_type: 'Sell', // Closing a position is effectively a Sell
                    platform: 'etoro',
                    price: closeRate,
                    fees: parseFloat(this.getVal(row, map, 'Spread') || 0) + parseFloat(this.getVal(row, map, 'Fees') || 0),
                    notes: `Closed: ${action} (Profit: ${profit})`
                });
            }
        }

        // 2. Parse Transactions Report (Deposits, Withdrawals, Dividends)
        if (transactionsReport.length > 0) {
            const headers = transactionsReport[0];
            const map = this.mapHeaders(headers);

            for (let i = 1; i < transactionsReport.length; i++) {
                const row = transactionsReport[i];
                if (!row || row.length === 0) continue;

                const type = this.getVal(row, map, 'Type');
                const details = this.getVal(row, map, 'Details');
                const amount = parseFloat(this.getVal(row, map, 'Amount') || 0);
                const date = this.parseDate(this.getVal(row, map, 'Date'));
                const positionId = this.getVal(row, map, 'Position ID');

                if (type === 'Deposit' || type === 'Withdraw Request' || type === 'Dividend') {
                    transactions.push({
                        date: date,
                        id: positionId || `TX-${i}`,
                        amount: amount,
                        amount_cur: amount,
                        currency: 'USD',
                        trans_type: type === 'Withdraw Request' ? 'Withdrawal' : type,
                        platform: 'etoro',
                        product_type: type === 'Dividend' ? 'Stock' : 'Cash',
                        notes: details
                    });
                }
            }
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
        return null;
    }

    parseDate(dateStr) {
        // eToro: "01/05/2024 10:20:30" (DD/MM/YYYY)
        if (!dateStr) return null;
        const parts = String(dateStr).split(' ')[0].split('/');
        if (parts.length === 3) {
            return `${parts[2]}-${parts[1]}-${parts[0]}`;
        }
        return dateStr; // Fallback
    }

    detectProduct(action) {
        if (/Bitcoin|Ethereum|XRP|Crypto/i.test(action)) return 'Crypto';
        return 'Stock';
    }
}
