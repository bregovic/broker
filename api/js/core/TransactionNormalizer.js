// /broker/js/core/TransactionNormalizer.js
export default class TransactionNormalizer {
  constructor(fxRateService) {
    this.fxRateService = fxRateService;
  }

  // ====== Hlavní normalizace s prefetch ======
  async normalize(transactions) {
    // 0) Přednačti kurzy pro unikátní (date, currency) ≠ CZK
    const pairs = [];
    const seen = new Set();
    for (const tx of transactions || []) {
      const date = this.normalizeDate(tx.date);
      const cur = (tx.currency || 'CZK').toUpperCase();
      if (!date || cur === 'CZK') continue;
      const key = `${date}|${cur}`;
      if (!seen.has(key)) {
        seen.add(key);
        pairs.push({ date, currency: cur });
      }
    }
    await this.fxRateService.prefetch(pairs, true);

    // 1) Pak normalizuj jednotlivé transakce
    const out = [];
    for (const tx of transactions || []) {
      try {
        const norm = await this.normalizeTransaction(tx);
        out.push(norm);
      } catch (e) {
        console.error('Error normalizing transaction:', tx, e);
      }
    }
    return out;
  }

  async normalizeTransaction(tx) {
    const normalized = {
      date: this.normalizeDate(tx.date),
      id: (tx.id || 'UNKNOWN').toUpperCase().trim(),
      amount: Math.abs(parseFloat(tx.amount) || 0),
      price: tx.price != null ? parseFloat(tx.price) : null,
      amount_cur: parseFloat(tx.amount_cur) || 0,
      currency: (tx.currency || 'CZK').toUpperCase().trim(),
      platform: tx.platform || '',
      product_type: tx.product_type || 'Stock',
      trans_type: tx.trans_type || 'Other',
      notes: tx.notes || '',
      fees: 0,
      isin: tx.isin || null,
      company_name: tx.company_name || null
    };

    // Crypto revenue – bez kurzů a CZK částky
    if (normalized.product_type === 'Crypto' && normalized.trans_type === 'Revenue') {
      normalized.ex_rate = 1;
      normalized.amount_czk = 0;
      normalized.fees = 0;
      return normalized;
    }

    // kurz
    normalized.ex_rate = (normalized.currency === 'CZK')
      ? 1
      : await this.fxRateService.getRate(normalized.date, normalized.currency);

    // přepočet
    normalized.amount_czk = (normalized.currency === 'CZK')
      ? normalized.amount_cur
      : Math.round(normalized.amount_cur * (normalized.ex_rate || 1) * 100) / 100;

    // poplatky
    if (tx.__tmp) {
      normalized.fees = await this.calculateFees(tx, normalized.ex_rate);
    } else if (tx.fees) {
      normalized.fees = parseFloat(tx.fees) || 0;
    }

    return normalized;
  }

  normalizeDate(dateInput) {
    if (!dateInput) return '';
    if (/^\d{4}-\d{2}-\d{2}/.test(dateInput)) return String(dateInput).split('T')[0];
    const cs = String(dateInput).match(/(\d{1,2})\.?\s*(\d{1,2})\.?\s*(\d{4})/);
    if (cs) {
      const d = cs[1].padStart(2, '0');
      const m = cs[2].padStart(2, '0');
      const y = cs[3];
      return `${y}-${m}-${d}`;
    }
    try {
      const dt = new Date(dateInput);
      if (!isNaN(dt.getTime())) return dt.toISOString().slice(0, 10);
    } catch { }
    return '';
  }

  async calculateFees(tx, txExRate) {
    if (!tx.__tmp) return 0;

    if (tx.__tmp.feeFiat != null) {
      const feeCurrency = tx.__tmp.feeFiatCurrency || tx.currency || 'CZK';
      const feeRate = feeCurrency === 'CZK' ? 1
        : await this.fxRateService.getRate(tx.date, feeCurrency);
      return Math.round(parseFloat(tx.__tmp.feeFiat) * feeRate * 100) / 100;
    } else if (tx.__tmp.feeCur != null) {
      const rate = tx.currency === 'CZK' ? 1 : (txExRate || 1);
      return Math.round(parseFloat(tx.__tmp.feeCur) * rate * 100) / 100;
    }
    return 0;
  }
}
