import BaseParser from '../BaseParser.js';
import { TickerMap } from '../../data/TickerMap.js';

export default class FioParser extends BaseParser {
    async parse(text) {
        console.log('FioParser input length:', text?.length);
        const lines = text.split('\n').map(line => line.trim()).filter(line => line.length > 0);
        const transactions = [];

        const pdfIsinMap = this.extractIsinMap(lines);
        console.log('FioParser Ticker Map loaded.');

        const dateStartRegex = /^(\d{1,2})\.(\d{1,2})\.(\d{4}|'\d{2})/;

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            const dateMatch = line.match(dateStartRegex);

            if (dateMatch) {
                let year = dateMatch[3];
                if (year.startsWith("'")) {
                    year = "20" + year.substring(1);
                }
                const date = `${year}-${dateMatch[2].padStart(2, '0')}-${dateMatch[1].padStart(2, '0')}`;

                const blockLines = [line];
                for (let j = i + 1; j < Math.min(lines.length, i + 15); j++) {
                    if (lines[j].match(dateStartRegex)) break;
                    blockLines.push(lines[j]);
                }

                const type = this.detectType(blockLines);

                if (type === 'Deposit') {
                    i += blockLines.length - 1;
                    continue;
                }

                if (type) {
                    const allNumbers = this.extractAllNumbers(blockLines);
                    const currency = this.extractCurrency(blockLines);
                    let ticker = this.extractTicker(blockLines);

                    if (ticker && TickerMap[ticker]) {
                        ticker = TickerMap[ticker];
                    }

                    if (!ticker || /^[A-Z]{2}[A-Z0-9]{9}\d$/.test(ticker)) {
                        const blockText = blockLines.join(' ');

                        for (const [name, mappedTicker] of Object.entries(TickerMap)) {
                            if (blockText.includes(name)) {
                                ticker = mappedTicker;
                                break;
                            }
                        }

                        if (!ticker) {
                            for (const [name, isin] of Object.entries(pdfIsinMap)) {
                                if (blockText.includes(name)) {
                                    if (TickerMap[isin]) {
                                        ticker = TickerMap[isin];
                                    } else {
                                        ticker = isin;
                                    }
                                    break;
                                }
                            }
                        }
                    }

                    if (ticker && TickerMap[ticker]) {
                        ticker = TickerMap[ticker];
                    }

                    if (type === 'Fee' && (!ticker || ticker === 'UNKNOWN' || !Object.values(TickerMap).includes(ticker))) {
                        console.log(`FioParser: Skipping Fee transaction without valid ticker: ${ticker}`);
                        i += blockLines.length - 1;
                        continue;
                    }

                    // Extract quantity, amount, and calculate price
                    let quantity = 0;
                    let amountCur = 0;
                    let price = 0;

                    // Extract numbers preserving order




                    // Filter meaningful specific numbers (filter out very small ones like dates if any leaked, though regex handles most)
                    // Fio order typically: Quantity, Price, ..., Total

                    if (type === 'Buy' || type === 'Sell' || type === 'Dividend') {
                        // Heuristic: Total is usually the largest number (in absolute value), unless penny stock
                        // But verifying A * B = C is safest

                        const absNumbers = allNumbers.map(n => Math.abs(n));
                        const maxVal = Math.max(...absNumbers);
                        const maxIndex = absNumbers.indexOf(maxVal);

                        amountCur = maxVal; // Assume largest is total amount

                        console.warn(`[FioDebug] ${date} ${ticker} (${type}): Numbers:`, absNumbers, 'Max:', maxVal);

                        // Try to find Q * P = Total
                        const usedIndices = new Set([maxIndex]);
                        let bestMatch = null;

                        // Loop through all pairs to find product match
                        for (let a = 0; a < absNumbers.length; a++) {
                            if (a === maxIndex) continue;
                            for (let b = a + 1; b < absNumbers.length; b++) {
                                if (b === maxIndex) continue;
                                const prod = absNumbers[a] * absNumbers[b];
                                const diff = Math.abs(prod - maxVal);

                                // Tolerance 5.0 unit (rounding differences)
                                if (diff < 5.0) {
                                    bestMatch = { qty: absNumbers[a], price: absNumbers[b], idxA: a, idxB: b };
                                    break;
                                }
                            }
                            if (bestMatch) break;
                        }

                        if (bestMatch) {
                            quantity = bestMatch.qty;
                            price = bestMatch.price;
                            usedIndices.add(bestMatch.idxA);
                            usedIndices.add(bestMatch.idxB);
                            console.log(`[FioDebug] BestMatch: Qty=${quantity}, Price=${price}`);
                        } else {
                            console.warn('[FioDebug] No product match. Using Fallback.');
                            // Fallback if multiplication check fails
                            if (type === 'Dividend') {
                                quantity = 1;
                                amountCur = maxVal;
                                price = maxVal;
                                usedIndices.add(maxIndex);
                            } else if (absNumbers.length >= 3) {
                                // Fallback: Trust column order (Qty, Price, Fee, Total)
                                // We filter out the Total (maxVal) and look at the preceding numbers
                                const candidates = absNumbers.map((val, idx) => ({ val, idx }))
                                    .filter(x => x.idx !== maxIndex)
                                    .sort((a, b) => a.idx - b.idx);

                                amountCur = maxVal;
                                usedIndices.add(maxIndex);

                                if (candidates.length >= 2) {
                                    // Fio typically has Fees, so we expect: Qty, Price, Fee, Total
                                    // We take the number at position -3 (Qty) and -2 (Price) from the end of candidates list, if possible.
                                    // If only 2 candidates: Qty, Price, Total -> Take -2 (Qty), -1 (Price)

                                    const idx1 = candidates.length >= 3 ? candidates.length - 3 : candidates.length - 2;
                                    const idx2 = candidates.length >= 3 ? candidates.length - 2 : candidates.length - 1;

                                    if (idx1 >= 0 && idx2 >= 0) {
                                        quantity = candidates[idx1].val;
                                        price = candidates[idx2].val;
                                        usedIndices.add(candidates[idx1].idx);
                                        usedIndices.add(candidates[idx2].idx);
                                    } else {
                                        // Fallback safety if indices weird
                                        quantity = candidates[0].val;
                                        usedIndices.add(candidates[1].idx);
                                    }
                                }
                            } else if (absNumbers.length === 2) {
                                quantity = absNumbers[0];
                                amountCur = absNumbers[1];
                                price = amountCur / quantity;
                                usedIndices.add(0);
                                usedIndices.add(1);
                            }
                        }


                        // Extract fees
                        let fees = 0;
                        for (let i = 0; i < absNumbers.length; i++) {
                            if (!usedIndices.has(i)) {
                                if (absNumbers[i] > fees) fees = absNumbers[i];
                            }
                        }
                        this.lastFees = fees;

                    } else {
                        const largest = Math.max(...allNumbers.map(Math.abs));
                        amountCur = largest;
                        quantity = 0;
                        this.lastFees = 0;
                    }

                    console.log(`FioParser: ${type} ${ticker}, qty=${quantity}, price=${price?.toFixed(2)}, amount=${amountCur}, fees=${this.lastFees}`);

                    transactions.push({
                        date: date,
                        id: ticker || 'UNKNOWN',
                        amount: quantity,
                        price: price,
                        amount_cur: amountCur,
                        currency: currency || 'CZK',
                        trans_type: type,
                        platform: 'fio',
                        product_type: this.getProductType(type),
                        notes: `Fio Import: ${type}`,
                        fees: this.lastFees || 0
                    });

                    // Reset fees
                    this.lastFees = 0;
                }
                i += blockLines.length - 1;
            }
        }

        console.log('FioParser found transactions:', transactions.length, transactions);
        return transactions;
    }

    extractCurrency(lines) {
        const text = lines.join(' ');
        if (text.includes('CZK')) return 'CZK';
        if (text.includes('EUR')) return 'EUR';
        if (text.includes('USD')) return 'USD';
        return null;
    }

    extractIsinMap(lines) {
        const map = {};
        const isinRegex = /([A-Z]{2}[A-Z0-9]{9}\d)/;

        for (const line of lines) {
            const match = line.match(isinRegex);
            if (match) {
                const isin = match[1];
                const parts = line.split(isin);
                if (parts.length > 0) {
                    const namePart = parts[0].trim();
                    if (namePart.length > 2) {
                        const cleanName = namePart.replace(/[\d\.,]+$/, '').trim();
                        if (cleanName) {
                            map[cleanName] = isin;
                        }
                    }
                }
            }
        }
        return map;
    }

    getProductType(type) {
        if (type === 'Deposit' || type === 'Withdrawal') return 'Cash';
        return 'Stock';
    }

    detectType(lines) {
        const text = lines.join(' ').toLowerCase();
        if (text.includes('nákup')) return 'Buy';
        if (text.includes('prodej')) return 'Sell';
        if (text.includes('výplata dividendy') || text.includes('dividenda') || text.includes('výplata')) return 'Dividend';
        if (text.includes('vklad') || text.includes('vloženo')) return 'Deposit';
        if (text.includes('výběr')) return 'Withdrawal';
        if (text.includes('poplatek')) return 'Fee';
        return null;
    }

    extractAllNumbers(lines) {
        const numRegex = /(-?[\d\s\u00A0]+,\d{2})/g;
        const candidates = [];

        for (const line of lines) {
            const matches = line.match(numRegex);
            if (matches) {
                matches.forEach(m => {
                    const clean = m.replace(/[\s\u00A0]/g, '').replace(',', '.');
                    const val = parseFloat(clean);
                    if (!isNaN(val) && val !== 0) candidates.push(val);
                });
            }
        }

        return candidates;
    }

    extractTicker(lines) {
        const ignore = new Set(['CZK', 'EUR', 'USD', 'GBP', 'BCPP', 'NÁKUP', 'PRODEJ', 'VKLAD', 'VÝBĚR', 'DIVIDENDA', 'POPLATKY', 'OBJEM', 'CENA', 'MNOŽSTVÍ', 'TRH', 'SMĚR', 'POZNÁMKA', 'DATUM', 'ČAS', 'AKCIE', 'VÝPIS', 'OPERACÍ', 'CELKEM', 'ZŮSTATEK', 'OBRAT', 'KREDIT', 'DEBET', 'POČÁTEČNÍ', 'KONEČNÝ', 'FIO', 'BANKA', 'A.S.', 'IN', 'ISIN', 'KS', 'KC', 'BALÍK', 'POKYN']);

        const text = lines.join(' ');

        const isinMatch = text.match(/\b([A-Z]{2}[A-Z0-9]{9}\d)\b/);
        if (isinMatch) {
            return isinMatch[1];
        }

        const upperText = text.toUpperCase();
        for (const [key, ticker] of Object.entries(TickerMap)) {
            if (!/^[A-Z]{2}[A-Z0-9]{9}\d$/.test(key)) {
                if (upperText.includes(key)) {
                    return ticker;
                }
            }
        }

        for (const line of lines) {
            const words = line.split(/\s+/);
            for (const w of words) {
                const cleanW = w.replace(/[^A-Z0-9]/g, '');

                if (!cleanW || ignore.has(cleanW)) continue;
                if (/^\d{6,8}$/.test(cleanW)) continue;
                if (cleanW === 'O2') return 'O2';

                if (cleanW.length >= 2 && cleanW.length <= 10) {
                    const letterCount = (cleanW.match(/[A-Z]/g) || []).length;
                    if (letterCount >= 2) {
                        return cleanW;
                    }
                }
            }
        }

        return null;
    }
}
