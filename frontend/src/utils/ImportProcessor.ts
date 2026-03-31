
import axios from 'axios';
import { type Transaction } from './parsers/BaseParser';
import Trading212Parser from './parsers/Trading212Parser';
import RevolutParser from './parsers/RevolutParser';
import CoinbaseParser from './parsers/CoinbaseParser';
import FioParser from './parsers/FioParser';
import IbkrParser from './parsers/IbkrParser';
import * as pdfjsLib from 'pdfjs-dist';
// @ts-ignore
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

// --- Services ---

class FxRateService {
    private cache = new Map<string, number>();
    private endpoint = '/investyx/rates.php?api=1';

    async getRate(date: string, currency: string): Promise<number> {
        const d = date.slice(0, 10);
        const cur = (currency || 'CZK').toUpperCase();
        if (cur === 'CZK') return 1;

        const key = `${d}|${cur}`;
        if (this.cache.has(key)) return this.cache.get(key)!;

        try {
            const res = await axios.post(this.endpoint, {
                requests: [{ date: d, currency: cur }],
                nearest: true
            });

            const rateVal = res.data?.rates?.[key] ?? res.data?.rate;
            const rate = Number(rateVal || 0);
            if (rate > 0) {
                this.cache.set(key, rate);
                return rate;
            }
        } catch (e) {
            console.error('FX fetch failed', e);
        }
        return 1;
    }
}

class TransactionNormalizer {
    private fx: FxRateService;
    constructor(fx: FxRateService) {
        this.fx = fx;
    }

    normalizeDate(dateInput: string): string {
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

    async normalize(rawTx: Transaction): Promise<Transaction> {
        const cur = (rawTx.currency || 'CZK').toUpperCase();
        const date = this.normalizeDate(rawTx.date);

        const normalized: Transaction = {
            ...rawTx,
            date: date,
            currency: cur,
            amount: Math.abs(Number(rawTx.amount) || 0),
            amount_cur: Number(rawTx.amount_cur) || 0,
        };

        const rate = await this.fx.getRate(date, cur);
        (normalized as any).ex_rate = rate;

        let amountCzk = 0;
        if (cur === 'CZK') {
            amountCzk = normalized.amount_cur || 0;
        } else {
            amountCzk = (normalized.amount_cur || 0) * rate;
        }
        (normalized as any).amount_czk = Math.round(amountCzk * 100) / 100;

        return normalized;
    }
}

const readPdfText = async (file: File): Promise<string> => {
    const arrayBuffer = await file.arrayBuffer();
    const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer });
    const pdf = await loadingTask.promise;
    let fullText = '';

    for (let i = 1; i <= pdf.numPages; i++) {
        const page = await pdf.getPage(i);
        const content = await page.getTextContent();
        // Separator space to avoid merging words
        const strings = content.items.map((item: any) => item.str);
        fullText += strings.join(' ') + '\n';
    }
    return fullText;
};

// --- Orchestrator ---

export const processImport = async (file: File, log: (msg: string) => void): Promise<any> => {
    log(`Reading file: ${file.name}`);

    let text = '';
    const isPdf = file.name.toLowerCase().endsWith('.pdf') || file.type === 'application/pdf';

    if (isPdf) {
        log('Detected PDF format. Parsing text...');
        try {
            text = await readPdfText(file);
        } catch (e) {
            log('Error reading PDF: ' + e);
            throw new Error('Failed to read PDF file.');
        }
    } else {
        text = await file.text();
    }

    // Detect logic
    let provider = 'unknown';

    // Helper: Check first 20 lines for CSV headers signature (robust content check)
    const lines = text.split(/\r?\n/).slice(0, 20).map(l => l.toLowerCase());
    const hasHeaders = (keywords: string[]) => lines.some(l => keywords.every(k => l.includes(k)));

    if (text.includes('Trading 212') || hasHeaders(['action', 'time', 'isin'])) provider = 't212';
    else if (
        text.includes('Cash top-up') ||
        text.includes('Cash withdrawal') ||
        hasHeaders(['type', 'product', 'started date']) ||
        hasHeaders(['type', 'ticker', 'quantity']) ||
        hasHeaders(['type', 'symbol', 'amount']) ||
        hasHeaders(['type', 'commodity']) ||
        (text.includes('Revolut') && (text.includes('Statement') || text.includes('Výpis'))) // Safer check for PDF header
    ) provider = 'revolut';
    else if (text.includes('Fio banka') || text.includes('FIO BANKA') || hasHeaders(['id transakce'])) provider = 'fio';
    else if (
        text.toLowerCase().includes('transaction history report') ||
        hasHeaders(['timestamp', 'transaction type', 'asset'])
    ) provider = 'coinbase';
    else if (text.includes('Interactive Brokers') || text.includes('Activity Statement')) provider = 'ibkr';

    if (provider === 'unknown') {
        throw new Error('Nepodařilo se rozpoznat formát souboru (neznámý broker).');
    }
    log(`Detected provider: ${provider}`);

    // Parse
    let transactions: Transaction[] = [];
    if (provider === 't212') {
        const parser = new Trading212Parser();
        const rows = parser.parseCSV(text);
        transactions = await parser.parse(rows);
    } else if (provider === 'revolut') {
        const parser = new RevolutParser();
        transactions = await parser.parse(text);
    } else if (provider === 'coinbase') {
        const parser = new CoinbaseParser();
        transactions = await parser.parse(text);
    } else if (provider === 'fio') {
        const parser = new FioParser();
        transactions = await parser.parse(text);
    } else if (provider === 'ibkr') {
        const parser = new IbkrParser();
        transactions = await parser.parse(text);
    } else {
        throw new Error(`Parser pro ${provider} není v Reactu implementován. Použijte starý web.`);
    }


    log(`Parsed ${transactions.length} transactions.`);

    // Normalize
    log('Normalizing and fetching FX rates...');
    const fx = new FxRateService();
    const normalizer = new TransactionNormalizer(fx);

    const normalized = [];
    for (const tx of transactions) {
        normalized.push(await normalizer.normalize(tx));
    }

    // Save
    log('Saving to database...');
    const apiUrl = '/investyx/import-handler.php';

    const res = await axios.post(apiUrl, {
        provider: provider,
        transactions: normalized
    }, {
        headers: {
            'Content-Type': 'application/json'
        }
    });

    if (res.data && res.data.success) {
        log('✅ Import successful!');
        return res.data;
    } else {
        throw new Error(res.data?.error || 'Unknown server error');
    }
};
