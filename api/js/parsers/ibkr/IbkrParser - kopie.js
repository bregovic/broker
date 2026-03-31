// IBKR Parser v3.0 - Oprava pro Buy/Sell s CZK částkami
// Správně parsuje částky v CZK na konci řádku

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

  parsePdf(text) {
    console.log('\n' + '='.repeat(70));
    console.log('🔍 IBKR PDF PARSER v3.0 - OPRAVA BUY/SELL');
    console.log('='.repeat(70) + '\n');

    if (!text || typeof text !== 'string') {
      console.log('❌ IBKR PDF Parser: prázdný nebo neplatný vstup textu.');
      return [];
    }

    // Vyčistíme text
    let cleanText = text
      .replace(/\u00A0/g, ' ')
      .replace(/\r/g, '\n')
      .trim();

    let lines = cleanText
      .split(/\n/)
      .map(l => l.trim())
      .filter(Boolean);

    console.log(`📊 Vstupní řádky: ${lines.length}`);

    // Oprava rozsekaných datumů
    lines = this.fixBrokenDates(lines);

    // Sloučení rozsekaných PDF řádků podle data (jedna transakce = jeden řádek)
    const mergedLines = [];
    let current = '';
    for (const l of lines) {
      const isDateStart = /^20\d{2}-\d{2}-\d{2}/.test(l);
      if (isDateStart) {
        if (current) mergedLines.push(current.trim());
        current = l;
      } else if (current) {
        current += ' ' + l;
      } else {
        // ignorujeme hlavičky/tabulky před prvním datem
      }
    }
    if (current) mergedLines.push(current.trim());

    console.log(`📊 Po sloučení řádků podle data: ${mergedLines.length}`);

    lines = mergedLines;

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

    // Debug - najdeme všechny Buy/Sell řádky
    const buyLines = lines.filter(
      l => l.includes(' Buy ') && l.match(/^20\d{2}-\d{2}-\d{2}/)
    );
    const sellLines = lines.filter(
      l => l.includes(' Sell ') && l.match(/^20\d{2}-\d{2}-\d{2}/)
    );
    console.log(
      `\n🔍 Nalezeno ${buyLines.length} Buy řádků a ${sellLines.length} Sell řádků v textu`
    );

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];

      // Musí začínat datumem
      const dateMatch = line.match(/^(20\d{2}-\d{2}-\d{2})/);
      if (!dateMatch) continue;

      const date = dateMatch[1];

      // 1. BUY transakce - OPRAVENÝ PATTERN
      if (line.includes(' Buy ')) {
        // Pattern: datum ... Buy SYMBOL množství cena měna ... poslední_částka_v_CZK
        // Bereme POSLEDNÍ částku jako CZK hodnotu
        const parts = line.split(/\s+/);
        const buyIndex = parts.findIndex(p => p === 'Buy');

        if (buyIndex > 0 && buyIndex < parts.length - 1) {
          const symbol = parts[buyIndex + 1];
          const quantity = parseFloat(parts[buyIndex + 2]) || 0;
          const price = parseFloat(parts[buyIndex + 3]) || 0;
          const origCurrency = parts[buyIndex + 4] || 'USD';

          // Najdeme poslední částku (v CZK)
          let czkAmount = null;
          for (let j = parts.length - 1; j >= 0; j--) {
            const cleaned = parts[j].replace(/,/g, '');
            if (cleaned.match(/^-?\d+\.\d{2}$/)) {
              czkAmount = parseFloat(cleaned);
              break;
            }
          }

          if (czkAmount !== null) {
            const txKey = `${date}|${symbol}|Buy|${Math.abs(czkAmount).toFixed(
              2
            )}|${Math.abs(quantity).toFixed(4)}`;

            if (!processedKeys.has(txKey)) {
              processedKeys.add(txKey);

              transactions.push({
                date,
                id: symbol,
                quantity: Math.abs(quantity),
                price,
                currency: 'CZK',
                amount: czkAmount,
                ex_rate: 1,
                platform: 'IBKR',
                product_type: 'Stock',
                trans_type: 'Buy',
                fees: 0,
                notes: `IBKR Buy ${symbol} ${Math.abs(
                  quantity
                )}x @ ${price} ${origCurrency}`
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
      }

      // 2. SELL transakce - OPRAVENÝ PATTERN
      else if (line.includes(' Sell ')) {
        // Pattern podobný jako Buy
        const parts = line.split(/\s+/);
        const sellIndex = parts.findIndex(p => p === 'Sell');

        if (sellIndex > 0 && sellIndex < parts.length - 1) {
          const symbol = parts[sellIndex + 1];
          const quantity = parts[sellIndex + 2]
            ? Math.abs(parseFloat(parts[sellIndex + 2]))
            : 0;
          const price = parseFloat(parts[sellIndex + 3]) || 0;
          const origCurrency = parts[sellIndex + 4] || 'USD';

          // Najdeme poslední částku (v CZK)
          let czkAmount = null;
          for (let j = parts.length - 1; j >= 0; j--) {
            const cleaned = parts[j].replace(/,/g, '');
            if (cleaned.match(/^-?\d+\.\d{2}$/)) {
              czkAmount = parseFloat(cleaned);
              break;
            }
          }

          if (czkAmount !== null) {
            // Prodej = kladná částka
            const finalAmount = Math.abs(czkAmount);

            const txKey = `${date}|${symbol}|Sell|${finalAmount.toFixed(
              2
            )}|${Math.abs(quantity).toFixed(4)}`;

            if (!processedKeys.has(txKey)) {
              processedKeys.add(txKey);

              transactions.push({
                date,
                id: symbol,
                quantity: -Math.abs(quantity),
                price,
                currency: 'CZK',
                amount: finalAmount,
                ex_rate: 1,
                platform: 'IBKR',
                product_type: 'Stock',
                trans_type: 'Sell',
                fees: 0,
                notes: `IBKR Sell ${symbol} ${Math.abs(
                  quantity
                )}x @ ${price} ${origCurrency}`
              });
              sellCount++;
              console.log(
                `   ✅ Sell: ${date} ${symbol} ${Math.abs(
                  quantity
                )}x = ${finalAmount} CZK`
              );
            }
          }
        }
      }

      // 3. OSTATNÍ TRANSAKCE (Dividendy, Tax, Deposit, FX, Corporate Action)
      else {
        const contextLines = [];
        for (let j = i; j < Math.min(i + 6, lines.length); j++) {
          if (lines[j].match(/^20\d{2}-\d{2}-\d{2}/) && j > i) break;
          contextLines.push(lines[j]);
        }
        const fullContext = contextLines.join(' ');

        const transType = this.detectTransactionType(fullContext);
        if (!transType) continue;

        const symbol = this.extractSymbol(fullContext, transType);
        const netAmount = this.extractLastAmount(fullContext);

        if (netAmount === null) continue;

        const txKey = `${date}|${symbol}|${transType}|${Math.abs(
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
          id: symbol,
          quantity: this.getQuantityForType(transType),
          price: 1,
          currency: 'CZK',
          amount: finalAmount,
          ex_rate: 1,
          platform: 'IBKR',
          product_type: this.getProductTypeForTransType(transType),
          trans_type: transType,
          fees: 0,
          notes: `IBKR ${transType} ${symbol || ''}`.trim()
        });

        console.log(
          `   ✅ ${transType}: ${date} ${symbol || ''} = ${finalAmount} CZK`
        );
      }
    }

    console.log('\n📊 Souhrn IBKR PDF importu:');
    console.log(
      `   ✅ Buy: ${buyCount}, Sell: ${sellCount}, Dividendy: ${divCount}, Tax: ${taxCount}, FX: ${fxCount}, Deposit: ${depositCount}, Fee: ${feeCount}, Corp: ${corpCount}`
    );
    console.log(`   🔢 Celkem transakcí: ${transactions.length}`);
    console.log('\n' + '='.repeat(70) + '\n');

    return transactions;
  }

  fixBrokenDates(lines) {
    const fixedLines = [];
    let i = 0;

    while (i < lines.length) {
      const line = lines[i];
      const incompleteMatch = line.match(/^(20\d{2}-\d{2})-?$/);

      if (incompleteMatch && i + 1 < lines.length) {
        const nextLine = lines[i + 1];

        if (nextLine.match(/^\d{2}$/)) {
          if (i + 2 < lines.length) {
            const thirdLine = lines[i + 2];
            const fixed = `${incompleteMatch[1]}-${nextLine} ${thirdLine}`;
            fixedLines.push(fixed);
            i += 3;
            continue;
          }
        } else if (nextLine.match(/^\d{2}\s/)) {
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

    if (/Deposit/i.test(text) && /CZK/i.test(text)) {
      return 'Deposit';
    }

    if (
      /Dividend Accrual|Cash Dividend|Stock Dividend/i.test(text) &&
      !/Tax/i.test(text)
    ) {
      return 'Dividend';
    }

    if (
      /(Withholding Tax|Tax on Dividend|Tax Withheld)/i.test(text) ||
      /Tax.*Dividend/i.test(text)
    ) {
      return 'Tax';
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

    const isinMatch = text.match(
      /\b([A-Z][A-Z0-9.\-]{0,9})\s*\([A-Z]{2}[A-Z0-9]{8,10}\)/
    );
    if (isinMatch) return isinMatch[1];

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

  extractLastAmount(text) {
    const matches = [...text.matchAll(/-?\d{1,3}(?:,\d{3})*\.\d{2}/g)];
    if (!matches.length) return null;

    const last = matches[matches.length - 1][0];
    const normalized = last.replace(/,/g, '');
    return parseFloat(normalized);
  }

  getQuantityForType(transType) {
    if (transType === 'Dividend' || transType === 'Tax' || transType === 'Fee')
      return 0;
    if (transType === 'Deposit') return 0;
    if (transType === 'FX') return 0;
    if (transType === 'Corporate Action') return 0;
    return 0;
  }

  getProductTypeForTransType(transType) {
    const mapping = {
      Dividend: 'Stock',
      Tax: 'Stock',
      Fee: 'Stock',
      Deposit: 'Cash',
      Withdrawal: 'Cash',
      FX: 'FX',
      'Corporate Action': 'Stock',
      Buy: 'Stock',
      Sell: 'Stock'
    };
    return mapping[transType] || 'Stock';
  }

  parseCsv(rows) {
    console.log('CSV parsing není zatím implementováno');
    return [];
  }
}
