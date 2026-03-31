
import BaseParser, { type Transaction } from './BaseParser';

export default class IbkrParser extends BaseParser {
    async parse(content: any): Promise<Transaction[]> {
        if (!content) return [];
        if (Array.isArray(content)) return this.parseCsv(content);
        if (typeof content === 'string') return this.parsePdf(content);
        throw new Error('IBKR: Neplatný vstup pro parser.');
    }

    /* ======================= PDF PARSER ======================= */
    parsePdf(text: string): Transaction[] {
        if (!text) return [];

        let cleanText = text.replace(/\u00A0/g, ' ').replace(/\r/g, '\n').trim();
        let lines = cleanText.split(/\n/).map(l => l.trim()).filter(Boolean);

        lines = this.fixBrokenDates(lines);
        lines = this.mergeLinesByDate(lines);

        const transactions: Transaction[] = [];
        const processedKeys = new Set<string>();

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            const dateMatch = line.match(/^(20\d{2}-\d{2}-\d{2})/);
            if (!dateMatch) continue;
            const date = dateMatch[1];

            /* ---------- 1) BUY ---------- */
            if (line.includes(' Buy ')) {
                const parts = line.split(/\s+/);
                const buyIndex = parts.findIndex(p => p === 'Buy');

                if (buyIndex > 0 && buyIndex < parts.length - 4) {
                    const symbol = parts[buyIndex + 1];
                    const quantity = parseFloat(parts[buyIndex + 2]) || 0;
                    const price = parseFloat(parts[buyIndex + 3]) || 0;
                    // const origCurrency = parts[buyIndex + 4] || 'USD';

                    let czkAmount = this.extractLastAmountFromParts(parts);
                    if (symbol && czkAmount !== null) {
                        const txKey = `${date}|${symbol}|Buy|${Math.abs(czkAmount).toFixed(2)}|${Math.abs(quantity).toFixed(4)}`;
                        if (!processedKeys.has(txKey)) {
                            processedKeys.add(txKey);
                            transactions.push({
                                date,
                                id: symbol,
                                amount: Math.abs(quantity),
                                price,
                                amount_cur: czkAmount, // Usually negative for Buy
                                currency: 'CZK', // Assuming CZK account or conversion
                                platform: 'IBKR',
                                product_type: 'Stock',
                                trans_type: 'Buy',
                                fees: 0,
                                notes: `IBKR Buy ${Math.abs(quantity)}x ${symbol}`
                            });
                        }
                    }
                }
                continue;
            }

            /* ---------- 2) SELL ---------- */
            if (line.includes(' Sell ')) {
                const parts = line.split(/\s+/);
                const sellIndex = parts.findIndex(p => p === 'Sell');

                if (sellIndex > 0 && sellIndex < parts.length - 4) {
                    const symbol = parts[sellIndex + 1];
                    const quantity = parts[sellIndex + 2] ? Math.abs(parseFloat(parts[sellIndex + 2])) : 0;
                    const price = parseFloat(parts[sellIndex + 3]) || 0;

                    let czkAmount = this.extractLastAmountFromParts(parts);
                    if (czkAmount !== null) {
                        czkAmount = Math.abs(czkAmount);
                        const txKey = `${date}|${symbol}|Sell|${czkAmount.toFixed(2)}|${Math.abs(quantity).toFixed(4)}`;
                        if (!processedKeys.has(txKey)) {
                            processedKeys.add(txKey);
                            transactions.push({
                                date, id: symbol, amount: Math.abs(quantity), price,
                                amount_cur: czkAmount, currency: 'CZK',
                                platform: 'IBKR', product_type: 'Stock', trans_type: 'Sell',
                                fees: 0, notes: `IBKR Sell ${Math.abs(quantity)}x ${symbol}`
                            });
                        }
                    }
                }
                continue;
            }

            /* ---------- 3) OTHER ---------- */
            const transType = this.detectTransactionType(line);
            if (!transType) continue;

            const symbol = this.extractSymbol(line, transType);
            const netAmount = this.extractLastAmount(line);
            if (netAmount === null) continue;

            const txKey = `${date}|${symbol || ''}|${transType}|${Math.abs(netAmount).toFixed(2)}`;
            if (processedKeys.has(txKey)) continue;
            processedKeys.add(txKey);

            let finalAmount = netAmount;
            if (transType === 'Tax' || transType === 'Fee') finalAmount = -Math.abs(netAmount);
            else if (['Dividend', 'Deposit', 'Corporate Action'].includes(transType)) finalAmount = Math.abs(netAmount);

            transactions.push({
                date,
                id: symbol || (transType === 'Deposit' ? 'CASH_CZK' : 'FX_PNL'),
                amount: 1,
                price: transType === 'Dividend' ? Math.abs(netAmount) : 0,
                amount_cur: finalAmount,
                currency: 'CZK',
                platform: 'IBKR',
                product_type: this.getProductType(transType),
                trans_type: transType,
                fees: (transType === 'Fee') ? Math.abs(netAmount) : 0,
                notes: `IBKR ${transType} ${symbol || ''}`.trim()
            });
        }

        return transactions;
    }

    parseCsv(_rows: any): Transaction[] {
        console.warn('CSV parsing pro IBKR zatím není implementován.');
        return [];
    }

    /* ======================= HELPERS ======================= */
    mergeLinesByDate(lines: string[]): string[] {
        const merged: string[] = [];
        let current = '';
        for (const l of lines) {
            if (/^20\d{2}-\d{2}-\d{2}\b/.test(l)) {
                if (current) merged.push(current.trim());
                current = l;
            } else if (current) current += ' ' + l;
        }
        if (current) merged.push(current.trim());
        return merged;
    }

    fixBrokenDates(lines: string[]): string[] {
        const fixedLines: string[] = [];
        let i = 0;
        while (i < lines.length) {
            const line = lines[i];
            const incompleteMatch = line.match(/^(20\d{2}-\d{2})-?$/);
            if (incompleteMatch && i + 1 < lines.length) {
                const nextLine = lines[i + 1];
                if (/^\d{2}$/.test(nextLine)) {
                    if (i + 2 < lines.length) {
                        const thirdLine = lines[i + 2];
                        fixedLines.push(`${incompleteMatch[1]}-${nextLine} ${thirdLine}`);
                        i += 3; continue;
                    }
                } else if (/^\d{2}\s/.test(nextLine)) {
                    const day = nextLine.substring(0, 2);
                    const rest = nextLine.substring(2).trim();
                    fixedLines.push(`${incompleteMatch[1]}-${day} ${rest}`);
                    i += 2; continue;
                }
            }
            fixedLines.push(line);
            i++;
        }
        return fixedLines;
    }

    detectTransactionType(text: string): string | null {
        if (/Merged.*Acquisition/i.test(text) || /Corporate Action/i.test(text)) return 'Corporate Action';
        if (/FX Translation|P&L Adjustment/i.test(text)) return 'FX';
        if (/Other Fee|FEE$/i.test(text)) return 'Fee';
        if (/Cash Transfer.*(?:Deposit|Transfer to)/i.test(text)) return 'Deposit';
        if (/(?:Foreign Tax|US Tax|JP Tax|Withholding)/i.test(text) && !/(Dividend.*per Share\s*\(Ordinary)/i.test(text)) return 'Tax';
        if (/Cash Dividend.*per Share(?!.*Tax)/i.test(text) || /Stock Dividend.*Ordinary(?!.*Tax)/i.test(text)) return 'Dividend';
        return null;
    }

    extractSymbol(text: string, transType: string): string | null {
        if (transType === 'Deposit') return 'CASH_CZK';
        if (transType === 'FX') return 'FX_PNL';
        const isinMatch = text.match(/\b([A-Z][A-Z0-9.\-]{0,9})\s*\([A-Z]{2}[A-Z0-9]{8,10}\)/);
        if (isinMatch) return isinMatch[1];
        const tickerMatch = text.match(/\b([A-Z]{2,5})\b(?=.*(?:Dividend|Tax|Fee))/);
        if (tickerMatch) {
            const ticker = tickerMatch[1];
            if (!['USD', 'EUR', 'CZK', 'US', 'JP', 'TAX', 'FEE', 'FOR'].includes(ticker)) return ticker;
        }
        return null;
    }

    extractLastAmount(text: string): number | null {
        const amounts: number[] = [];
        const regex = /[-\d,]+\.\d{2}(?=\s|$)/g;
        let match;
        while ((match = regex.exec(text)) !== null) {
            const cleanAmount = match[0].replace(/,/g, '');
            const num = parseFloat(cleanAmount);
            if (!isNaN(num) && Math.abs(num) < 10000000) amounts.push(num);
        }
        return amounts.length > 0 ? amounts[amounts.length - 1] : null;
    }

    extractLastAmountFromParts(parts: string[]): number | null {
        for (let j = parts.length - 1; j >= 0; j--) {
            const cleaned = parts[j].replace(/,/g, '');
            if (/^-?\d+\.\d{2}$/.test(cleaned)) {
                const num = parseFloat(cleaned);
                if (!isNaN(num)) return num;
            }
        }
        return null;
    }

    getProductType(transType: string): string {
        const mapping: Record<string, string> = {
            Dividend: 'Stock', Tax: 'Tax', Fee: 'Fee', Deposit: 'Cash',
            Withdrawal: 'Cash', FX: 'FX', 'Corporate Action': 'Stock', Buy: 'Stock', Sell: 'Stock'
        };
        return mapping[transType] || 'Stock';
    }
}
