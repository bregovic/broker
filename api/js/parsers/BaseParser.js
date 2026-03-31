// /broker/js/parsers/BaseParser.js
export default class BaseParser {
  /**
   * Parse content and return transactions
   * Must be overridden by specific parsers
   */
  async parse(content) {
    throw new Error('Parse method must be implemented');
  }

  /**
   * Parse number from string
   */
  parseNumber(str) {
    if (str == null) return null;

    let value = String(str).trim()
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

  /**
   * Convert Czech date to ISO format
   */
  csDateToISO(dateStr) {
    const match = String(dateStr).match(/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/);
    if (!match) return '';

    const day = match[1].padStart(2, '0');
    const month = match[2].padStart(2, '0');
    const year = match[3];

    return `${year}-${month}-${day}`;
  }

  /**
   * Convert English date to ISO format
   */
  enDateToISO(dateStr) {
    const monthMap = {
      Jan: 1, Feb: 2, Mar: 3, Apr: 4, May: 5, Jun: 6,
      Jul: 7, Aug: 8, Sep: 9, Oct: 10, Nov: 11, Dec: 12
    };

    const match = String(dateStr).match(/(\d{2})\s([A-Za-z]{3})\s(\d{4})/);
    if (!match) return '';

    const day = match[1];
    const month = String(monthMap[match[2]] || 1).padStart(2, '0');
    const year = match[3];

    return `${year}-${month}-${day}`;
  }

  /**
   * Extract currency and number from value
   */
  extractCurrencyAndNumber(value) {
    if (value == null) return { num: null, currency: null };

    let str = String(value).trim();

    // Extract currency code
    const currencyMatch = str.match(/\b(AUD|CAD|CHF|CZK|DKK|EUR|GBP|HUF|JPY|NOK|PLN|SEK|USD|CNY)\b/i);
    const currency = currencyMatch ? currencyMatch[1].toUpperCase() : null;

    // Remove currency and parse number
    str = str.replace(/\b[A-Z]{3}\b/gi, '').replace(/\u00a0| /g, '');

    const hasDot = str.includes('.');
    const hasComma = str.includes(',');

    if (hasComma && hasDot) {
      const lastDot = str.lastIndexOf('.');
      const lastComma = str.lastIndexOf(',');
      if (lastComma > lastDot) {
        str = str.replace(/\./g, '').replace(',', '.');
      } else {
        str = str.replace(/,/g, '');
      }
    } else if (hasComma && !hasDot) {
      str = str.replace(',', '.');
    }

    str = str.replace(/[^0-9.\-]/g, '');
    const num = str === '' ? null : Number(str);

    return {
      num: isNaN(num) ? null : num,
      currency
    };
  }
}