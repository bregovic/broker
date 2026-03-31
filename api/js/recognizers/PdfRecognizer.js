// /broker/js/recognizers/PdfRecognizer.js
import { BaseRecognizer } from './BaseRecognizer.js';

export default class PdfRecognizer extends BaseRecognizer {
  defineRules() {
    return [
      // Interactive Brokers (IBKR) - Activity Statement
      {
        provider: 'ibkr',
        patterns: [
          /Time Period:\s*\d{4}-\d{2}-\d{2}\s+to\s+\d{4}-\d{2}-\d{2}/i,              // hlavička s časovým obdobím
          /U\*\*\*\d+/i,                                                               // account pattern
          /Beginning Cash Balance.*Ending Cash Balance.*Change/i,                      // cash balance řádek
          /Statements\s+Flex\s+Queries\s+Other\s+Reports\s+Tax\s+Third-Party\s+Reports\s+Transaction\s+History/i, // menu footer
          /Date\s+Account\s+Description\s+Transaction Type\s+Symbol.*Commission\s+Net Amount/i, // table headers
          /All Rows/i                                                                  // konec tabulky
        ],
        required: 3  // stačí 3 z těchto vzorů pro rozpoznání
      },

      // Revolut TRADING account statement (PDF)
      {
        provider: 'revolut_trading',
        // CZ + EN klíčová slova, která se v trading PDF pravidelně vyskytují
        patterns: [
          /Account Statement/i,                                   // hlavička
          /USD Transactions|Transactions\s*USD/i,                // sekce transakcí
          /Cash top-?up/i,                                       // dobíjení
          /Custody fee/i,                                        // poplatek za úschovu
          /Trade\s*-\s*(Market|Limit)/i,                         // obchody
          /Dividend/i,                                           // dividendy
          /Transfer from Revolut Trading Ltd to Revolut Securities Europe UAB/i,
          /Revolut (Trading|Securities) (Ltd|Europe)/i
        ],
        required: 2
      },

      // Revolut CRYPTO account statement (PDF)
      {
        provider: 'revolut_crypto',
        patterns: [
          /Výpis z účtu s kryptomĕnami/i,                        // CZ hlavička
          /Crypto (Account )?Statement/i,                        // EN varianta
          /Revolut Digital Assets Europe Ltd/i,                  // společnost
          /Odměny ze stakingu|Staking rewards?/i,                // staking
          /Symbol\s+Typ\s+Množství|Symbol\s+Type\s+Amount/i      // tabulky transakcí
        ],
        required: 1
      },

      // Revolut COMMODITY statement (PDF) – XAU/XAG atd.
      {
        provider: 'revolut_commodity',
        patterns: [
          /Výpis v\s+(XAU|XAG|XPT|XPD)/i,                        // CZ hlavička
          /Smĕněno na\s+(XAU|XAG|XPT|XPD)/i,                     // detail transakce
          /Commodity (Account )?Statement|Commodity Exchange/i   // EN fallback
        ],
        required: 1
      },

      // Fio (pro jistotu nechávame i tady)
      {
        provider: 'fio',
        patterns: [
          /Fio banka/i,
          /Výpis operací|Výpis z účtu/i,
          /BCPP|Dividenda/i
        ],
        required: 2
      },

      // Coinbase PDF (kdyby někdy bylo v PDF)
      {
        provider: 'coinbase',
        patterns: [
          /Coinbase (Global|Europe)/i,
          /Transaction History Report|Transaction History/i
        ],
        required: 1
      }
    ];
  }
}