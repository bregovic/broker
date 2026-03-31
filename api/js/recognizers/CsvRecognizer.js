import { BaseRecognizer } from './BaseRecognizer.js';

export default class CsvRecognizer extends BaseRecognizer {
  async identify(content, filename = '') {
    if (!Array.isArray(content) || content.length < 2) {
      return 'unknown';
    }

    // 0. Check strict full header match for Trading 212
    if (content[0]) {
      const line1 = content[0].map(c => String(c || '').trim()).join(',');
      if (line1.startsWith('Action,Time,ISIN,Ticker,Name,Notes,ID,No. of shares,Price / share')) {
        return 'trading212';
      }
    }

    const headers = content[0].map(h => (h || '').toString().trim().toLowerCase());

    // Check for specific column combinations
    const rules = [
      {
        provider: 'revolut_trading',
        requiredHeaders: ['ticker', 'type', 'quantity'],
        optionalHeaders: ['price per share', 'total amount']
      },
      {
        provider: 'revolut_crypto',
        requiredHeaders: ['symbol', 'type'],
        optionalHeaders: ['quantity', 'amount', 'fee']
      },
      {
        provider: 'revolut_commodity',
        requiredHeaders: ['commodity'],
        optionalHeaders: ['underlying', 'value']
      },
      {
        provider: 'trading212',
        requiredHeaders: ['action', 'time', 'isin'],
        optionalHeaders: ['ticker', 'name', 'no. of shares']
      }
    ];

    for (const rule of rules) {
      const hasRequired = rule.requiredHeaders.every(h => headers.includes(h));
      const hasOptional = rule.optionalHeaders ?
        rule.optionalHeaders.some(h => headers.includes(h)) : true;

      if (hasRequired && hasOptional) {
        return rule.provider;
      }
    }

    // Check by filename patterns
    const filenameRules = [
      { pattern: /revolut.*trading/i, provider: 'revolut_trading' },
      { pattern: /revolut.*crypto/i, provider: 'revolut_crypto' },
      { pattern: /revolut.*commodity/i, provider: 'revolut_commodity' },
      { pattern: /fio/i, provider: 'fio' },
      { pattern: /trade212|trading212|212/i, provider: 'trading212' }
    ];

    for (const rule of filenameRules) {
      if (rule.pattern.test(filename)) {
        return rule.provider;
      }
    }

    return 'unknown';
  }
}
