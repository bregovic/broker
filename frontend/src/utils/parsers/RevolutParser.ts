
import BaseParser, { type Transaction } from './BaseParser';
import RevolutCryptoParser from './RevolutCryptoParser';
import RevolutCommodityParser from './RevolutCommodityParser';

export default class RevolutParser extends BaseParser {

    private cryptoParser = new RevolutCryptoParser();
    private commodityParser = new RevolutCommodityParser();

    private monthMap: Record<string, string> = {
        'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04', 'May': '05', 'Jun': '06',
        'Jul': '07', 'Aug': '08', 'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12',
        // Czech abbreviations and full names
        'led': '01', 'úno': '02', 'bře': '03', 'dub': '04', 'kvě': '05', 'čvn': '06',
        'čvc': '07', 'srp': '08', 'zář': '09', 'říj': '10', 'lis': '11', 'pro': '12',
        'leden': '01', 'únor': '02', 'březen': '03', 'duben': '04', 'květen': '05', 'červen': '06',
        'červenec': '07', 'srpen': '08', 'září': '09', 'říjen': '10', 'listopad': '11', 'prosinec': '12'
    };

    /**
     * Dispatcher method to choose the right parser based on content.
     */
    async parse(content: any): Promise<Transaction[]> {
        // 1. Detect Type from String (PDF Text or CSV Text)
        if (typeof content === 'string') {
            // Check for Crypto Metadata
            if (/Výpis z účtu s kryptoměnami|Crypto (Account )?Statement|Revolut Digital Assets Europe Ltd|Odměna ze stakingu/i.test(content)) {
                return this.cryptoParser.parse(content);
            }
            // Check for Commodity Metadata
            if (/Výpis v\s+(XAU|XAG|XPT|XPD)|Směněno na\s+(XAU|XAG|XPT|XPD)|Commodity (Account )?Statement/i.test(content)) {
                return this.commodityParser.parse(content);
            }

            // CSV Detection
            const firstLine = content.split('\n')[0].toLowerCase();
            const hasCsvHeaders = firstLine.includes('type') || firstLine.includes('date') || firstLine.includes('symbol') || firstLine.includes('ticker') || firstLine.includes('commodity') || firstLine.includes('action');

            const isCsv = (content.includes(',') || content.includes(';')) &&
                !content.includes('%PDF-') &&
                content.split('\n').length > 1 &&
                !/Statement|Výpis/i.test(content) &&
                hasCsvHeaders;

            if (isCsv) {
                if ((firstLine.includes('symbol') || firstLine.includes('ticker') || firstLine.includes('crypto')) && (firstLine.includes('type') || firstLine.includes('typ'))) {
                    return this.cryptoParser.parseCsv(content);
                }
                if (firstLine.includes('commodity') || firstLine.includes('underlying') || (firstLine.includes('asset') && firstLine.includes('type'))) {
                    return this.commodityParser.parseCsv(content);
                }
                return this.parseTradingCsv(content);
            }

            return this.parseTradingPdf(content);
        }

        throw new Error('Unsupported content format for Revolut parser.');
    }

    parseTradingPdf(text: string): Transaction[] {
        // Extract ticker mappings
        const mappingText = text.replace(/\u00A0/g, ' ');
        const tickerMappings = this.extractTickerMappings(mappingText);

        // Clean text
        let cleanText = text
            .replace(/\u00A0/g, ' ')
            .replace(/\s{2,}/g, ' ')
            .replace(/US\$/g, 'USD ')
            .replace(/€/g, 'EUR ')
            .replace(/([0-9])(?=Buy|Nákup)/gi, '$1 ')
            .replace(/([0-9])(?=Sell|Prodej)/gi, '$1 ')
            .trim();

        const transactions: Transaction[] = [];

        // Universal Date Splitter (Trading Timestamp OR Crypto Date OR CZ Date)
        // Groups: Not capturing for split
        const splitRegex = /\s(?=(?:\d{1,2}\s[\w\u00C0-\u024F]{2,}\s\d{4}\s\d{2}:\d{2}:\d{2}\sGMT)|(?:\d{1,2}\s[\w\u00C0-\u024F]{2,}\s\d{4})|(?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4}))/g;

        const chunks = cleanText.split(splitRegex);

        for (const chunk of chunks) {
            let date = '';

            // 1. Try Trading Timestamp
            let dMatch = chunk.match(/(\d{1,2})\s([\w\u00C0-\u024F]{2,})\s(\d{4})\s(\d{2}:\d{2}:\d{2})\sGMT/);
            if (dMatch) {
                date = this.parseDateStr(dMatch[1], dMatch[2], dMatch[3]);
            } else {
                // 2. Try Simple Date (EN/CZ)
                dMatch = chunk.match(/(\d{1,2})\s([\w\u00C0-\u024F]{2,})\s(\d{4})/);
                if (dMatch) {
                    date = this.parseDateStr(dMatch[1], dMatch[2], dMatch[3]);
                } else {
                    // 3. Try Numeric Date (CZ) e.g. 21. 2. 2021
                    dMatch = chunk.match(/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/);
                    if (dMatch) {
                        date = this.parseDateStr(dMatch[1], dMatch[2], dMatch[3]);
                    }
                }
            }

            if (!date) continue; // Not a valid chunk

            let tx: Transaction | null = null;
            let match;

            // --- TRADING / STOCK LOGIC ---
            // Trade (EN/CZ)
            if ((match = chunk.match(
                /\b([A-Z0-9.]{1,10})\s+(?:Trade|Obchod)\s+-\s+(?:Market|Limit|Tržní|Limitní)\s+([0-9.,\s]+)\s+([A-Z]{3})\s*([0-9.,\s]+)\s+(Buy|Sell|Nákup|Prodej)\s+([A-Z]{3})\s*([0-9.,\s\-]+)\s+([A-Z]{3})\s*([0-9.,\s\-]+)\s+([A-Z]{3})\s*([0-9.,\s\-]+)/i
            ))) {
                // Improved check for false positives like INDUSTRIES, INC, etc.
                if (['INDUSTRIES', 'INC', 'LTD', 'CORP', 'LIMITED', 'PLC'].includes(match[1].toUpperCase())) continue;

                const quantity = this.parseNumber(match[2]);
                const currency = match[3].toUpperCase();
                const price = this.parseNumber(match[4]);
                const sideStr = match[5].toLowerCase();
                // Value usually at group 7
                const value = this.parseNumber(match[7]);
                const fees1 = Math.abs(this.parseNumber(match[9]) || 0);
                const fees2 = Math.abs(this.parseNumber(match[11]) || 0);
                const totalFees = fees1 + fees2;

                const isBuy = sideStr.includes('buy') || sideStr.includes('nákup') || sideStr.includes('koup');

                tx = {
                    date,
                    id: match[1],
                    amount: quantity || 0,
                    price: price || 0,
                    amount_cur: value || 0,
                    currency: currency,
                    platform: 'Revolut',
                    product_type: 'Stock',
                    trans_type: isBuy ? 'Buy' : 'Sell',
                    fees: totalFees,
                    notes: `Trade`
                };
            }
            // --- CRYPTO LOGIC ---
            // Buy/Sell Ticker ... (Crypto style)
            else if ((match = chunk.match(/(?:Buy|Sell|Nákup|Prodej)\s+([A-Z0-9]{2,10}).*?([0-9.,\s]+)\s*[A-Z0-9]{2,10}.*?(€|\$|CZK|USD|EUR)\s*([0-9.,\s]+)/i))) {
                // Try to avoid false positives (Cash top-up usually doesn't match this structure exactly, but be careful)
                // Crypto regex: Keyword Ticker ... Qty Ticker ... Cur Value
                // Example: Nákup BTC ... 0,1 BTC ... CZK 1000

                const sideStr = match[0].split(' ')[0].toLowerCase(); // First word is keyword
                const ticker = match[1].toUpperCase();

                // Explicitly ignore blocked tickers in crypto logic too if needed, though mostly relevant for Stocks
                if (['INDUSTRIES', 'INC', 'LTD', 'CORP', 'LIMITED', 'PLC'].includes(ticker)) continue;

                const qty = this.parseNumber(match[2]);
                const curSymbol = match[3];
                const val = this.parseNumber(match[4]);

                let currency = curSymbol.toUpperCase();
                if (currency === '$') currency = 'USD';
                if (currency === '€') currency = 'EUR';

                const isBuy = sideStr.includes('buy') || sideStr.includes('nákup') || sideStr.includes('koup');

                // If ID is actually "USD" or "EUR", it might be FX or Cash, but assuming Crypto if Structure matches
                if (ticker !== 'USD' && ticker !== 'EUR') {
                    tx = {
                        date,
                        id: ticker,
                        amount: qty || 0,
                        price: (qty && val) ? (val / qty) : 0,
                        amount_cur: val || 0,
                        currency: currency,
                        platform: 'Revolut',
                        product_type: 'Crypto',
                        trans_type: isBuy ? 'Buy' : 'Sell',
                        fees: 0, // Fees parsing in crypto is complex, skipping for simplicity or needs better regex
                        notes: `Crypto Trade`
                    };
                }
            }
            // --- CASH / DIV / FEE ---
            else if ((match = chunk.match(/(?:Cash|Hotovost)\s+(top-up|withdrawal|vklad|výběr)\s+(USD|EUR)\s*([0-9.,\s\-]+)/i))) {
                const typeStr = match[1].toLowerCase();
                const isDeposit = typeStr.includes('top-up') || typeStr.includes('vklad');
                const currency = match[2].toUpperCase();
                const amount = this.parseNumber(match[3]) || 0;
                tx = {
                    date,
                    id: 'CASH_' + currency,
                    amount: 1,
                    price: amount,
                    amount_cur: amount,
                    currency: currency,
                    platform: 'Revolut',
                    product_type: 'Cash',
                    trans_type: isDeposit ? 'Deposit' : 'Withdrawal',
                    notes: `Cash`
                };
            }
            else if ((match = chunk.match(/\b([A-Z0-9.]{1,10})\s+(?:Dividend|Dividenda)\s+(USD|EUR)\s*([0-9.,\s]+)/i))) {
                const currency = match[2].toUpperCase();
                const value = this.parseNumber(match[3]) || 0;
                tx = {
                    date,
                    id: match[1],
                    amount: 1,
                    price: value,
                    amount_cur: value,
                    currency: currency,
                    platform: 'Revolut',
                    product_type: 'Stock',
                    trans_type: 'Dividend',
                    notes: 'Dividend'
                };
            }
            else if ((match = chunk.match(/(?:Custody fee|Poplatek za úschovu)\s+-?(USD|EUR)\s*([0-9.,\s]+)/i))) {
                const currency = match[1].toUpperCase();
                const value = this.parseNumber(match[2]) || 0;
                tx = {
                    date,
                    id: 'FEE_CUSTODY',
                    amount: 1,
                    price: value,
                    amount_cur: -value,
                    currency: currency,
                    platform: 'Revolut',
                    product_type: 'Fee',
                    trans_type: 'Fee',
                    fees: value,
                    notes: 'Custody fee'
                };
            }
            // Spinoff
            else if (/Spinoff|Transfer|Převod|Rozdělení|Fúze/i.test(chunk)) {
                const symbolMatch = chunk.match(/\b([A-Z0-9.]{1,10})\b/);
                tx = {
                    date,
                    id: symbolMatch ? symbolMatch[1] : 'CORP_ACTION',
                    amount: 1,
                    price: 0,
                    amount_cur: 0,
                    currency: 'USD',
                    platform: 'Revolut',
                    product_type: 'Stock',
                    trans_type: 'Other',
                    notes: 'Corporate action'
                };
            }

            if (tx) {
                if (tx.id && tickerMappings[tx.id]) {
                    tx.isin = tickerMappings[tx.id].isin;
                    tx.company_name = tickerMappings[tx.id].company_name;
                }
                transactions.push(tx);
            }
        }
        return transactions;
    }

    extractTickerMappings(text: string): Record<string, { isin: string, company_name: string }> {
        const mappings: Record<string, any> = {};
        const regex = /\b([A-Z0-9.\-]{1,10})[ \t]+(.+?)[ \t]+([A-Z]{2}[A-Z0-9]{9}[0-9])\b/g;
        let match;
        while ((match = regex.exec(text)) !== null) {
            const ticker = match[1].toUpperCase();
            if (['INDUSTRIES', 'INC', 'LTD', 'CORP', 'LIMITED', 'PLC'].includes(ticker)) continue;

            if (match[1] && match[3].length === 12) {
                mappings[match[1]] = { isin: match[3], company_name: match[2].trim() };
            }
        }
        return mappings;
    }

    parseDateStr(day: string, monthStr: string, year: string): string {
        // Handle numeric month (possible in CZ)
        if (/^\d+$/.test(monthStr)) {
            return `${year}-${monthStr.padStart(2, '0')}-${day.padStart(2, '0')}`;
        }
        const m = monthStr.replace('.', '').substring(0, 3).toLowerCase();
        let month = this.monthMap[monthStr] || this.monthMap[m] || '01';
        return `${year}-${month}-${day.padStart(2, '0')}`;
    }

    parseNumber(str: string | undefined): number {
        if (!str) return 0;
        let clean = str.replace(/\s/g, ''); // Remove spaces (CZ thousands)
        if (clean.includes(',') && !clean.includes('.')) {
            clean = clean.replace(',', '.');
        } else if (clean.includes(',') && clean.includes('.')) {
            if (clean.lastIndexOf(',') > clean.lastIndexOf('.')) {
                clean = clean.replace(/\./g, '').replace(',', '.');
            } else {
                clean = clean.replace(/,/g, '');
            }
        }
        return parseFloat(clean) || 0;
    }

    private parseTradingCsv(content: string): Transaction[] {
        const rows = this.parseCSV(content); // BaseParser helper
        if (rows.length < 2) return [];

        // Simple header map based on V4 logic
        const headers = rows[0].map(h => h.trim().toLowerCase());

        const getIdx = (candidates: string[]) => headers.findIndex(h => candidates.some(c => h.includes(c)));

        const map = {
            date: getIdx(['date', 'datum', 'time', 'started date']),
            ticker: getIdx(['ticker', 'symbol']),
            type: getIdx(['type', 'typ']),
            quantity: getIdx(['quantity', 'množství']),
            price: getIdx(['price per share', 'price', 'cena']),
            total: getIdx(['total amount', 'total', 'celkem', 'amount']),
            currency: getIdx(['currency', 'měna']),
            fxRate: getIdx(['fx rate', 'kurz'])
        };

        // If critical cols missing, try to parse anyway if type is present?
        // But without type we can't do much.
        if (map.type === -1) return [];

        const transactions: Transaction[] = [];

        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            if (row.length < 2) continue; // Skip empty rows

            const getVal = (idx: number) => (idx !== -1 && row[idx]) ? row[idx] : '';

            const rawType = getVal(map.type).toUpperCase();
            const ticker = getVal(map.ticker);

            // Logic port from V4
            let transType = 'Other';
            let productType = 'Stock';
            let txId = ticker;
            let notes = `Import CSV: ${rawType}`;

            let qty = this.parseNumber(getVal(map.quantity));
            let price = this.parseNumber(getVal(map.price));

            const totalStr = getVal(map.total);
            const totalData = this.extractCurrencyAndNumber(totalStr);
            let totalAmount = totalData.num ?? this.parseNumber(totalStr);
            let cur = getVal(map.currency).toUpperCase() || totalData.currency || 'USD';

            const fxRate = this.parseNumber(getVal(map.fxRate));

            // Date parsing
            let date = '';
            const dStr = getVal(map.date);
            if (dStr) {
                // Try ISO
                if (dStr.includes('T')) date = dStr.split('T')[0];
                else {
                    const d = new Date(dStr);
                    if (!isNaN(d.getTime())) date = d.toISOString().split('T')[0];
                }
            }

            // Type switching
            if (['BUY', 'BUY - MARKET', 'BUY - LIMIT'].some(t => rawType.includes(t))) {
                transType = 'Buy';
            } else if (['SELL', 'SELL - MARKET', 'SELL - LIMIT'].some(t => rawType.includes(t))) {
                transType = 'Sell';
            } else if (rawType.includes('DIVIDEND')) {
                transType = 'Dividend';
                qty = 1;
                price = totalAmount;
            } else if (rawType.includes('CUSTODY') || rawType.includes('FEE')) {
                transType = 'Fee';
                productType = 'Fee';
                txId = 'FEE_CUSTODY';
                qty = 1;
                price = Math.abs(totalAmount);
                totalAmount = -Math.abs(totalAmount);
            } else if (rawType.includes('CASH TOP-UP') || rawType.includes('DEPOSIT')) {
                transType = 'Deposit';
                productType = 'Cash';
                txId = 'CASH_' + cur;
                qty = 1;
                price = totalAmount;
            } else if (rawType.includes('WITHDRAWAL')) {
                transType = 'Withdrawal';
                productType = 'Cash';
                txId = 'CASH_' + cur;
                qty = 1;
                price = totalAmount;
            }

            if (transType === 'Other' && !ticker) continue; // Skip unknown garbage

            const tx: Transaction = {
                date,
                id: txId,
                amount: Math.abs(qty),
                price: Math.abs(price),
                amount_cur: totalAmount,
                currency: cur,
                platform: 'Revolut',
                product_type: productType,
                trans_type: transType,
                notes,
                fees: (transType === 'Fee') ? Math.abs(totalAmount) : 0
            };

            // @ts-ignore
            if (fxRate && fxRate !== 1) tx.ex_rate = fxRate;

            transactions.push(tx);
        }

        return transactions;
    }
}
