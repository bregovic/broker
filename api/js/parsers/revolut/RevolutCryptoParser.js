// /broker/js/parsers/revolut/RevolutCryptoParser.js
import BaseParser from '../BaseParser.js';

export default class RevolutCryptoParser extends BaseParser {
  async parse(content) {
    if (typeof content === 'string') return this.parsePdf(content);
    if (Array.isArray(content))      return this.parseCsv(content);
    throw new Error('Neplatný formát obsahu pro Revolut Crypto');
  }

  /* ===== PDF (CZ/EN) ===== */
  parsePdf(text) {
    const t = String(text)
      .replace(/\u00A0/g, ' ')
      .replace(/[ \t]+/g, ' ')
      .replace(/\s{2,}/g, ' ')
      .trim();

    const out = [];
    const symToFiat = (s) => (s === '$' ? 'USD' : (s === '€' ? 'EUR' : (s || '').toUpperCase()));

    // bloky oddělené datem (CZ i EN: "17. 2. 2021" nebo "17 Feb 2021")
    const blockRe =
      /((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{1,2}\s[A-Za-z]{3}\s\d{4}))([\s\S]*?)(?=((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{1,2}\s[A-Za-z]{3}\s\d{4}))|$)/g;

    let m;
    while ((m = blockRe.exec(t)) !== null) {
      const rawDate = m[1];
      const dateIso = /\./.test(rawDate) ? this.csDateToISO(rawDate) : this.enDateToISO(rawDate);
      const block   = (m[2] || '').trim();
      if (!dateIso || !block) continue;

      // --- Reward / staking ---
      // Primárně: "ADA Odměna za staking 0,263607 ..."
      let rw = block.match(
        /([A-Z]{2,10})\s+(?:Odměna(?:\s+za\s+staking)?|Staking(?:\s+reward)?|Reward|Interest)\b[^\d]*([0-9][0-9.,]*)/i
      );
      // Fallback: "... staking reward ... 0,123456 BTC"
      if (!rw) {
        const alt = block.match(
          /(?:Staking(?:\s+reward)?|Reward|Odměna(?:\s+ze\s+stakingu)?|Interest)\b[^\d]*([0-9][0-9.,]*)\s*([A-Z]{2,10})/i
        );
        if (alt) rw = [alt[0], alt[2], alt[1]]; // => [symbol, qty]
      }
      if (rw) {
        const symbol = (rw[1] || '').toUpperCase();
        const qty    = this.parseNumber(rw[2]) || 0;
        if (symbol && qty) {
          out.push({
            date: dateIso,
            id: symbol,
            amount: qty,
            price: null,
            ex_rate: null,
            amount_cur: 0,
            currency: 'CZK',
            amount_czk: 0,
            platform: 'Revolut',
            product_type: 'Crypto',
            trans_type: 'Revenue',
            fees: 0,
            __tmp: null,
            notes: 'Staking/Reward'
          });
          continue;
        }
      }

      // --- Nákup/Prodej (věty typu "Buy BTC ..." / "Prodej ETH ...") ---
      const trade = block.match(
        /(Buy|Sell|Nákup|Prodej)\s+([A-Z0-9]{2,10}).*?([0-9][0-9.,]*)\s*[A-Z0-9]{2,10}.*?(€|\$|CZK|USD|EUR)\s*([0-9][0-9.,]*)/i
      );

      if (trade) {
        const side     = trade[1];
        const symbol   = (trade[2] || '').toUpperCase();
        const qty      = this.parseNumber(trade[3]) || 0;
        const curTok   = trade[4];
        const total    = this.parseNumber(trade[5]) || 0;
        const currency = symToFiat(curTok) || 'CZK';

        // fees – fiat (EUR/USD/CZK) nebo v kryptu (BTC/ETH…)
        const feeM = block.match(/(?:Fee|Poplatek):\s*([0-9][0-9.,]*)\s*(€|\$|CZK|USD|EUR|[A-Z0-9]{2,10})/i);
        let tmp = null;
        if (feeM) {
          const feeVal = this.parseNumber(feeM[1]);
          const feeSym = (feeM[2] || '').toUpperCase();
          if (feeVal != null) {
            if (/^(€|\$|CZK|USD|EUR)$/.test(feeSym)) {
              tmp = { feeFiat: feeVal, feeFiatCurrency: symToFiat(feeSym) };
            } else {
              tmp = { feeCur: feeVal, feeCurSymbol: feeSym };
            }
          }
        }

        out.push({
          date: dateIso,
          id: symbol || 'CRYPTO',
          amount: Math.abs(qty),
          price: (qty && total != null) ? (total / Math.abs(qty)) : null,
          ex_rate: null,
          // SELL i BUY: total vždy kladný – směr určuje trans_type
          amount_cur: total,
          currency,
          amount_czk: currency === 'CZK' ? total : null,
          platform: 'Revolut',
          product_type: 'Crypto',
          trans_type: /Sell|Prodej/i.test(side) ? 'Sell' : 'Buy',
          fees: 0,
          __tmp: tmp,
          notes: `Trade ${side}`
        });
        continue;
      }
    }

    // ===== Po blokovém průchodu zkuste i tabulkovou část (Transakce / Přiložené transakce) =====
    const tabular = this.parseTabularSection(t);
    if (tabular.length) {
      const key = (x) =>
        [
          x.date || '',
          (x.id || '').toUpperCase(),
          x.trans_type || '',
          x.currency || '',
          (x.amount_cur == null ? 'n' : Number(x.amount_cur).toFixed(2)),
          (x.amount == null ? 'n' : Number(Math.abs(x.amount)).toFixed(8)),
        ].join('|');

      const seen = new Set(out.map(key));
      for (const tx of tabular) {
        const k = key(tx);
        if (!seen.has(k)) {
          out.push(tx);
          seen.add(k);
        }
      }
    }

    return out;
  }

  // Parsuje řádkovou tabulku "Transakce" / "Přiložené transakce" v PDF
  parseTabularSection(t) {
    const out = [];
    const symToCur = (s) => (s === '€' ? 'EUR' : s === '$' ? 'USD' : (s || '').toUpperCase());

    // symbol | typ | množství | cena | hodnota | poplatky | datum (s volitelným časem)
    const rowRe =
      /([A-Z0-9]{2,10})\s+(Nákup|Prodej|Platba|Odměna(?:\s+ze\s+stakingu)?|Reward|Staking(?:\s+reward)?|Interest|Buy|Sell)\s+([0-9][0-9.,\s]*)\s+([0-9][0-9.,\s]*)\s*(CZK|EUR|USD|CNY|€|\$)\s+([0-9][0-9.,\s]*)\s*(CZK|EUR|USD|CNY|€|\$)\s+([0-9][0-9.,\s]*)\s*(CZK|EUR|USD|CNY|€|\$)\s+(\d{1,2}\.\s*\d{1,2}\.\s*\d{4})(?:\s+\d{1,2}:\d{2}:\d{2})?/gim;

    let m2;
    while ((m2 = rowRe.exec(t)) !== null) {
      const symbolRaw = (m2[1] || '').toUpperCase();
      if (!/[A-Z]/.test(symbolRaw)) continue; // musí mít aspoň 1 písmeno (zahodí 300/914)

      const typeRaw  = m2[2] || '';
      const qty      = this.parseNumber(m2[3]) || 0;

      const priceVal = this.parseNumber(m2[4]); // může být null
      const priceCur = symToCur(m2[5]);
      const totalVal = this.parseNumber(m2[6]) || 0;
      const totalCur = symToCur(m2[7]);
      const feeVal   = this.parseNumber(m2[8]) || 0;
      const feeCur   = symToCur(m2[9]);
      const dateIso0 = this.csDateToISO(m2[10]) || this.enDateToISO(m2[10]) || '';
      const dateIso  = dateIso0 ? dateIso0 : '';

      const isBuy  = /Nákup|Buy/i.test(typeRaw);
      const isSell = /Prodej|Sell|Platba/i.test(typeRaw);
      const isRev  = /Odměna|Reward|Staking|Interest/i.test(typeRaw);

      if (isRev) {
        out.push({
          date: dateIso, id: symbolRaw, amount: qty, price: null, ex_rate: null,
          amount_cur: 0, currency: totalCur || priceCur || 'CZK', amount_czk: 0,
          platform: 'Revolut', product_type: 'Crypto', trans_type: 'Revenue',
          fees: 0, __tmp: null, notes: `Import ${symbolRaw} (PDF): ${typeRaw}`
        });
        continue;
      }
      if (!(isBuy || isSell)) continue;

      const currency  = totalCur || priceCur || 'CZK';
      const unitPrice = (qty && totalVal != null) ? (totalVal / Math.abs(qty)) : (priceVal ?? null);

      out.push({
        date: dateIso,
        id: symbolRaw,
        amount: Math.abs(qty),
        price: unitPrice,
        ex_rate: null,
        // SELL i BUY: total vždy kladný – směr určuje trans_type
        amount_cur: totalVal,
        currency,
        amount_czk: currency === 'CZK' ? totalVal : null,
        platform: 'Revolut',
        product_type: 'Crypto',
        trans_type: isSell ? 'Sell' : 'Buy',
        fees: 0,
        __tmp: { feeFiat: feeVal || 0, feeFiatCurrency: feeCur || currency },
        notes: `Import ${symbolRaw} (PDF): ${typeRaw}`
      });
    }
    return out;
  }

  /* ===== CSV ===== */
  parseCsv(rows) {
    const out = [];
    if (!rows || rows.length < 2) return out;

    const headers = rows[0].map(h => (h || '').toString().trim().toLowerCase());
    const findIdx = (...alts) => {
      for (const a of alts) {
        const i = headers.findIndex(h => h === a || new RegExp(`\\b${a}\\b`, 'i').test(h));
        if (i !== -1) return i;
      }
      return -1;
    };

    const idx = {
      date:     findIdx('date','datum'),
      symbol:   findIdx('symbol','ticker','crypto','krypto','asset'),
      type:     findIdx('type','typ','transaction type'),
      qty:      findIdx('quantity','qty','amount','množství','mnozstvi','coins'),
      total:    findIdx('total','amount (fiat)','hodnota','celkem','amount fiat','amount','value'),
      currency: findIdx('currency','měna','mena'),
      fee:      findIdx('fee','poplatky','poplatek'),
      feeCur:   findIdx('fee currency','měna poplatku','mena poplatku'),
      ppu:      findIdx('price per unit','price','cena za jednotku','cena')
    };

    for (let i = 1; i < rows.length; i++) {
      const r = rows[i]; if (!r || !r.length) continue;

      const typeRaw = (r[idx.type] || '').toString();
      const symbol  = (r[idx.symbol] || '').toString().trim().toUpperCase();
      if (!/[A-Z]/.test(symbol)) continue; // vyžaduj aspoň 1 písmeno
      const qty     = this.parseNumber(r[idx.qty]) ?? 0;

      const fromTotal = this.extractCurrencyAndNumber(r[idx.total]);
      let totalNum = (fromTotal && fromTotal.num != null) ? fromTotal.num : this.parseNumber(r[idx.total]);
      let currency = (r[idx.currency] || (fromTotal && fromTotal.currency) || 'USD').toString().trim().toUpperCase();
      if (currency === '€') currency = 'EUR';
      if (currency === '$') currency = 'USD';

      let feeFiat = this.parseNumber(r[idx.fee]);
      let feeFiatCurrency = currency;
      if ((feeFiat == null || Number.isNaN(feeFiat)) && r[idx.fee]) {
        const ex = this.extractCurrencyAndNumber(r[idx.fee]);
        if (ex && ex.num != null) {
          feeFiat = ex.num;
          if (ex.currency) feeFiatCurrency = (ex.currency.toUpperCase() === '€' ? 'EUR'
                                      : ex.currency.toUpperCase() === '$' ? 'USD'
                                      : ex.currency.toUpperCase());
        }
      }
      if (idx.feeCur !== -1 && r[idx.feeCur]) {
        const fc = (r[idx.feeCur] || '').toString().trim().toUpperCase();
        if (fc) feeFiatCurrency = (fc === '€' ? 'EUR' : fc === '$' ? 'USD' : fc);
      }

      const unit = (qty && totalNum != null) ? (Math.abs(totalNum) / Math.abs(qty)) : (this.parseNumber(r[idx.ppu]) ?? null);

      if (/reward|staking|interest|airdrop|bonus|odměna|výnos/i.test(typeRaw)) {
        if (symbol && qty) {
          out.push({
            date: this.safeDate(r[idx.date]),
            id: symbol,
            amount: qty,
            price: null,
            ex_rate: null,
            amount_cur: 0,
            currency: 'CZK',
            amount_czk: 0,
            platform: 'Revolut',
            product_type: 'Crypto',
            trans_type: 'Revenue',
            fees: 0,
            __tmp: null,
            notes: `CSV: ${typeRaw}`
          });
        }
        continue;
      }

      if (/buy|sell|nákup|nakup|prodej|platba/i.test(typeRaw)) {
        const isSell = /sell|prodej|platba/i.test(typeRaw);
        out.push({
          date: this.safeDate(r[idx.date]),
          id: symbol || 'CRYPTO',
          amount: Math.abs(qty),
          price: unit ?? null,
          ex_rate: null,
          // SELL i BUY: total vždy kladný
          amount_cur: totalNum ?? 0,
          currency,
          amount_czk: currency === 'CZK' ? (totalNum ?? 0) : null,
          platform: 'Revolut',
          product_type: 'Crypto',
          trans_type: isSell ? 'Sell' : 'Buy',
          fees: 0,
          __tmp: (feeFiat != null && !Number.isNaN(feeFiat)) ? { feeFiat, feeFiatCurrency } : null,
          notes: `CSV: ${typeRaw}`
        });
      }
    }
    return out;
  }

  safeDate(v) {
    const s = (v || '').toString();
    if (/\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0,10);
    if (/\d{1,2}\.\s*\d{1,2}\.\s*\d{4}/.test(s)) return this.csDateToISO(s);
    if (/\d{1,2}\s[A-Za-z]{3}\s\d{4}/.test(s))   return this.enDateToISO(s);
    try { return new Date(s).toISOString().slice(0,10); } catch { return ''; }
  }
}
