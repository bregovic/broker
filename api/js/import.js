// /broker/js/import.js - BUNDLED VERSION to fix missing core file errors
// Obsahuje: FxRateService, TransactionNormalizer, DatabaseWriter, FileReaderService, ImportOrchestrator

class FxRateService {
  constructor() {
    this.cache = new Map();
    this.internalApiUrl = 'rates.php?api=1'; // Relative path to be safe
  }
  async prefetch(pairs = [], useNearest = true) {
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
    } catch (e) { console.warn('Bulk FX prefetch failed:', e); }
  }
  async getRate(date, currency) {
    const cur = (currency || '').toUpperCase();
    const d = (date || '').slice(0, 10);
    if (!cur || cur === 'CZK') return 1;
    const key = `${d}|${cur}`;
    if (this.cache.has(key)) return this.cache.get(key);
    try {
      const response = await fetch(`${this.internalApiUrl}&currency=${encodeURIComponent(cur)}&date=${encodeURIComponent(d)}&nearest=1`, { credentials: 'same-origin' });
      if (response.ok) {
        const data = await response.json();
        const rate = Number(data?.rate ?? data?.value ?? data?.czk);
        if (Number.isFinite(rate) && rate > 0) {
          this.cache.set(key, rate);
          return rate;
        }
      }
    } catch (e) { }
    try {
      const rate = await this.fetchExternalRate(d, cur);
      if (rate > 0) { this.cache.set(key, rate); return rate; }
    } catch (e) { }
    return 1;
  }
  async fetchExternalRate(date, currency) {
    try {
      const r = await fetch(`https://api.exchangerate.host/${encodeURIComponent(date)}?base=${encodeURIComponent(currency)}&symbols=CZK`);
      const j = await r.json();
      const rate = Number(j?.rates?.CZK);
      if (Number.isFinite(rate) && rate > 0) return rate;
    } catch { }
    try {
      const r = await fetch(`https://api.frankfurter.app/${encodeURIComponent(date)}?from=${encodeURIComponent(currency)}&to=CZK`);
      const j = await r.json();
      const rate = Number(j?.rates?.CZK);
      if (Number.isFinite(rate) && rate > 0) return rate;
    } catch { }
    throw new Error('All external APIs failed');
  }
}

class TransactionNormalizer {
  constructor(fxRateService) {
    this.fxRateService = fxRateService;
  }
  async normalize(transactions) {
    const pairs = [];
    const seen = new Set();
    for (const tx of transactions || []) {
      const date = this.normalizeDate(tx.date);
      const cur = (tx.currency || 'CZK').toUpperCase();
      if (!date || cur === 'CZK') continue;
      const key = `${date}|${cur}`;
      if (!seen.has(key)) { seen.add(key); pairs.push({ date, currency: cur }); }
    }
    await this.fxRateService.prefetch(pairs, true);
    const out = [];
    for (const tx of transactions || []) {
      try {
        const norm = await this.normalizeTransaction(tx);
        out.push(norm);
      } catch (e) { console.error('Error normalizing transaction:', tx, e); }
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
    if (normalized.product_type === 'Crypto' && normalized.trans_type === 'Revenue') {
      normalized.ex_rate = 1; normalized.amount_czk = 0; normalized.fees = 0; return normalized;
    }
    normalized.ex_rate = (normalized.currency === 'CZK') ? 1 : await this.fxRateService.getRate(normalized.date, normalized.currency);
    normalized.amount_czk = (normalized.currency === 'CZK') ? normalized.amount_cur : Math.round(normalized.amount_cur * (normalized.ex_rate || 1) * 100) / 100;
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
      const feeRate = feeCurrency === 'CZK' ? 1 : await this.fxRateService.getRate(tx.date, feeCurrency);
      return Math.round(parseFloat(tx.__tmp.feeFiat) * feeRate * 100) / 100;
    } else if (tx.__tmp.feeCur != null) {
      const rate = tx.currency === 'CZK' ? 1 : (txExRate || 1);
      return Math.round(parseFloat(tx.__tmp.feeCur) * rate * 100) / 100;
    }
    return 0;
  }
}

class DatabaseWriter {
  constructor() {
    this.endpoint = 'import-handler.php';
  }
  async save(transactions, provider = 'unknown') {
    if (!transactions || transactions.length === 0) return { success: false, error: 'Žádné transakce k uložení' };
    try {
      const response = await fetch(this.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ provider: provider, transactions: transactions })
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return await response.json();
    } catch (error) {
      console.error('Database write error:', error);
      return { success: false, error: `Chyba při ukládání: ${error.message}` };
    }
  }
}

class FileReaderService {
  constructor() { this.pdfjsLoaded = false; }
  async read(file) {
    const ext = this.getExtension(file.name);
    switch (ext) {
      case 'pdf': return { type: 'pdf', content: await this.readPdf(file) };
      case 'html': case 'htm': return { type: 'html', content: await this.readText(file) };
      case 'xml': return { type: 'xml', content: await this.readText(file) };
      case 'csv': return { type: 'csv', content: await this.readCsv(file) };
      case 'xlsx': case 'xls': return { type: 'xlsx', content: await this.readExcel(file) };
      default: throw new Error(`Nepodporovaný formát: ${ext}`);
    }
  }
  async readText(file) { return this.decodeWithFallback(await file.arrayBuffer()); }
  async readCsv(file) {
    const text = this.decodeWithFallback(await file.arrayBuffer());
    return this.parseCSV(this.tryRepairMojibake(text));
  }
  async readExcel(file) {
    const buffer = await file.arrayBuffer();
    const workbook = XLSX.read(buffer, { type: 'array', cellDates: true });
    const result = {};
    workbook.SheetNames.forEach(sheetName => {
      result[sheetName] = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, defval: null });
    });
    return result;
  }
  async readPdf(file) {
    await this.ensurePdfJsLoaded();
    const pdf = await window.pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
    let allText = '';
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
      const page = await pdf.getPage(pageNum);
      const textContent = await page.getTextContent();
      for (const item of textContent.items) { if (item.str.trim()) allText += item.str + '\n'; }
    }
    return allText;
  }
  async ensurePdfJsLoaded() {
    if (this.pdfjsLoaded) return;
    return new Promise((resolve, reject) => {
      const versions = ['3.11.174', '2.16.105'];
      let versionIndex = 0;
      const tryLoadVersion = () => {
        if (versionIndex >= versions.length) { reject(new Error('PDF.js nešlo načíst')); return; }
        const version = versions[versionIndex];
        const script = document.createElement('script');
        script.src = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${version}/pdf.min.js`;
        script.onload = () => {
          try {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${version}/pdf.worker.min.js`;
            this.pdfjsLoaded = true;
            resolve();
          } catch (e) { versionIndex++; tryLoadVersion(); }
        };
        script.onerror = () => { versionIndex++; tryLoadVersion(); };
        document.head.appendChild(script);
      };
      tryLoadVersion();
    });
  }
  decodeWithFallback(buffer) {
    const encodings = ['utf-8', 'windows-1250', 'iso-8859-2', 'cp1250', 'latin2'];
    let bestText = '', bestScore = Infinity;
    for (const encoding of encodings) {
      try {
        const text = new TextDecoder(encoding, { fatal: false }).decode(buffer);
        const badChars = (text.match(/ï¿½|/g) || []).length;
        if (badChars < bestScore) { bestScore = badChars; bestText = text; }
        if (badChars === 0) break;
      } catch (e) { continue; }
    }
    return bestText || new TextDecoder('utf-8').decode(buffer);
  }
  tryRepairMojibake(text) {
    if (!/[ÃÄÅ]/.test(text)) return text;
    const replacements = {
      'Ã¡': 'á', 'Ã„': 'Á', 'Ã¤': 'ä', 'Ã„': 'Ä', 'Ã¨': 'è', 'Ã‰': 'É', 'Ã©': 'é', 'Ã­': 'í',
      'ÃŒ': 'Í', 'Ã³': 'ó', 'Ã"': 'Ó', 'Ã¶': 'ö', 'Ã–': 'Ö', 'Ãº': 'ú', 'Ãš': 'Ú', 'Ã¼': 'ü',
      'Ãœ': 'Ü', 'Ã½': 'ý', 'Ã': 'Ý', 'Ä›': 'ě', 'Äš': 'Ě', 'Å™': 'ř', 'Å˜': 'Ř', 'Å¡': 'š',
      'Å ': 'Š', 'Ä': 'č', 'ÄŒ': 'Č', 'Å¥': 'ť', 'Å¤': 'Ť', 'Å¯': 'ů', 'Å®': 'Ů', 'Ä': 'ď',
      'ÄŽ': 'Ď', 'Åˆ': 'ň', 'Â°': '°', 'Â ': ' ', 'Â': ''
    };
    let result = text;
    for (const [bad, good] of Object.entries(replacements)) result = result.split(bad).join(good);
    return result;
  }
  parseCSV(text) {
    const firstLine = text.split(/\r?\n/)[0] || '';
    const delimiters = [',', ';', '\t'];
    let delimiter = ',', maxCount = 0;
    for (const delim of delimiters) {
      const count = (firstLine.match(new RegExp(`\\${delim}`, 'g')) || []).length;
      if (count > maxCount) { maxCount = count; delimiter = delim; }
    }
    const rows = [];
    let currentRow = [], currentCell = '', inQuotes = false;
    for (let i = 0; i < text.length; i++) {
      const char = text[i];
      const nextChar = text[i + 1];
      if (inQuotes) {
        if (char === '"' && nextChar === '"') { currentCell += '"'; i++; }
        else if (char === '"') { inQuotes = false; }
        else { currentCell += char; }
      } else {
        if (char === '"') { inQuotes = true; }
        else if (char === delimiter) { currentRow.push(currentCell.trim()); currentCell = ''; }
        else if (char === '\n') {
          currentRow.push(currentCell.trim());
          if (currentRow.some(cell => cell !== '')) rows.push(currentRow);
          currentRow = []; currentCell = '';
        } else if (char !== '\r') { currentCell += char; }
      }
    }
    if (currentCell.length || currentRow.length) {
      currentRow.push(currentCell.trim());
      if (currentRow.some(cell => cell !== '')) rows.push(currentRow);
    }
    return rows;
  }
  getExtension(filename) { return (filename.split('.').pop() || '').toLowerCase(); }
}

class ImportOrchestrator {
  constructor() {
    this.fileReader = new FileReaderService();
    this.normalizer = new TransactionNormalizer(new FxRateService());
    this.dbWriter = new DatabaseWriter();
    this.fileInput = document.getElementById('import_file');
    this.uploadArea = document.getElementById('uploadArea');
    this.selectedFile = document.getElementById('selectedFile');
    this.submitBtn = document.getElementById('submitBtn');
    this.progressBar = document.getElementById('progressBar');
    this.progressFill = document.getElementById('progressFill');
    this.bindEvents();
  }
  bindEvents() {
    if (!this.fileInput || !this.uploadArea) return;
    this.fileInput.addEventListener('change', () => this.onFileSelected());
    this.uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); this.uploadArea.classList.add('dragover'); });
    this.uploadArea.addEventListener('dragleave', () => this.uploadArea.classList.remove('dragover'));
    this.uploadArea.addEventListener('drop', (e) => {
      e.preventDefault(); e.stopPropagation(); this.uploadArea.classList.remove('dragover');
      const files = e.dataTransfer?.files;
      if (files?.length) { this.fileInput.files = files; this.onFileSelected(); }
    });
    this.uploadArea.addEventListener('click', () => this.fileInput.click());
    const form = document.getElementById('importForm');
    if (form) form.addEventListener('submit', async (e) => { e.preventDefault(); await this.processFile(); });
  }
  onFileSelected() {
    const files = this.fileInput.files;
    if (!files || files.length === 0) return;
    const count = files.length;
    let totalSize = 0, validCount = 0;
    const supported = ['csv', 'xlsx', 'xls', 'pdf', 'html', 'htm', 'xml'];
    for (const file of files) { if (supported.includes(this.getFileExtension(file.name))) { validCount++; totalSize += file.size; } }
    if (validCount === 0) { this.showError('Žádný soubor není podporován.'); return; }
    const sizeMB = (totalSize / 1024 / 1024).toFixed(2);
    if (this.selectedFile) { this.selectedFile.style.display = 'block'; this.selectedFile.innerHTML = `<strong>Vybráno:</strong> ${count} (${sizeMB} MB)`; }
    if (this.submitBtn) this.submitBtn.disabled = false;
  }
  async processFile() {
    const files = Array.from(this.fileInput.files);
    if (!files || files.length === 0) { this.showError('Vyberte soubor.'); return; }
    this.setProcessing(true); this.showProgress(0);
    let successCount = 0, failCount = 0, skippedCount = 0;
    const errors = [];
    for (let i = 0; i < files.length; i++) {
      const file = files[i];
      const progressBase = (i / files.length) * 100;
      const progressStep = (1 / files.length) * 100;
      if (this.selectedFile) this.selectedFile.innerHTML = `Zpracovávám: <strong>${file.name}</strong> (${i + 1}/${files.length})`;
      try {
        this.showProgress(progressBase + (progressStep * 0.1));
        const fileData = await this.fileReader.read(file);
        this.showProgress(progressBase + (progressStep * 0.3));
        const fileType = fileData.type || this.getFileType(file);
        const vRec = new Date().getTime();
        const { default: RecognizerFactory } = await import(`./recognizers/RecognizerFactory.js?v=${vRec}`);
        const recognizer = await RecognizerFactory.create(fileType);
        const provider = await recognizer.identify(fileData.content, file.name);
        if (provider === 'unknown') throw new Error(`Nerozpoznaný formát: ${file.name}`);
        this.showProgress(progressBase + (progressStep * 0.5));
        const v = new Date().getTime();
        const { default: ParserFactory } = await import(`./parsers/ParserFactory.js?v=${v}`);
        const parser = await ParserFactory.create(provider);
        const rawTransactions = await parser.parse(fileData.content);
        if (!rawTransactions || rawTransactions.length === 0) { console.warn(`Skipping ${file.name}`); skippedCount++; continue; }
        this.showProgress(progressBase + (progressStep * 0.7));
        const normalizedTransactions = await this.normalizer.normalize(rawTransactions);
        this.showProgress(progressBase + (progressStep * 0.9));
        const result = await this.dbWriter.save(normalizedTransactions, provider);
        if (result.success) successCount++; else throw new Error(result.error || 'Chyba při ukládání');
      } catch (error) {
        console.error(`Error processing ${file.name}:`, error);
        failCount++; errors.push(`${file.name}: ${error.message}`);
      }
    }
    this.showProgress(100); this.setProcessing(false);
    if (failCount === 0 && skippedCount === 0) this.showSuccess(`Úspěšně importováno ${successCount} souborů.`);
    else if (successCount === 0 && skippedCount === 0) this.showError(`Import selhal. Chyby:\n${errors.join('\n')}`);
    else this.showMessage(`Dokončeno. Úspěšně: ${successCount}, Chyby: ${failCount}`, failCount > 0 ? 'danger' : 'warning');
    setTimeout(() => this.onFileSelected(), 3000);
  }
  getFileExtension(filename) { return (filename.split('.').pop() || '').toLowerCase(); }
  getFileType(file) {
    const ext = this.getFileExtension(file.name);
    if (ext === 'pdf') return 'pdf';
    if (ext === 'html' || ext === 'htm') return 'html';
    if (ext === 'xml') return 'xml';
    if (ext === 'csv' || ext === 'xlsx' || ext === 'xls') return 'csv';
    return 'unknown';
  }
  showProgress(percent) {
    if (this.progressBar) this.progressBar.style.display = 'block';
    if (this.progressFill) this.progressFill.style.width = `${percent}%`;
  }
  setProcessing(is) {
    if (this.submitBtn) { this.submitBtn.disabled = is; this.submitBtn.textContent = is ? '⏳ Zpracovávám…' : '🚀 Spustit import'; }
    if (!is && this.progressBar) setTimeout(() => { this.progressBar.style.display = 'none'; this.progressFill.style.width = '0%'; }, 800);
  }
  showMessage(msg, type) {
    const el = document.createElement('div');
    el.className = `alert alert-${type}`; el.textContent = msg;
    const form = document.getElementById('importForm');
    if (form?.parentNode) { form.parentNode.insertBefore(el, form); setTimeout(() => el.remove(), 6000); }
  }
  showError(msg) { this.showMessage(msg, 'danger'); }
  showSuccess(msg) { this.showMessage(msg, 'success'); }
}

document.addEventListener('DOMContentLoaded', () => new ImportOrchestrator());
