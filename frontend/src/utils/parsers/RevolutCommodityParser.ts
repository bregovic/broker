
import BaseParser, { type Transaction } from './BaseParser';

export default class RevolutCommodityParser extends BaseParser {
    async parse(content: any): Promise<Transaction[]> {
        if (typeof content === 'string') return this.parsePdf(content);
        // CSV parsing not yet fully integrated in ImportProcessor for raw array flow, 
        // usually content comes as string for PDF or csv-string for CSV.
        // BaseParser has parseCSV method which returns string[][].
        // If content is array, assume it's already parsed CSV rows.
        if (Array.isArray(content)) return this.parseCsvRows(content);

        // If it's a string but doesn't look like PDF (no bin chars), try CSV
        // But here we rely on ImportProcessor which passes PDF text or File content.
        // For now, assume string is PDF text unless we explicitly handle CSV string elsewhere.
        // Let's stick to the JS logic:
        return this.parsePdf(content);
    }

    parseCsv(csvText: string): Promise<Transaction[]> {
        const rows = this.parseCSV(csvText);
        return Promise.resolve(this.parseCsvRows(rows));
    }

    /* ===== PDF (CZ/EN) – XAU/XAG/XPT/XPD ===== */
    parsePdf(text: string): Transaction[] {
        const t = String(text)
            .replace(/\u00A0/g, ' ')
            .replace(/[ \t]+/g, ' ')
            .replace(/\s{2,}/g, ' ')
            .trim();

        const out: Transaction[] = [];
        // bloky oddělené datem (CZ i EN patterny)
        const blockRe =
            /((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{2}\s[A-Za-z]{3}\s\d{4}))([\s\S]*?)(?=((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{2}\s[A-Za-z]{3}\s\d{4}))|$)/g;

        const symToFiat = (s: string) => s === '$' ? 'USD' : (s === '€' ? 'EUR' : (s || '').toUpperCase());

        let m;
        while ((m = blockRe.exec(t)) !== null) {
            const rawDate = m[1];
            const dateIso = /\./.test(rawDate) ? this.csDateToISO(rawDate) : this.enDateToISO(rawDate);
            const block = m[2].trim();
            if (!dateIso || !block) continue;

            // BUY: „Směněno na XAU …“
            const buyM = block.match(/Směněno na\s+(XAU|XAG|XPT|XPD)\s+([0-9][0-9.,\s]*)/i);
            if (buyM) {
                const asset = buyM[1].toUpperCase();
                const qtyNet = this.parseNumber(buyM[2]) || 0;

                // Poplatek v KOMODITĚ (např. "Poplatek: 0.013517 XAU")
                const feeAssetMatch = block.match(/Poplatek:\s*([0-9][0-9.,\s]*)\s*(XAU|XAG|XPT|XPD)/i);
                const feeAsset = feeAssetMatch ? (this.parseNumber(feeAssetMatch[1]) || 0) : 0;
                const qtyGross = (qtyNet || 0) + (feeAsset || 0);

                // najdi částku v původní fiat měně (poslední výskyt €/$/kód měny)
                let amountCur: number | null = null;
                let currency: string | null = null;
                const sym = block.match(/(€|\$)\s*([0-9][0-9.,\s]*)\b(?!.*(€|\$)\s*[0-9])/);
                if (sym) {
                    amountCur = this.parseNumber(sym[2]);
                    currency = symToFiat(sym[1]);
                } else {
                    const code = block.match(/([0-9][0-9.,\s]*)\s*(EUR|USD|CZK|GBP|PLN|HUF|CHF|SEK|NOK|DKK)\b(?!.*\b(EUR|USD|CZK|GBP|PLN|HUF|CHF|SEK|NOK|DKK)\b)/i);
                    if (code) {
                        amountCur = this.parseNumber(code[1]);
                        currency = code[2].toUpperCase();
                    }
                }

                // Cena za 1 jednotku = totalFiat / (NET + feeAsset)
                const unitPrice = (qtyGross && amountCur != null) ? (amountCur / qtyGross) : null;
                // Poplatek ve fiatu – přepočet z množství komodity
                const feeFiat = unitPrice && feeAsset ? (unitPrice * feeAsset) : 0;

                out.push({
                    date: dateIso,
                    id: asset,
                    amount: qtyNet,                         // NET po fee
                    price: unitPrice ?? null,               // fiat / 1 jednotku
                    // @ts-ignore
                    ex_rate: null,
                    amount_cur: amountCur ?? 0,
                    currency: currency || 'EUR',
                    // @ts-ignore
                    amount_czk: (currency === 'CZK') ? (amountCur ?? 0) : null,
                    platform: 'Revolut',
                    product_type: 'Commodity',
                    trans_type: 'Buy',
                    fees: 0,
                    __tmp: { feeAsset, feeFiat: feeFiat || 0, feeFiatCurrency: currency || 'EUR' },
                    notes: 'Commodity exchange (buy)'
                });
                continue;
            }

            // SELL: „Směněno na CZK {qty} XAU … {CZK} CZK“
            const sellQtyM = block.match(/Směněno na\s+CZK\s+([0-9][0-9.,\s]*)\s*(XAU|XAG|XPT|XPD)/i);
            const czkValM = block.match(/([0-9][0-9.,\s]*)\s*CZK/i);
            if (sellQtyM && czkValM) {
                const qty = this.parseNumber(sellQtyM[1]) || 0;
                const asset = sellQtyM[2].toUpperCase();
                const czk = this.parseNumber(czkValM[1]) || 0;
                const unitPrice = qty ? (czk / qty) : null;

                out.push({
                    date: dateIso,
                    id: asset,
                    amount: qty,
                    price: unitPrice ?? null,   // CZK / 1 jednotku
                    // @ts-ignore
                    ex_rate: 1,
                    amount_cur: czk,
                    currency: 'CZK',
                    // @ts-ignore
                    amount_czk: czk,
                    platform: 'Revolut',
                    product_type: 'Commodity',
                    trans_type: 'Sell',
                    fees: 0,
                    __tmp: null,
                    notes: 'Commodity exchange (sell)'
                });
                continue;
            }
        }
        return out;
    }

    /* ===== CSV ===== */
    parseCsvRows(rows: string[][]): Transaction[] {
        const out: Transaction[] = [];
        if (!rows || rows.length < 2) return out;

        const headers = rows[0].map(h => (h || '').toString().trim().toLowerCase());
        const findIdx = (...alts: string[]) => {
            for (const a of alts) {
                const i = headers.findIndex(h => h === a || new RegExp(`\\b${a}\\b`, 'i').test(h));
                if (i !== -1) return i;
            }
            return -1;
        };

        const idx = {
            date: findIdx('date', 'datum'),
            asset: findIdx('commodity', 'asset', 'symbol', 'ticker'),
            type: findIdx('type', 'typ', 'transaction type'),
            qty: findIdx('quantity', 'qty', 'množství', 'mnozstvi', 'units'),
            total: findIdx('value', 'total', 'amount', 'celkem', 'amount (fiat)'),
            currency: findIdx('currency', 'měna', 'mena'),
            fee: findIdx('fee', 'poplatky', 'poplatek'),
            feeCur: findIdx('fee currency', 'měna poplatku', 'mena poplatku'),
            ppu: findIdx('price per unit', 'price', 'cena za jednotku', 'cena')
        };

        for (let i = 1; i < rows.length; i++) {
            const r = rows[i]; if (!r || !r.length) continue;

            const typeRaw = (r[idx.type] || '').toString();
            const asset = (r[idx.asset] || '').toString().trim().toUpperCase() || 'COMMODITY';
            const qty = this.parseNumber(r[idx.qty]) ?? 0;

            // total + currency (zkus i "1000 EUR" v jednom poli)
            const fromTotal = this.extractCurrencyAndNumber(r[idx.total]);
            let totalNum = (fromTotal && fromTotal.num != null) ? fromTotal.num : this.parseNumber(r[idx.total]);
            let currency = (r[idx.currency] || (fromTotal && fromTotal.currency) || 'EUR').toString().trim().toUpperCase();
            if (currency === '€') currency = 'EUR';
            if (currency === '$') currency = 'USD';

            // fee – může být ve FIAT nebo v ASSETu
            let feeFiat = this.parseNumber(r[idx.fee]);
            let feeFiatCurrency = currency;
            let feeAsset = 0;
            if ((feeFiat == null || Number.isNaN(feeFiat)) && r[idx.fee]) {
                const ex = this.extractCurrencyAndNumber(r[idx.fee]);
                if (ex && ex.num != null) {
                    // pokud feeCur chybí a extrahovaná "měna" vypadá jako asset (XAU/XAG/…), ber to jako asset
                    const cur = (ex.currency || '').toUpperCase();
                    if (/^XA[UGPD]$/.test(cur) || cur === asset) {
                        feeAsset = ex.num;
                        feeFiat = null;
                    } else {
                        feeFiat = ex.num;
                        feeFiatCurrency = (cur === '€' ? 'EUR' : cur === '$' ? 'USD' : (cur || currency));
                    }
                }
            }
            if (idx.feeCur !== -1 && r[idx.feeCur]) {
                const fc = (r[idx.feeCur] || '').toString().trim().toUpperCase();
                if (/^XA[UGPD]$/.test(fc) || fc === asset) {
                    // fee je v jednotkách komodity
                    feeAsset = feeAsset || (feeFiat ?? 0);
                    feeFiat = null;
                } else if (fc) {
                    feeFiatCurrency = fc === '€' ? 'EUR' : (fc === '$' ? 'USD' : fc);
                }
            }

            // spočítej unit price, preferuj hrubé množství (net + feeAsset), jinak net
            const qtyGross = (qty || 0) + (feeAsset || 0);
            let unit: number | null = null;
            if (qtyGross && totalNum != null) unit = totalNum / qtyGross;
            else if (qty && totalNum != null) unit = totalNum / qty;
            if (unit == null && r[idx.ppu]) unit = this.parseNumber(r[idx.ppu]); // fallback

            // dopočítej fiat poplatek z feeAsset, pokud nebyl dodán ve fiatu
            if ((feeFiat == null || Number.isNaN(feeFiat)) && feeAsset && unit != null) {
                feeFiat = unit * feeAsset;
                feeFiatCurrency = currency;
            }

            // ----- výstup -----
            if (/buy|nákup|nakup/i.test(typeRaw)) {
                out.push({
                    date: this.safeDate(r[idx.date]),
                    id: asset,
                    amount: Math.abs(qty),                   // NET po fee
                    price: unit ?? null,
                    // @ts-ignore
                    ex_rate: null,
                    amount_cur: totalNum ?? 0,
                    currency,
                    // @ts-ignore
                    amount_czk: currency === 'CZK' ? (totalNum ?? 0) : null,
                    platform: 'Revolut',
                    product_type: 'Commodity',
                    trans_type: 'Buy',
                    fees: 0,
                    __tmp: (feeFiat != null && !Number.isNaN(feeFiat))
                        ? { feeAsset: feeAsset || 0, feeFiat, feeFiatCurrency }
                        : (feeAsset ? { feeAsset, feeFiat: unit != null && unit ? unit * feeAsset : 0, feeFiatCurrency: currency } : null),
                    notes: `CSV: ${typeRaw}`
                });
            } else if (/sell|prodej/i.test(typeRaw)) {
                // Prodej: pokud je currency CZK a známe qty → spočti CZK/1 jednotku
                const unitSell = (currency === 'CZK' && qty) ? ((totalNum ?? 0) / qty) : (unit ?? null);
                out.push({
                    date: this.safeDate(r[idx.date]),
                    id: asset,
                    amount: Math.abs(qty),
                    price: unitSell ?? null,
                    // @ts-ignore
                    ex_rate: currency === 'CZK' ? 1 : null,
                    amount_cur: totalNum ?? 0,
                    currency,
                    // @ts-ignore
                    amount_czk: currency === 'CZK' ? (totalNum ?? 0) : null,
                    platform: 'Revolut',
                    product_type: 'Commodity',
                    trans_type: 'Sell',
                    fees: 0,
                    __tmp: (feeFiat != null && !Number.isNaN(feeFiat)) ? { feeFiat, feeFiatCurrency } : null,
                    notes: `CSV: ${typeRaw}`
                });
            }
        }
        return out;
    }

    safeDate(v: any): string {
        const s = (v || '').toString();
        if (/\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
        if (/\d{1,2}\.\s*\d{1,2}\.\s*\d{4}/.test(s)) return this.csDateToISO(s);
        if (/\d{2}\s[A-Za-z]{3}\s\d{4}/.test(s)) return this.enDateToISO(s);
        try { return new Date(s).toISOString().slice(0, 10); } catch { return ''; }
    }
}
