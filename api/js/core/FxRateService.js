// /broker/js/core/FxRateService.js
export default class FxRateService {
  constructor() {
    this.cache = new Map(); // key = `${date}|${CUR}`
    this.internalApiUrl = '/broker/rates.php?api=1';
  }

  // ====== NOVÉ: hromadné přednačtení kurzů ======
  async prefetch(pairs = [], useNearest = true) {
    // odfiltruj CZK a už v keši
    const need = [];
    for (const p of pairs) {
      if (!p) continue;
      const cur = (p.currency || '').toUpperCase();
      const d = (p.date || '').slice(0, 10);
      if (!cur || !d || cur === 'CZK') continue;
      const key = `${d}|${cur}`;
      if (!this.cache.has(key)) need.push({ date: d, currency: cur });
    }
    if (need.length === 0) return;

    try {
      const res = await fetch(this.internalApiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ requests: need, nearest: !!useNearest })
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      const rates = data?.rates || {};
      for (const [k, v] of Object.entries(rates)) {
        const num = Number(v);
        if (Number.isFinite(num) && num > 0) this.cache.set(k, num);
      }
    } catch (e) {
      console.warn('Bulk FX prefetch failed:', e);
    }
  }

  // ====== Jednotlivý kurz ======
  async getRate(date, currency) {
    const cur = (currency || '').toUpperCase();
    const d = (date || '').slice(0, 10);
    if (!cur || cur === 'CZK') return 1;

    const key = `${d}|${cur}`;
    if (this.cache.has(key)) return this.cache.get(key);

    // 1) Interní API – nearest ≤ date (rychlé)
    try {
      const url = `${this.internalApiUrl}&currency=${encodeURIComponent(cur)}&date=${encodeURIComponent(d)}&nearest=1`;
      const response = await fetch(url, { credentials: 'same-origin' });
      if (response.ok) {
        const data = await response.json();
        const rate = Number(data?.rate ?? data?.value ?? data?.czk);
        if (Number.isFinite(rate) && rate > 0) {
          this.cache.set(key, rate);
          return rate;
        }
      }
    } catch (e) {
      console.warn('Internal API (nearest) failed:', e);
    }

    // 2) Fallback – externí API (stejné jako dřív)
    try {
      const rate = await this.fetchExternalRate(d, cur);
      if (rate > 0) {
        this.cache.set(key, rate);
        return rate;
      }
    } catch (e) {
      console.warn('External API failed:', e);
    }

    // 3) Nouzově 1
    return 1;
  }

  // ====== Externí zdroje – stejné jako dřív ======
  async fetchExternalRate(date, currency) {
    // exchangerate.host
    try {
      const url = `https://api.exchangerate.host/${encodeURIComponent(date)}?base=${encodeURIComponent(currency)}&symbols=CZK`;
      const r = await fetch(url);
      const j = await r.json();
      const rate = Number(j?.rates?.CZK);
      if (Number.isFinite(rate) && rate > 0) return rate;
    } catch { }

    // frankfurter.app
    try {
      const url = `https://api.frankfurter.app/${encodeURIComponent(date)}?from=${encodeURIComponent(currency)}&to=CZK`;
      const r = await fetch(url);
      const j = await r.json();
      const rate = Number(j?.rates?.CZK);
      if (Number.isFinite(rate) && rate > 0) return rate;
    } catch { }

    throw new Error('All external APIs failed');
  }
}
