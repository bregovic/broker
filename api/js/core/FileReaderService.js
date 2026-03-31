// /broker/js/core/FileReaderService.js - OPRAVENÁ VERZE
export default class FileReaderService {
  constructor() {
    this.pdfjsLoaded = false;
  }

  /**
   * Read file content based on file type
   * @param {File} file 
   * @returns {Promise<{type: string, content: any}>}
   */
  async read(file) {
    const ext = this.getExtension(file.name);

    switch (ext) {
      case 'pdf':
        return { type: 'pdf', content: await this.readPdf(file) };

      case 'html':
      case 'htm':
        return { type: 'html', content: await this.readText(file) };

      case 'xml':
        return { type: 'xml', content: await this.readText(file) };

      case 'csv':
        return { type: 'csv', content: await this.readCsv(file) };

      case 'xlsx':
      case 'xls':
        return { type: 'xlsx', content: await this.readExcel(file) };

      default:
        throw new Error(`Nepodporovaný formát: ${ext}`);
    }
  }

  /**
   * Read text file
   */
  async readText(file) {
    const buffer = await file.arrayBuffer();
    return this.decodeWithFallback(buffer);
  }

  /**
   * Read CSV file
   */
  async readCsv(file) {
    const buffer = await file.arrayBuffer();
    const text = this.decodeWithFallback(buffer);
    const repaired = this.tryRepairMojibake(text);
    return this.parseCSV(repaired);
  }

  /**
   * Read Excel file with SheetJS
   */
  async readExcel(file) {
    const buffer = await file.arrayBuffer();
    const workbook = XLSX.read(buffer, { type: 'array', cellDates: true });

    // Return object with sheet names and their data (as JSON)
    const result = {};
    workbook.SheetNames.forEach(sheetName => {
      const worksheet = workbook.Sheets[sheetName];
      // header: 1 returns array of arrays (raw rows) which is better for parsing
      result[sheetName] = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: null });
    });

    return result;
  }

  async readPdf(file) {
    await this.ensurePdfJsLoaded();

    const buffer = await file.arrayBuffer();
    const pdf = await window.pdfjsLib.getDocument({ data: buffer }).promise;

    let allText = '';

    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
      const page = await pdf.getPage(pageNum);
      const textContent = await page.getTextContent();

      // Jednodušší přístup - každá položka na nový řádek
      for (const item of textContent.items) {
        if (item.str.trim()) {
          allText += item.str + '\n';
        }
      }
    }

    return allText;
  }

  /**
   * Ensure PDF.js library is loaded
   */
  async ensurePdfJsLoaded() {
    if (this.pdfjsLoaded) return;

    return new Promise((resolve, reject) => {
      // Zkus nejdřív novější verzi
      const versions = ['3.11.174', '2.16.105', '2.11.338'];
      let versionIndex = 0;

      const tryLoadVersion = () => {
        if (versionIndex >= versions.length) {
          reject(new Error('PDF.js se nepodařilo načíst'));
          return;
        }

        const version = versions[versionIndex];
        const script = document.createElement('script');
        script.src = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${version}/pdf.min.js`;

        script.onload = () => {
          try {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc =
              `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${version}/pdf.worker.min.js`;
            this.pdfjsLoaded = true;
            console.log(`✅ PDF.js ${version} načteno`);
            resolve();
          } catch (error) {
            console.warn(`Chyba při inicializaci PDF.js ${version}:`, error);
            versionIndex++;
            tryLoadVersion();
          }
        };

        script.onerror = () => {
          console.warn(`Nelze načíst PDF.js ${version}`);
          versionIndex++;
          tryLoadVersion();
        };

        document.head.appendChild(script);
      };

      tryLoadVersion();
    });
  }

  /**
   * Decode text with fallback encodings
   */
  decodeWithFallback(buffer) {
    const encodings = ['utf-8', 'windows-1250', 'iso-8859-2', 'cp1250', 'latin2'];
    let bestText = '';
    let bestScore = Infinity;

    for (const encoding of encodings) {
      try {
        const text = new TextDecoder(encoding, { fatal: false }).decode(buffer);
        const badChars = (text.match(/ï¿½|/g) || []).length;

        if (badChars < bestScore) {
          bestScore = badChars;
          bestText = text;
        }

        if (badChars === 0) break;
      } catch (e) {
        continue;
      }
    }

    return bestText || new TextDecoder('utf-8').decode(buffer);
  }

  /**
   * Try to repair mojibake characters
   */
  tryRepairMojibake(text) {
    if (!/[ÃÄÅ]/.test(text)) return text;

    const replacements = {
      'Ã¡': 'á', 'Ã„': 'Á', 'Ã¤': 'ä', 'Ã„': 'Ä',
      'Ã¨': 'è', 'Ã‰': 'É', 'Ã©': 'é', 'Ã­': 'í',
      'ÃŒ': 'Í', 'Ã³': 'ó', 'Ã"': 'Ó', 'Ã¶': 'ö',
      'Ã–': 'Ö', 'Ãº': 'ú', 'Ãš': 'Ú', 'Ã¼': 'ü',
      'Ãœ': 'Ü', 'Ã½': 'ý', 'Ã': 'Ý', 'Ä›': 'ě',
      'Äš': 'Ě', 'Å™': 'ř', 'Å˜': 'Ř', 'Å¡': 'š',
      'Å ': 'Š', 'Ä': 'č', 'ÄŒ': 'Č', 'Å¥': 'ť',
      'Å¤': 'Ť', 'Å¯': 'ů', 'Å®': 'Ů', 'Ä': 'ď',
      'ÄŽ': 'Ď', 'Åˆ': 'ň', 'Â°': '°', 'Â ': ' ', 'Â': ''
    };

    let result = text;
    for (const [bad, good] of Object.entries(replacements)) {
      result = result.split(bad).join(good);
    }

    return result;
  }

  /**
   * Parse CSV text
   */
  parseCSV(text) {
    const firstLine = text.split(/\r?\n/)[0] || '';
    const delimiters = [',', ';', '\t'];

    // Detect delimiter
    let delimiter = ',';
    let maxCount = 0;

    for (const delim of delimiters) {
      const count = (firstLine.match(new RegExp(`\\${delim}`, 'g')) || []).length;
      if (count > maxCount) {
        maxCount = count;
        delimiter = delim;
      }
    }

    // Parse CSV
    const rows = [];
    let currentRow = [];
    let currentCell = '';
    let inQuotes = false;

    for (let i = 0; i < text.length; i++) {
      const char = text[i];
      const nextChar = text[i + 1];

      if (inQuotes) {
        if (char === '"' && nextChar === '"') {
          currentCell += '"';
          i++;
        } else if (char === '"') {
          inQuotes = false;
        } else {
          currentCell += char;
        }
      } else {
        if (char === '"') {
          inQuotes = true;
        } else if (char === delimiter) {
          currentRow.push(currentCell.trim());
          currentCell = '';
        } else if (char === '\n') {
          currentRow.push(currentCell.trim());
          currentCell = '';
          if (currentRow.some(cell => cell !== '')) {
            rows.push(currentRow);
          }
          currentRow = [];
        } else if (char !== '\r') {
          currentCell += char;
        }
      }
    }

    // Add last row if needed
    if (currentCell.length || currentRow.length) {
      currentRow.push(currentCell.trim());
      if (currentRow.some(cell => cell !== '')) {
        rows.push(currentRow);
      }
    }

    return rows;
  }

  /**
   * Get file extension
   */
  getExtension(filename) {
    return (filename.split('.').pop() || '').toLowerCase();
  }
}