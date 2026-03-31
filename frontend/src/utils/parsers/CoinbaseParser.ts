
import BaseParser, { type Transaction } from './BaseParser';

export default class CoinbaseParser extends BaseParser {
    async parse(content: any): Promise<Transaction[]> {
        if (Array.isArray(content)) return this.parseCsv(content);
        if (typeof content === 'string') {
            if (content.includes('Timestamp') && content.includes('Transaction Type') && !content.includes('Transaction History Report') && !content.includes('<html')) {
                return this.parseCsv(this.parseCSV(content));
            }
            if (content.includes('Transaction History Report') && !content.includes('<html>')) {
                return this.parsePdf(content);
            }
            return this.parseHtml(content);
        }
        throw new Error('Neplatný formát obsahu pro Coinbase');
    }

    /* ===================== PDF ===================== */
    parsePdf(text: string): Transaction[] {
        const out: Transaction[] = [];
        if (!text) return out;

        const N = (s: any) => this.parseNumber(s) || 0;
        const normCur = (s: any) => {
            const u = (s || '').toString().trim().toUpperCase();
            return u === 'KČ' ? 'CZK' : u;
        };

        const rawLines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);

        for (let i = 0; i < rawLines.length; i++) {
            const line = rawLines[i];
            if (!/^\d{4}-\d{2}-\d{2}/.test(line)) continue;

            const parts = this.parsePdfLine(line);
            if (!parts) continue;

            const { timestamp, transactionType, asset, quantity, priceCurrency, total, notes } = parts;
            const date = timestamp.split(' ')[0]; // YYYY-MM-DD matches start

            if (!date) continue;

            const type = (transactionType || '').toLowerCase();
            const assetUp = (asset || '').toUpperCase();
            const qty = N(quantity);
            const tot = N(total);
            const pCur = normCur(priceCurrency);

            if (type === 'sell' && assetUp && qty < 0) {
                const totalAmount = Math.abs(tot);
                const assetQuantity = Math.abs(qty);
                if (totalAmount > 0 && assetQuantity > 0) {
                    out.push({
                        date, id: assetUp, amount: assetQuantity, price: totalAmount / assetQuantity,
                        amount_cur: totalAmount, currency: pCur,
                        platform: 'Coinbase', product_type: 'Crypto', trans_type: 'Sell', fees: 0,
                        notes: `Coinbase PDF: Sell ${notes || ''}`
                    });
                }
            } else if ((type === 'buy' || qty > 0) && assetUp && !['EUR', 'USD', 'CZK', 'GBP', 'PLN', 'HUF'].includes(assetUp)) {
                const totalAmount = Math.abs(tot);
                const assetQuantity = Math.abs(qty);
                if (totalAmount > 0 && assetQuantity > 0) {
                    out.push({
                        date, id: assetUp, amount: assetQuantity, price: totalAmount / assetQuantity,
                        amount_cur: totalAmount, currency: pCur,
                        platform: 'Coinbase', product_type: 'Crypto', trans_type: 'Buy', fees: 0,
                        notes: `Coinbase PDF: Buy ${notes || ''}`
                    });
                }
            } else if (['deposit', 'withdrawal'].includes(type)) {
                const isDeposit = type === 'deposit';
                const totalAmount = Math.abs(tot);
                const isFiat = ['EUR', 'USD', 'CZK', 'GBP', 'PLN', 'HUF'].includes(assetUp);

                if (isFiat) {
                    if (totalAmount > 0) {
                        out.push({
                            date, id: `CASH_${assetUp}`, amount: 1, price: totalAmount,
                            amount_cur: totalAmount, currency: pCur || assetUp,
                            platform: 'Coinbase', product_type: 'Cash', trans_type: isDeposit ? 'Deposit' : 'Withdrawal', fees: 0,
                            notes: `Coinbase PDF: ${transactionType} ${notes || ''}`
                        });
                    }
                } else {
                    // Crypto transfer
                    out.push({
                        date, id: assetUp, amount: Math.abs(qty), price: 0,
                        amount_cur: totalAmount, currency: pCur || 'USD',
                        platform: 'Coinbase', product_type: 'Crypto',
                        trans_type: isDeposit ? 'Deposit' : 'Withdrawal', fees: 0,
                        notes: `Coinbase PDF: ${transactionType} ${notes || ''}`
                    });
                }
            } else if (line.includes('Exchange Deposit')) {
                // Skip (internal transfer)
            } else if (line.includes('Pro Withdrawal') && assetUp && qty > 0) {
                const totalAmount = Math.abs(tot);
                const assetQuantity = Math.abs(qty);
                out.push({
                    date, id: assetUp, amount: assetQuantity, price: 0,
                    amount_cur: totalAmount, currency: pCur,
                    platform: 'Coinbase', product_type: 'Crypto', trans_type: 'Withdrawal', fees: 0,
                    notes: `Coinbase PDF: Pro Withdrawal ${notes || ''}`
                });
            }
        }
        return out;
    }

    parsePdfLine(line: string): any {
        const parts = line.split(/\s+/);
        if (parts.length < 8) return null;
        const timestamp = `${parts[0]} ${parts[1]} ${parts[2]}`;
        let transactionType = parts[3];
        let asset, quantity, priceCurrency, subtotal, total, notes;



        if (transactionType === 'Sell') {
            asset = parts[4]; quantity = parts[5]; priceCurrency = parts[6];
            const kcAmounts = line.match(/Kč([\d.,]+)/g) || [];
            if (kcAmounts.length >= 2) {
                subtotal = kcAmounts[kcAmounts.length - 2];
                total = kcAmounts[kcAmounts.length - 1];
            }
            const lastKcIndex = line.lastIndexOf('Kč');
            if (lastKcIndex !== -1) {
                const after = line.substring(lastKcIndex).match(/Kč[\d.,]+\s+(.+)/);
                notes = after ? after[1] : '';
            }
        } else if (['Deposit', 'Withdrawal'].includes(transactionType)) {
            asset = parts[4]; quantity = parts[5]; priceCurrency = parts[6];
            const kcAmounts = line.match(/Kč([\d.,]+)/g) || [];
            if (kcAmounts.length >= 1) total = kcAmounts[kcAmounts.length - 1];
            const lastKcIndex = line.lastIndexOf('Kč');
            if (lastKcIndex !== -1) {
                const after = line.substring(lastKcIndex).match(/Kč[\d.,]+\s+(.+)/);
                notes = after ? after[1] : '';
            }
        } else if (line.includes('Pro Withdrawal')) {
            const match = line.match(/Pro Withdrawal\s+([A-Z]+)\s+([\d.,]+)\s+([A-Z]+)/);
            if (match) {
                transactionType = 'Pro Withdrawal'; asset = match[1]; quantity = match[2]; priceCurrency = match[3];
                const kcAmounts = line.match(/Kč([\d.,]+)/g) || [];
                if (kcAmounts.length >= 1) total = kcAmounts[kcAmounts.length - 1];
            } else return null;
        } else return null;

        return { timestamp, transactionType, asset, quantity, priceCurrency, subtotal, total, notes: (notes || '').trim() };
    }

    /* ===================== HTML ===================== */
    parseHtml(html: string): Transaction[] {
        const out: Transaction[] = [];
        const t = String(html);
        const normCur = (s: any) => { const u = (s || '').toString().trim().toUpperCase(); return u === 'KČ' ? 'CZK' : u; };
        const rowRe = /<tr[^>]*>([\s\S]*?)<\/tr>/gi;
        let rm;
        while ((rm = rowRe.exec(t)) !== null) {
            const cells: string[] = [];
            const tdRe = /<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/gi;
            let cm; while ((cm = tdRe.exec(rm[1])) !== null) cells.push(String(cm[1]).replace(/<[^>]*>/g, '').replace(/\u00A0/g, ' ').trim());
            if (cells.length < 6) continue;

            const [ts, typeRaw, assetRaw, qtyRaw, priceCurRaw, spotTxt, _subRaw, totalRaw, notesRaw] = cells;
            const asset = (assetRaw || '').toUpperCase().trim();
            const qty = Math.abs(this.parseNumber(qtyRaw) ?? 0);
            let priceCur = normCur(priceCurRaw);
            let total = this.parseNumber(totalRaw);
            const notes = notesRaw || '';


            // Date logic
            const m = String(ts).match(/(\d{4})-(\d{2})-(\d{2})/);
            const date = m ? `${m[1]}-${m[2]}-${m[3]}` : '';

            const isReward = /(reward|staking|learn|interest)/i.test(typeRaw);
            const isTrade = /(buy|sell)/i.test(typeRaw);
            const isDeposit = /deposit/i.test(typeRaw);
            const isWithdrawal = /withdrawal/i.test(typeRaw);

            if (!date) continue;
            if (!isReward && !isTrade && !isDeposit && !isWithdrawal) continue;

            const isFiat = /^(CZK|EUR|USD|GBP|CHF|PLN|HUF|JPY|CNY|AUD|CAD|NOK|SEK)$/i.test(asset);
            if (isFiat && !isDeposit && !isWithdrawal) continue;


            if (isReward) {
                if (!qty) continue;
                out.push({
                    date, id: asset, amount: qty, price: 0, amount_cur: 0, currency: 'CZK',
                    platform: 'Coinbase', product_type: 'Crypto', trans_type: 'Revenue', fees: 0,
                    notes: `Coinbase HTML: ${typeRaw} ${notes}`
                });
                continue;
            }
            if ((total == null || Number.isNaN(total)) && notes) {
                const m = notes.match(/for\s+([0-9\s.,]+)\s*([A-ZČ]{2,3})/i);
                if (m) { const n = this.parseNumber(m[1]); const c = normCur(m[2]); if (n != null) total = n; if (!priceCur && c) priceCur = c; }
            }

            if (isDeposit || isWithdrawal) {
                const totalAmount = Math.abs(total ?? 0);
                if (isFiat) {
                    out.push({
                        date, id: `CASH_${asset}`, amount: 1, price: totalAmount,
                        amount_cur: totalAmount, currency: priceCur || asset,
                        platform: 'Coinbase', product_type: 'Cash',
                        trans_type: isDeposit ? 'Deposit' : 'Withdrawal', fees: 0,
                        notes: `Coinbase HTML: ${typeRaw} ${notes}`
                    });
                } else {
                    out.push({
                        date, id: asset, amount: qty, price: 0,
                        amount_cur: totalAmount, currency: priceCur || 'USD',
                        platform: 'Coinbase', product_type: 'Crypto',
                        trans_type: isDeposit ? 'Deposit' : 'Withdrawal', fees: 0,
                        notes: `Coinbase HTML: ${typeRaw} ${notes}`
                    });
                }
                continue;
            }

            if (!priceCur || total == null || !qty) continue;

            const unit = Math.abs(total) / Math.abs(qty);

            out.push({
                date, id: asset, amount: qty, price: Number.isFinite(unit) ? unit : (this.parseNumber(spotTxt) ?? 0),
                amount_cur: Math.abs(total), currency: priceCur,
                platform: 'Coinbase', product_type: 'Crypto', trans_type: /sell/i.test(typeRaw) ? 'Sell' : 'Buy', fees: 0,
                notes: `Coinbase HTML: ${typeRaw} ${notes}`
            });
        }
        return out;
    }

    /* ===================== CSV ===================== */
    parseCsv(rows: string[][]): Transaction[] {
        const out: Transaction[] = [];
        if (!Array.isArray(rows) || rows.length < 2) return out;

        let headers: string[] | null = null;
        let headerIndex = -1;
        for (let i = 0; i < Math.min(rows.length, 10); i++) {
            const r = (rows[i] || []).map(h => (h || '').toString().trim().toLowerCase());
            if (r.includes('timestamp') && r.includes('transaction type')) { headers = r; headerIndex = i; break; }
        }
        if (!headers) return out;

        const find = (...alts: string[]) => {
            for (const a of alts) { const idx = headers!.findIndex(h => h === a || h.includes(a)); if (idx !== -1) return idx; }
            return -1;
        };
        const iTs = find('timestamp'); const iType = find('transaction type'); const iAsset = find('asset');
        const iQty = find('quantity transacted', 'quantity'); const iPCur = find('spot price currency', 'price currency');
        const iPAt = find('spot price at transaction', 'price at transaction');
        const iTotal = find('total (inclusive of fees', 'total'); const iNotes = find('notes');

        const normCur = (s: any) => (String(s || '').trim().toUpperCase() === 'KČ' ? 'CZK' : String(s || '').trim().toUpperCase());

        for (let r = headerIndex + 1; r < rows.length; r++) {
            const row = rows[r] || [];
            if (!row || !row.length) continue;

            const ts = (row[iTs] || '').toString().trim();
            const type = (row[iType] || '').toString().trim();
            const asset = (row[iAsset] || '').toString().trim().toUpperCase();
            const qty = Math.abs(this.parseNumber(row[iQty]) ?? 0);
            let pcur = normCur(row[iPCur]);
            const pAt = this.parseNumber(row[iPAt]);
            const total = this.parseNumber(row[iTotal]);
            const notes = (row[iNotes] || '').toString().trim();

            const m = String(ts).match(/(\d{4})-(\d{2})-(\d{2})/);
            const date = m ? `${m[1]}-${m[2]}-${m[3]}` : '';

            const isReward = /(reward|staking|learn|interest)/i.test(type);
            const isTrade = /(buy|sell)/i.test(type);
            const isDeposit = /deposit/i.test(type);
            const isWithdrawal = /withdrawal/i.test(type);

            if (!date || !asset) continue;
            if (!isReward && !isTrade && !isDeposit && !isWithdrawal) continue;

            // Skip non-supported fiat assets for Trade/Reward logic, but keep for Cash ops
            const isFiat = /^(CZK|KČ|EUR|USD|GBP|CHF|PLN|HUF|JPY|CNY|AUD|CAD|NOK|SEK)$/i.test(asset);
            if (isFiat && !isDeposit && !isWithdrawal) continue;

            if (isReward) {
                if (!qty) continue;
                out.push({
                    date, id: asset, amount: qty, price: 0, amount_cur: 0, currency: 'CZK',
                    platform: 'Coinbase', product_type: 'Crypto', trans_type: 'Revenue', fees: 0,
                    notes: `Coinbase CSV: ${type} ${notes}`
                });
                continue;
            }

            if (!pcur && row[iTotal]) {
                const ex = this.extractCurrencyAndNumber(row[iTotal]);
                if (ex && ex.currency) pcur = normCur(ex.currency);
            }
            if (!pcur && notes) { const m = notes.match(/\b([A-ZČ]{2,3})\b/); if (m) pcur = normCur(m[1]); }

            if (isDeposit || isWithdrawal) {
                const totalAmount = Math.abs(total ?? 0);
                if (isFiat) {
                    // Cash transaction
                    out.push({
                        date, id: `CASH_${asset}`, amount: 1, price: totalAmount,
                        amount_cur: totalAmount, currency: pcur || asset, // Use asset as currency for cash
                        platform: 'Coinbase', product_type: 'Cash',
                        trans_type: isDeposit ? 'Deposit' : 'Withdrawal', fees: 0,
                        notes: `Coinbase CSV: ${type} ${notes}`
                    });
                } else {
                    // Crypto transfer
                    out.push({
                        date, id: asset, amount: qty, price: 0,
                        amount_cur: totalAmount, currency: pcur || 'USD',
                        platform: 'Coinbase', product_type: 'Crypto',
                        trans_type: isDeposit ? 'Deposit' : 'Withdrawal', fees: 0,
                        notes: `Coinbase CSV: ${type} ${notes}`
                    });
                }
                continue;
            }

            if (!pcur || total == null || !qty) continue;
            const unit = Math.abs(total) / Math.abs(qty);

            out.push({
                date, id: asset, amount: qty, price: Number.isFinite(unit) ? unit : (pAt ?? 0),
                amount_cur: Math.abs(total), currency: pcur,
                platform: 'Coinbase', product_type: 'Crypto', trans_type: /sell/i.test(type) ? 'Sell' : 'Buy', fees: 0,
                notes: `Coinbase CSV: ${type} ${notes}`
            });
        }
        return out;
    }
}
