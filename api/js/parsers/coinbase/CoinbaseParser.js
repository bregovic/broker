// /broker/js/parsers/coinbase/CoinbaseParser.js
import BaseParser from '../BaseParser.js';

export default class CoinbaseParser extends BaseParser {
  async parse(content) {
    if (Array.isArray(content)) return this.parseCsv(content);
    if (typeof content === 'string') {
      // Rozlišení PDF vs HTML
      if (content.includes('Transaction History Report') && !content.includes('<html>')) {
        return this.parsePdf(content);
      }
      return this.parseHtml(content);
    }
    throw new Error('Neplatný formát obsahu pro Coinbase');
  }

  /* ===================== PDF (Transaction History Report) ===================== */
  parsePdf(text) {
    const out = [];
    if (!text) return out;

    const N = (s) => this.parseNumber(s);
    const normCur = (s) => {
      const u = (s || '').toString().trim().toUpperCase();
      return u === 'KČ' ? 'CZK' : u;
    };

    // Rozdělíme na řádky a najdeme transakce
    const rawLines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    
    for (let i = 0; i < rawLines.length; i++) {
      const line = rawLines[i];
      
      // Pattern pro timestamp na začátku řádku
      if (!/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+UTC/.test(line)) continue;
      
      const parts = this.parsePdfLine(line);
      if (!parts) continue;

      const { timestamp, transactionType, asset, quantity, priceCurrency, priceAtTransaction, subtotal, total, notes } = parts;
      const date = this.timestampToISO(timestamp);
      
      if (!date) continue;

      // SELL transakce
      if (/^Sell$/i.test(transactionType) && asset && quantity < 0) {
        // Pro Sell: quantity je záporné (-0.1 BTC), total je kladné (kolik jsme dostali)
        const totalAmount = Math.abs(total || 0);
        const assetQuantity = Math.abs(quantity);
        
        if (totalAmount > 0 && assetQuantity > 0) {
          const unitPrice = totalAmount / assetQuantity;
          
          out.push({
            date,
            id: asset.toUpperCase(),
            amount: assetQuantity,
            price: unitPrice,
            ex_rate: null,
            amount_cur: totalAmount,
            currency: normCur(priceCurrency),
            amount_czk: priceCurrency === 'CZK' ? totalAmount : null,
            platform: 'Coinbase',
            product_type: 'Crypto',
            trans_type: 'Sell',
            fees: 0, // Fees už jsou započítané do total
            notes: `Coinbase PDF: Sell ${notes || ''}`
          });
        }
        continue;
      }

      // BUY transakce (označené jako Buy nebo implicitně při kladné quantity)
      if ((/^Buy$/i.test(transactionType) || quantity > 0) && asset && 
          !['EUR', 'USD', 'CZK', 'GBP'].includes(asset.toUpperCase())) {
        
        const totalAmount = Math.abs(total || 0);
        const assetQuantity = Math.abs(quantity);
        
        if (totalAmount > 0 && assetQuantity > 0) {
          const unitPrice = totalAmount / assetQuantity;
          
          out.push({
            date,
            id: asset.toUpperCase(),
            amount: assetQuantity,
            price: unitPrice,
            ex_rate: null,
            amount_cur: totalAmount,
            currency: normCur(priceCurrency),
            amount_czk: priceCurrency === 'CZK' ? totalAmount : null,
            platform: 'Coinbase',
            product_type: 'Crypto',
            trans_type: 'Buy',
            fees: 0,
            notes: `Coinbase PDF: Buy ${notes || ''}`
          });
        }
        continue;
      }

      // DEPOSIT a WITHDRAWAL pro cash
      if (/^(Deposit|Withdrawal)$/i.test(transactionType) && ['EUR', 'USD', 'CZK', 'GBP'].includes(asset.toUpperCase())) {
        const isDeposit = /^Deposit$/i.test(transactionType);
        const totalAmount = Math.abs(total || 0);
        
        if (totalAmount > 0) {
          out.push({
            date,
            id: `CASH_${asset.toUpperCase()}`,
            amount: 1,
            price: totalAmount,
            ex_rate: null,
            amount_cur: totalAmount,
            currency: normCur(asset),
            amount_czk: asset === 'CZK' ? totalAmount : null,
            platform: 'Coinbase',
            product_type: 'Cash',
            trans_type: isDeposit ? 'Deposit' : 'Withdrawal',
            fees: 0,
            notes: `Coinbase PDF: ${transactionType} ${notes || ''}`
          });
        }
        continue;
      }

      // EXCHANGE DEPOSIT (interní převod na trading účet)
      if (/Exchange Deposit/i.test(transactionType)) {
        // Tyto transakce můžeme přeskočit nebo označit jako interní převod
        continue;
      }

      // PRO WITHDRAWAL (velké výběry BTC)
      if (/Pro Withdrawal/i.test(transactionType) && asset && quantity > 0) {
        const totalAmount = Math.abs(total || 0);
        const assetQuantity = Math.abs(quantity);
        
        out.push({
          date,
          id: asset.toUpperCase(),
          amount: assetQuantity,
          price: null,
          ex_rate: null,
          amount_cur: totalAmount,
          currency: normCur(priceCurrency),
          amount_czk: priceCurrency === 'CZK' ? totalAmount : null,
          platform: 'Coinbase',
          product_type: 'Crypto',
          trans_type: 'Withdrawal',
          fees: 0,
          notes: `Coinbase PDF: Pro Withdrawal ${notes || ''}`
        });
        continue;
      }
    }

    console.debug('Coinbase PDF parsed:', out.length, 'transactions');
    return out;
  }

  // Parsování jednotlivého řádku PDF
  parsePdfLine(line) {
    // Pattern pro řádek: timestamp transactionType asset quantity priceCurrency priceAtTransaction subtotal total notes
    // Příklad: "2024-11-21 10:46:10 UTC Sell BTC -0.1 CZK Kč2346220.225... Kč231968.44728 Kč228512.08999 Sold 0.1 BTC for..."
    
    const N = (s) => this.parseNumber(s);
    
    // Rozdělíme na části
    const parts = line.split(/\s+/);
    if (parts.length < 8) return null;
    
    const timestamp = `${parts[0]} ${parts[1]} ${parts[2]}`; // "2024-11-21 10:46:10 UTC"
    let transactionType = parts[3]; // "Sell" - OPRAVA: změněno z const na let
    
    let asset, quantity, priceCurrency, priceAtTransaction, subtotal, total, notes;
    
    // Různé formáty podle typu transakce
    if (transactionType === 'Sell') {
      asset = parts[4]; // "BTC"
      quantity = N(parts[5]); // "-0.1"
      priceCurrency = parts[6]; // "CZK"
      
      // Najdeme Kč hodnoty
      const kcAmounts = line.match(/Kč([\d.,]+)/g) || [];
      if (kcAmounts.length >= 2) {
        subtotal = N(kcAmounts[kcAmounts.length - 2]); // předposlední
        total = N(kcAmounts[kcAmounts.length - 1]); // poslední
      }
      
      // Notes jsou zbytek řádku po posledním Kč
      const lastKcIndex = line.lastIndexOf('Kč');
      if (lastKcIndex !== -1) {
        const afterAmount = line.substring(lastKcIndex).match(/Kč[\d.,]+\s+(.+)/);
        notes = afterAmount ? afterAmount[1] : '';
      }
      
    } else if (transactionType === 'Deposit') {
      asset = parts[4]; // "EUR"
      quantity = N(parts[5]); // "1000"
      priceCurrency = parts[6]; // "CZK"
      
      const kcAmounts = line.match(/Kč([\d.,]+)/g) || [];
      if (kcAmounts.length >= 1) {
        total = N(kcAmounts[kcAmounts.length - 1]);
      }
      
      const lastKcIndex = line.lastIndexOf('Kč');
      if (lastKcIndex !== -1) {
        const afterAmount = line.substring(lastKcIndex).match(/Kč[\d.,]+\s+(.+)/);
        notes = afterAmount ? afterAmount[1] : '';
      }
      
    } else if (transactionType === 'Withdrawal') {
      asset = parts[4]; // "EUR"
      quantity = N(parts[5]); // "-9023.84"
      priceCurrency = parts[6]; // "CZK"
      
      const kcAmounts = line.match(/Kč([\d.,]+)/g) || [];
      if (kcAmounts.length >= 1) {
        total = N(kcAmounts[kcAmounts.length - 1]);
      }
      
      const lastKcIndex = line.lastIndexOf('Kč');
      if (lastKcIndex !== -1) {
        const afterAmount = line.substring(lastKcIndex).match(/Kč[\d.,]+\s+(.+)/);
        notes = afterAmount ? afterAmount[1] : '';
      }
      
    } else if (line.includes('Exchange Deposit')) {
      // "Exchange Deposit EUR -1000 CZK ..."
      const match = line.match(/Exchange Deposit\s+([A-Z]+)\s+(-?[\d.,]+)\s+([A-Z]+)/);
      if (match) {
        transactionType = 'Exchange Deposit'; // Označíme jako interní
        asset = match[1];
        quantity = N(match[2]);
        priceCurrency = match[3];
        
        const kcAmounts = line.match(/-?Kč([\d.,]+)/g) || [];
        if (kcAmounts.length >= 1) {
          total = N(kcAmounts[kcAmounts.length - 1]);
        }
        
        notes = line.substring(line.indexOf('Deposited') || 0); 
      } else {
        return null;
      }
      
    } else if (line.includes('Pro Withdrawal')) {
      // "Pro Withdrawal BTC 1.22992676 CZK ..."
      const match = line.match(/Pro Withdrawal\s+([A-Z]+)\s+([\d.,]+)\s+([A-Z]+)/);
      if (match) {
        transactionType = 'Pro Withdrawal'; // Oprava: celý název typu
        asset = match[1];
        quantity = N(match[2]);
        priceCurrency = match[3];
        
        const kcAmounts = line.match(/Kč([\d.,]+)/g) || [];
        if (kcAmounts.length >= 1) {
          total = N(kcAmounts[kcAmounts.length - 1]);
        }
      } else {
        return null;
      }
      
    } else {
      // Zkus generic pattern pro ostatní typy
      console.debug('Unknown transaction type:', transactionType, 'in line:', line.substring(0, 100));
      return null; // Nepodporovaný typ transakce
    }
    
    return {
      timestamp,
      transactionType,
      asset,
      quantity,
      priceCurrency,
      priceAtTransaction,
      subtotal,
      total,
      notes: (notes || '').trim()
    };
  }

  /* ===================== HTML (původní implementace) ===================== */
  parseHtml(html) {
    const out = [];
    const t = String(html);
    const normCur = (s) => {
      const u = (s || '').toString().trim().toUpperCase();
      return u === 'KČ' ? 'CZK' : u;
    };

    // každá transakce je <tr class="transaction-row">…</tr>
    const rowRe = /<tr[^>]*class="[^"]*transaction-row[^"]*"[^>]*>([\s\S]*?)<\/tr>/gi;
    let rm;
    while ((rm = rowRe.exec(t)) !== null) {
      const cells = [];
      const tdRe = /<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/gi;
      let cm; while ((cm = tdRe.exec(rm[1])) !== null) cells.push(this._strip(cm[1]));
      if (cells.length < 6) continue;

      const ts       = cells[0] || '';
      const typeRaw  = cells[1] || '';
      const asset    = (cells[2] || '').toUpperCase().trim();
      const qty      = Math.abs(this.parseNumber(cells[3]) ?? 0);
      let   priceCur = normCur(cells[4] || '');
      const spotTxt  = cells[5] || '';
      const subtotal = this.parseNumber(cells[6]);
      let   total    = this.parseNumber(cells[7]);
      const notes    = cells[8] || '';
      const date     = this.timestampToISO(ts);

      const isReward = /(reward|staking|learn|interest)/i.test(typeRaw);
      const isTrade  = /(buy|sell)/i.test(typeRaw);
      if (!date || (!isReward && !isTrade)) continue;
      if (!asset || /^(CZK|EUR|USD|GBP|CHF|PLN|HUF|JPY|CNY|AUD|CAD|NOK|SEK)$/i.test(asset)) continue;

      if (isReward) {
        if (!qty) continue;
        out.push({
          date, id: asset, amount: qty, price: null, ex_rate: null,
          amount_cur: 0, currency: 'CZK', amount_czk: 0,
          platform: 'Coinbase', product_type: 'Crypto', trans_type: 'Revenue',
          fees: 0, notes: `Coinbase HTML: ${typeRaw}${notes ? ' – ' + notes : ''}`,
        });
        continue;
      }

      // doplnění total/měny z poznámky, kdyby chyběly
      if ((total == null || Number.isNaN(total)) && notes) {
        const m = notes.match(/for\s+([0-9\s.,]+)\s*([A-ZČ]{2,3})/i);
        if (m) {
          const n = this.parseNumber(m[1]); const c = normCur(m[2]);
          if (n != null) total = n; if (!priceCur && c) priceCur = c;
        }
      }
      if (!priceCur || total == null || !qty) continue;

      const unit = Math.abs(total) / Math.abs(qty);
      const feeFiat = (subtotal != null && total != null && Math.abs(subtotal) > Math.abs(total))
        ? Math.abs(subtotal) - Math.abs(total) : 0;

      out.push({
        date, id: asset, amount: qty,
        price: Number.isFinite(unit) ? unit : (this.parseNumber(spotTxt) ?? null),
        ex_rate: null,
        amount_cur: Math.abs(total),
        currency: priceCur,
        amount_czk: priceCur === 'CZK' ? Math.abs(total) : null,
        platform: 'Coinbase', product_type: 'Crypto',
        trans_type: /sell/i.test(typeRaw) ? 'Sell' : 'Buy',
        fees: 0,
        __tmp: feeFiat > 0 ? { feeFiat, feeFiatCurrency: priceCur } : null,
        notes: `Coinbase HTML: ${typeRaw}${notes ? ' – ' + notes : ''}`,
      });
    }
    return out;
  }

  /* ===================== CSV (původní implementace) ===================== */
  parseCsv(rows) {
    const out = [];
    if (!Array.isArray(rows) || rows.length < 2) return out;

    // Coinbase CSV má často preambuli → najdi skutečnou hlavičku
    let headers = null, headerIndex = -1;
    for (let i = 0; i < Math.min(rows.length, 10); i++) {
      const r = (rows[i] || []).map(h => (h || '').toString().trim().toLowerCase());
      if (r.includes('timestamp') && r.includes('transaction type') && r.includes('asset')) {
        headers = r; headerIndex = i; break;
      }
    }
    if (!headers) return out;

    const find = (...alts) => {
      for (const a of alts) {
        const idx = headers.findIndex(h => h === a || h.includes(a));
        if (idx !== -1) return idx;
      }
      return -1;
    };

    const iTs    = find('timestamp');
    const iType  = find('transaction type');
    const iAsset = find('asset');
    const iQty   = find('quantity transacted','quantity');
    const iPCur  = find('spot price currency','price currency');
    const iPAt   = find('spot price at transaction','price at transaction');
    const iSub   = find('subtotal');
    const iTotal = find('total (inclusive of fees and/or spread)','total');
    const iFees  = find('fees and/or spread','fees');
    const iNotes = find('notes');
    const normCur = (s) => (String(s || '').trim().toUpperCase() === 'KČ' ? 'CZK' : String(s || '').trim().toUpperCase());

    for (let r = headerIndex + 1; r < rows.length; r++) {
      const row = rows[r] || [];
      if (!row || !row.length) continue;

      const ts     = (row[iTs]    || '').toString().trim();
      const type   = (row[iType]  || '').toString().trim();
      const asset  = (row[iAsset] || '').toString().trim().toUpperCase();
      const qty    = Math.abs(this.parseNumber(row[iQty]) ?? 0);
      let   pcur   = normCur(row[iPCur] || '');
      const pAt    = this.parseNumber(row[iPAt]);
      const subtotal = this.parseNumber(row[iSub]);
      const total    = this.parseNumber(row[iTotal]);
      const fees     = this.parseNumber(row[iFees]) || 0;
      const notes    = (row[iNotes] || '').toString().trim();

      const date     = this.timestampToISO(ts);
      const isReward = /(reward|staking|learn|interest)/i.test(type);
      const isTrade  = /(buy|sell)/i.test(type);
      if (!date || !asset) continue;
      if (!isReward && !isTrade) continue;
      if (/^(CZK|KČ|EUR|USD|GBP|CHF|PLN|HUF|JPY|CNY|AUD|CAD|NOK|SEK)$/i.test(asset)) continue;

      if (isReward) {
        if (!qty) continue;
        out.push({
          date, id: asset, amount: qty, price: null, ex_rate: null,
          amount_cur: 0, currency: 'CZK', amount_czk: 0,
          platform: 'Coinbase', product_type: 'Crypto', trans_type: 'Revenue',
          fees: 0, notes: `Coinbase CSV: ${type}${notes ? ' – ' + notes : ''}`
        });
        continue;
      }

      // chybějící měna → zkus Total/Notes
      if (!pcur && row[iTotal]) {
        const ex = this.extractCurrencyAndNumber(row[iTotal]);
        if (ex && ex.currency) pcur = normCur(ex.currency);
      }
      if (!pcur && notes) {
        const m = notes.match(/\b([A-ZČ]{2,3})\b/); if (m) pcur = normCur(m[1]);
      }
      if (!pcur || total == null || !qty) continue;

      const unit = Math.abs(total) / Math.abs(qty);
      const feeFiat = (subtotal != null && total != null && Math.abs(subtotal) >= Math.abs(total))
        ? Math.abs(subtotal) - Math.abs(total) : (fees > 0 ? fees : 0);

      out.push({
        date, id: asset, amount: qty,
        price: Number.isFinite(unit) ? unit : (pAt ?? null),
        ex_rate: null,
        amount_cur: Math.abs(total),
        currency: pcur,
        amount_czk: pcur === 'CZK' ? Math.abs(total) : null,
        platform: 'Coinbase', product_type: 'Crypto',
        trans_type: /sell/i.test(type) ? 'Sell' : 'Buy',
        fees: 0,
        __tmp: feeFiat > 0 ? { feeFiat, feeFiatCurrency: pcur } : null,
        notes: `Coinbase CSV: ${type}${notes ? ' – ' + notes : ''}`,
      });
    }
    return out;
  }

  /* ===================== Helper methods ===================== */
  parseNumber(str) {
    if (str == null) return null;
    
    let value = String(str).trim()
      .replace(/Kč/g, '') // Remove Czech crown symbol
      .replace(/\u00A0/g, ' ')
      .replace(/ /g, '');

    const hasDot = value.includes('.');
    const hasComma = value.includes(',');

    if (hasDot && hasComma) {
      const lastDot = value.lastIndexOf('.');
      const lastComma = value.lastIndexOf(',');
      if (lastComma > lastDot) {
        value = value.replace(/\./g, '').replace(',', '.');
      } else {
        value = value.replace(/,/g, '');
      }
    } else if (hasComma && !hasDot) {
      value = value.replace(',', '.');
    }

    value = value.replace(/[^0-9.\-]/g, '');
    const num = parseFloat(value);
    
    return Number.isFinite(num) ? num : null;
  }

  _strip(s) { 
    return String(s).replace(/<[^>]*>/g,'').replace(/\u00A0/g,' ').trim(); 
  }

  timestampToISO(ts) { 
    const m = String(ts).match(/(\d{4})-(\d{2})-(\d{2})/); 
    return m ? `${m[1]}-${m[2]}-${m[3]}` : ''; 
  }
}