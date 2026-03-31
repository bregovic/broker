// /broker/js/parsers/revolut/RevolutTradingParserV3.js
import BaseParser from '../BaseParser.js';

export default class RevolutTradingParser extends BaseParser {
    /**
     * Parse Revolut Trading content
     */
    async parse(content) {
        // Determine content type
        if (typeof content === 'string') {
            return this.parsePdf(content);
        } else if (Array.isArray(content)) {
            return this.parseCsv(content);
        }

        throw new Error('Neplatný formát obsahu pro Revolut Trading');
    }

    /**
     * Parse PDF content
     */
    parsePdf(text) {
        // Clean and normalize text
        let cleanText = text
            .replace(/\u00A0/g, ' ')
            .replace(/\s{2,}/g, ' ')
            .replace(/US\$/g, 'USD ')
            .replace(/([0-9])(?=Buy)/g, '$1 ')
            .replace(/([0-9])(?=Sell)/g, '$1 ')
            .trim();

        const transactions = [];

        // Split by date pattern
        const chunks = cleanText.split(/\s(?=\d{2}\s\w{3}\s\d{4}\s\d{2}:\d{2}:\d{2}\sGMT)/g);

        for (const chunk of chunks) {
            if (!/\d{2}\s\w{3}\s\d{4}\s\d{2}:\d{2}:\d{2}\sGMT/.test(chunk)) continue;

            // Extract date
            const dateMatch = chunk.match(/(\d{2}\s\w{3}\s\d{4})\s(\d{2}:\d{2}:\d{2})\sGMT/);
            const date = dateMatch ? this.enDateToISO(dateMatch[1]) : '';

            // Parse different transaction types
            const tx = this.parseChunk(chunk, date);
            if (tx) {
                transactions.push(tx);
            }
        }

        return transactions;
    }

    /**
     * Parse single chunk
     */
    parseChunk(chunk, date) {
        let match;

        // Cash operations
        match = chunk.match(/Cash (top-up|withdrawal)\s+USD\s*([0-9.,\-]+)/i);
        if (match) {
            const isDeposit = /top-up/i.test(match[1]);
            const amount = this.parseNumber(match[2]);

            return {
                date,
                id: 'CASH_USD',
                amount: 1,
                price: amount,
                amount_cur: amount,
                currency: 'USD',
                platform: 'Revolut',
                product_type: 'Cash',
                trans_type: isDeposit ? 'Deposit' : 'Withdrawal',
                notes: `Cash ${isDeposit ? 'top-up' : 'withdrawal'}`
            };
        }

        // Dividend
        match = chunk.match(/\b([A-Z0-9.]{1,10})\s+Dividend\s+USD\s*([0-9.,]+)/);
        if (match) {
            const value = this.parseNumber(match[2]);
            return {
                date,
                id: match[1],
                amount: 1,
                price: value,
                amount_cur: value,
                currency: 'USD',
                platform: 'Revolut',
                product_type: 'Stock',
                trans_type: 'Dividend',
                notes: 'Dividend'
            };
        }

        // Custody fee
        match = chunk.match(/Custody fee\s+-?USD\s*([0-9.,]+)/i);
        if (match) {
            const value = this.parseNumber(match[1]) || 0;
            return {
                date,
                id: 'FEE_CUSTODY',
                amount: 1,
                price: value,
                amount_cur: -value,
                currency: 'USD',
                platform: 'Revolut',
                product_type: 'Fee',
                trans_type: 'Fee',
                fees: value,
                notes: 'Custody fee'
            };
        }

        // Trade
        match = chunk.match(
            /\b([A-Z0-9.]{1,10})\s+Trade\s+-\s+(Market|Limit)\s+([0-9.]+)\s+USD\s*([0-9.,]+)\s+(Buy|Sell)\s+USD\s*([0-9.,\-]+)\s+USD\s*([0-9.,\-]+)\s+USD\s*([0-9.,\-]+)/i
        );
        if (match) {
            const quantity = this.parseNumber(match[3]);
            const price = this.parseNumber(match[4]);
            const side = match[5].toUpperCase();
            const value = this.parseNumber(match[6]);
            const fees = Math.abs(this.parseNumber(match[7]) || 0);
            const extra = Math.abs(this.parseNumber(match[8]) || 0);

            return {
                date,
                id: match[1],
                amount: quantity,
                price,
                amount_cur: value,
                currency: 'USD',
                platform: 'Revolut',
                product_type: 'Stock',
                trans_type: side === 'BUY' ? 'Buy' : 'Sell',
                fees: fees + extra,
                notes: `Trade - ${match[2]} ${side}`
            };
        }

        // Corporate actions
        if (/Spinoff|Transfer from|Transfer to/i.test(chunk)) {
            const symbolMatch = chunk.match(/\b([A-Z0-9.]{1,10})\b/);
            return {
                date,
                id: symbolMatch ? symbolMatch[1] : 'CORP_ACTION',
                amount: 1,
                price: 0,
                amount_cur: 0,
                currency: 'USD',
                platform: 'Revolut',
                product_type: 'Stock',
                trans_type: 'Other',
                notes: 'Corporate action / transfer'
            };
        }

        return null;
    }

    /**
     * Parse CSV content
     */
    parseCsv(rows) {
        const transactions = [];

        if (rows.length < 2) return transactions;

        // Parse headers
        const headers = rows[0].map(h => (h || '').toString().toLowerCase());
        const columnMap = this.mapColumns(headers);

        // Parse data rows
        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            if (!row || !row.length) continue;

            const tx = this.parseRow(row, columnMap);
            if (tx) {
                transactions.push(tx);
            }
        }

        return transactions;
    }

    /**
     * Map column indices
     */
    mapColumns(headers) {
        const map = {};
        const columnNames = {
            date: ['date', 'datum'],
            ticker: ['ticker', 'symbol'],
            type: ['type', 'typ'],
            quantity: ['quantity', 'množství'],
            price: ['price per share', 'price', 'cena'],
            total: ['total amount', 'total', 'celkem'],
            currency: ['currency', 'měna'],
            fxRate: ['fx rate', 'kurz']
        };

        for (const [key, names] of Object.entries(columnNames)) {
            for (const name of names) {
                const index = headers.indexOf(name);
                if (index !== -1) {
                    map[key] = index;
                    break;
                }
            }
        }

        return map;
    }

    /**
     * Parse single CSV row
     */
    parseRow(row, columnMap) {
        // Extract values
        const ticker = row[columnMap.ticker] || null;
        const type = (row[columnMap.type] || '').toString().toUpperCase();
        const quantity = this.parseNumber(row[columnMap.quantity]);
        const price = this.parseNumber(row[columnMap.price]);

        // Parse total amount with currency
        const totalData = this.extractCurrencyAndNumber(row[columnMap.total]);
        const totalAmount = totalData.num ?? 0;
        const currency = row[columnMap.currency] || totalData.currency || 'USD';

        const fxRate = this.parseNumber(row[columnMap.fxRate]);

        // Parse date
        let date = '';
        const dateStr = row[columnMap.date];
        if (dateStr) {
            try {
                date = new Date(dateStr).toISOString().split('T')[0];
            } catch { }
        }

        // Determine transaction type
        let transType = 'Other';
        let transactionId = ticker;
        let productType = 'Stock';
        let actualAmount = quantity;
        let actualPrice = price;

        switch (type) {
            case 'BUY':
            case 'BUY - MARKET':
            case 'BUY - LIMIT':
                transType = 'Buy';
                break;

            case 'SELL':
            case 'SELL - MARKET':
            case 'SELL - LIMIT':
                transType = 'Sell';
                break;

            case 'DIVIDEND':
                transType = 'Dividend';
                actualAmount = 1;
                actualPrice = totalAmount;
                break;

            case 'CASH TOP-UP':
                transType = 'Deposit';
                transactionId = 'CASH_' + currency;
                productType = 'Cash';
                actualAmount = 1;
                actualPrice = totalAmount;
                break;

            case 'CASH WITHDRAWAL':
                transType = 'Withdrawal';
                transactionId = 'CASH_' + currency;
                productType = 'Cash';
                actualAmount = 1;
                actualPrice = totalAmount;
                break;

            case 'CUSTODY FEE':
            case 'CUSTODYFEE':
                transType = 'Fee';
                transactionId = 'FEE_CUSTODY';
                productType = 'Fee';
                actualAmount = 1;
                actualPrice = Math.abs(totalAmount);
                break;

            default:
                transType = 'Other';
                transactionId = transactionId || ('OTHER_' + Date.now());
                actualAmount = 1;
                actualPrice = totalAmount;
        }

        return {
            date,
            id: transactionId,
            amount: actualAmount,
            price: actualPrice,
            ex_rate: fxRate || null,
            amount_cur: totalAmount,
            currency: currency.toUpperCase(),
            platform: 'Revolut',
            product_type: productType,
            trans_type: transType,
            notes: `Import: ${type}`
        };
    }
}
