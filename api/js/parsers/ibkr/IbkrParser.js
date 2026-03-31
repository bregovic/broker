// /broker/js/parsers/ibkr/IbkrParser.js
// IBKR Parser v3.x – PDF import s podporou Buy/Sell v CZK

import BaseParser from '../BaseParser.js';

export default class IbkrParser extends BaseParser {
  async parse(content) {
    if (!content) {
      console.log('❌ IBKR Parser: Žádný obsah k parsování!');
      return [];
    }

    if (Array.isArray(content)) {
      console.log('📊 IBKR Parser: Vstup je CSV pole');
      return this.parseCsv(content);
    }

    if (typeof content === 'string') {
      console.log('📄 IBKR Parser: Vstup je textový řetězec (PDF)');
      return this.parsePdf(content);
    }

    throw new Error('IBKR: Neplatný vstup pro parser.');
  }

  /* ======================= PDF PARSER ======================= */

  parsePdf(text) {
    console.log('\n' + '='.repeat(70));
    console.log('🔍 IBKR PDF PARSER – Buy/Sell + CZK částky');
    console.log('='.repeat(70) + '\n');

    if (!text || typeof text !== 'string') {
      console.log('❌ IBKR PDF Parser: prázdný nebo neplatný vstup textu.');
      return [];
    }

    // 1) Normalizace textu
    let cleanText = text
      .replace(/\u00A0/g, ' ')
      .replace(/\r/g, '\n')
      .trim();

    let lines = cleanText
      .split(/\n/)
      .map(l => l.trim())
      .filter(Boolean);

    console.log(`📊 Vstupní řádky: ${lines.length}`);

    // 2) Oprava rozsekaných dat (2024-12 / 02 / ...)
    lines = this.fixBrokenDates(lines);

    // 3) Slepíme řádky podle data – jedna transakce = jeden řádek
    lines = this.mergeLinesByDate(lines);
    console.log(`📊 Po sloučení řádků podle data: ${lines.length}`);

    const transactions = [];
    const processedKeys = new Set();

    let buyCount = 0;
    let sellCount = 0;
    let divCount = 0;
    let taxCount = 0;
    let fxCount = 0;
    let depositCount = 0;
    let feeCount = 0;
    let corpCount = 0;

    // Debug – kolik máme Buy/Sell řádků
    const buyLines = lines.filter(
      l => l.includes(' Buy ') && /^20\d{2}-\d{2}-\d{2}/.test(l)
    );
    const sellLines = lines.filter(
      l => l.includes(' Sell ') && /^20\d{2}-\d{2}-\d{2}/.test(l)
    );
    console.log(
      `🔍 Nalezeno ${buyLines.length} Buy řádků a ${sellLines.length} Sell řádků`
    );

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];

      // Musí začínat datem
      const dateMatch = line.match(/^(20\d{2}-\d{2}-\d{2})/);
      if (!dateMatch) continue;
      const date = dateMatch[1];

      /* ---------- 1) BUY ---------- */
      if (line.includes(' Buy ')) {
        // očekáváme: YYYY-MM-DD ... Buy SYMBOL qty price CCY ... CZK_amount
        const parts = line.split(/\s+/);
        const buyIndex = parts.findIndex(p => p === 'Buy');

        if (buyIndex > 0 && buyIndex < parts.length - 4) {
          const symbol = parts[buyIndex + 1];
          const quantity = parseFloat(parts[buyIndex + 2]) || 0;
          const price = parseFloat(parts[buyIndex + 3]) || 0;
          const origCurrency = parts[buyIndex + 4] || 'USD';

          // poslední číslo v řádku = CZK částka
          let czkAmount = this.extractLastAmountFromParts(parts);
          if (symbol && czkAmount !== null) {
            const txKey = `${date}|${symbol}|Buy|${Math.abs(
              czkAmount
            ).toFixed(2)}|${Math.abs(quantity).toFixed(4)}`;
            if (!processedKeys.has(txKey)) {
              processedKeys.add(txKey);

              transactions.push({
                date,
                id: symbol,
                amount: Math.abs(quantity), // počet kusů
                price,
                amount_cur: czkAmount, // CZK částka z výpisu (většinou záporná)
                currency: 'CZK',
                platform: 'IBKR',
                product_type: 'Stock',
                trans_type: 'Buy',
                fees: 0,
                notes: `IBKR Buy ${Math.abs(quantity)}x ${symbol} @ ${price} ${origCurrency}`
              });
              buyCount++;
              console.log(
                `   ✅ Buy: ${date} ${symbol} ${Math.abs(
                  quantity
                )}x = ${czkAmount} CZK`
              );
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
          const quantity = parts[sellIndex + 2]
            ? Math.abs(parseFloat(parts[sellIndex + 2]))
            : 0;
          const price = parseFloat(parts[sellIndex + 3]) || 0;
          const origCurrency = parts[sellIndex + 4] || 'USD';

          let czkAmount = this.extractLastAmountFromParts(parts);
          if (czkAmount !== null) {
            // Prodej = příjem → CZK částka kladně
            czkAmount = Math.abs(czkAmount);

            const txKey = `${date}|${symbol}|Sell|${czkAmount.toFixed(
              2
            )}|${Math.abs(quantity).toFixed(4)}`;
            if (!processedKeys.has(txKey)) {
              processedKeys.add(txKey);

              transactions.push({
                date,
                id: symbol,
                amount: Math.abs(quantity),
                price,
                amount_cur: czkAmount,
                currency: 'CZK',
                platform: 'IBKR',
                product_type: 'Stock',
                trans_type: 'Sell',
                fees: 0,
                notes: `IBKR Sell ${Math.abs(quantity)}x ${symbol} @ ${price} ${origCurrency}`
              });
              sellCount++;
              console.log(
                `   ✅ Sell: ${date} ${symbol} ${Math.abs(
                  quantity
                )}x = ${czkAmount} CZK`
              );
            }
          }
        }

        continue;
      }

      /* ---------- 3) OSTATNÍ (DIV, TAX, FX, DEPOSIT, CORP…) ---------- */

      const fullContext = line; // po sloučení podle data je vše pod jedním datem na jednom řádku
      const transType = this.detectTransactionType(fullContext);
      if (!transType) continue;

      const symbol = this.extractSymbol(fullContext, transType);
      const netAmount = this.extractLastAmount(fullContext);
      if (netAmount === null) continue;

      const txKey = `${date}|${symbol || ''}|${transType}|${Math.abs(
        netAmount
      ).toFixed(2)}`;
      if (processedKeys.has(txKey)) continue;
      processedKeys.add(txKey);

      let finalAmount = netAmount;

      if (transType === 'Tax' || transType === 'Fee') {
        finalAmount = -Math.abs(netAmount);
        if (transType === 'Tax') taxCount++;
        else feeCount++;
      } else if (transType === 'Dividend') {
        finalAmount = Math.abs(netAmount);
        divCount++;
      } else if (transType === 'Deposit') {
        finalAmount = Math.abs(netAmount);
        depositCount++;
      } else if (transType === 'Corporate Action') {
        finalAmount = Math.abs(netAmount);
        corpCount++;
      } else if (transType === 'FX') {
        fxCount++;
      }

      transactions.push({
        date,
        id: symbol || (transType === 'Deposit' ? 'CASH_CZK' : 'FX_PNL'),
        amount: 1,
        price: transType === 'Dividend' ? Math.abs(netAmount) : null,
        amount_cur: finalAmount,
        currency: 'CZK',
        platform: 'IBKR',
        product_type: this.getProductType(transType),
        trans_type: transType,
        fees: transType === 'Fee' ? Math.abs(netAmount) : 0,
        notes: `IBKR ${transType} ${symbol || ''}`.trim()
      });

      console.log(
        `   ✅ ${transType}: ${date} ${symbol || ''} = ${finalAmount} CZK`
      );
    }

    console.log('\n📊 Souhrn IBKR PDF importu:');
    console.log(
      `   ✅ Buy: ${buyCount}, Sell: ${sellCount}, Dividendy: ${divCount}, Tax: ${taxCount}, FX: ${fxCount}, Deposit: ${depositCount}, Fee: ${feeCount}, Corp: ${corpCount}`
    );
    console.log(`   🔢 Celkem transakcí: ${transactions.length}`);
    console.log('\n' + '='.repeat(70) + '\n');

    return transactions;
  }

  /* ======================= POMOCNÉ FUNKCE ======================= */

  // Slepí rozsekané datum + řádky: vše od data po další datum
  mergeLinesByDate(lines) {
    const merged = [];
    let current = '';

    for (const l of lines) {
      const isDate = /^20\d{2}-\d{2}-\d{2}\b/.test(l);
      if (isDate) {
        if (current) merged.push(current.trim());
        current = l;
      } else if (current) {
        current += ' ' + l;
      }
    }
    if (current) merged.push(current.trim());
    return merged;
  }

  // IBKR někdy rozseká datum na 3 řádky; tady je slepíme
  fixBrokenDates(lines) {
    const fixedLines = [];
    let i = 0;

    while (i < lines.length) {
      const line = lines[i];
      const incompleteMatch = line.match(/^(20\d{2}-\d{2})-?$/);

      if (incompleteMatch && i + 1 < lines.length) {
        const nextLine = lines[i + 1];

        if (/^\d{2}$/.test(nextLine)) {
          // "2024-12" + "02" + " zbytek"
          if (i + 2 < lines.length) {
            const thirdLine = lines[i + 2];
            const fixed = `${incompleteMatch[1]}-${nextLine} ${thirdLine}`;
            fixedLines.push(fixed);
            i += 3;
            continue;
          }
        } else if (/^\d{2}\s/.test(nextLine)) {
          // "2024-12" + "02 zbytek"
          const day = nextLine.substring(0, 2);
          const rest = nextLine.substring(2).trim();
          const fixed = `${incompleteMatch[1]}-${day} ${rest}`;
          fixedLines.push(fixed);
          i += 2;
          continue;
        }
      }

      fixedLines.push(line);
      i++;
    }

    return fixedLines;
  }

  detectTransactionType(text) {
    if (/Merged.*Acquisition/i.test(text) || /Corporate Action/i.test(text)) {
      return 'Corporate Action';
    }
    if (/FX Translation|P&L Adjustment/i.test(text)) {
      return 'FX';
    }
    if (/Other Fee|FEE$/i.test(text)) {
      return 'Fee';
    }
    if (/Cash Transfer.*(?:Deposit|Transfer to)/i.test(text)) {
      return 'Deposit';
    }
    if (
      /(?:Foreign Tax|US Tax|JP Tax|Withholding)/i.test(text) &&
      !/(Dividend.*per Share\s*\(Ordinary)/i.test(text)
    ) {
      return 'Tax';
    }
    if (
      /Cash Dividend.*per Share(?!.*Tax)/i.test(text) ||
      /Stock Dividend.*Ordinary(?!.*Tax)/i.test(text)
    ) {
      return 'Dividend';
    }

    return null;
  }

  extractSymbol(text, transType) {
    if (transType === 'Deposit') return 'CASH_CZK';
    if (transType === 'FX') return 'FX_PNL';

    // TICKER (ISIN v závorce)
    const isinMatch = text.match(
      /\b([A-Z][A-Z0-9.\-]{0,9})\s*\([A-Z]{2}[A-Z0-9]{8,10}\)/
    );
    if (isinMatch) return isinMatch[1];

    // fallback – prostý ticker před slovem Dividend/Tax/Fee
    const tickerMatch = text.match(
      /\b([A-Z]{2,5})\b(?=.*(?:Dividend|Tax|Fee))/
    );
    if (tickerMatch) {
      const ticker = tickerMatch[1];
      const excluded = ['USD', 'EUR', 'CZK', 'US', 'JP', 'TAX', 'FEE', 'FOR'];
      if (!excluded.includes(ticker)) return ticker;
    }

    return null;
  }

  // Poslední částka v textu (např. netto v CZK)
  extractLastAmount(text) {
    const amounts = [];
    const regex = /[-\d,]+\.\d{2}(?=\s|$)/g; // čísla s . jako desetinnou tečkou
    let match;

    while ((match = regex.exec(text)) !== null) {
      const cleanAmount = match[0].replace(/,/g, '');
      const num = parseFloat(cleanAmount);
      if (!isNaN(num) && Math.abs(num) < 10000000) {
        amounts.push(num);
      }
    }

    return amounts.length > 0 ? amounts[amounts.length - 1] : null;
  }

  // Stejná logika jako výše, ale nad poli (už splitnutý řádek)
  extractLastAmountFromParts(parts) {
    for (let j = parts.length - 1; j >= 0; j--) {
      const cleaned = parts[j].replace(/,/g, '');
      if (/^-?\d+\.\d{2}$/.test(cleaned)) {
        const num = parseFloat(cleaned);
        if (!isNaN(num)) return num;
      }
    }
    return null;
  }

  getProductType(transType) {
    const mapping = {
      Dividend: 'Stock',
      Tax: 'Tax',
      Fee: 'Fee',
      Deposit: 'Cash',
      Withdrawal: 'Cash',
      FX: 'FX',
      'Corporate Action': 'Stock',
      Buy: 'Stock',
      Sell: 'Stock'
    };
    return mapping[transType] || 'Stock';
  }

  /* ======================= CSV (zatím jen placeholder) ======================= */

  parseCsv(rows) {
    console.log('CSV parsing pro IBKR zatím není implementován.');
    return [];
  }
}
