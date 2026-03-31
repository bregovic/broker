// /broker/js/recognizers/RecognizerFactory.js
export default class RecognizerFactory {
  static async create(fileType) {
    const v = '?v=' + new Date().getTime();
    switch (fileType) {
      case 'pdf': {
        const mod = await import(`./PdfRecognizer.js${v}`);
        return new mod.default();
      }
      case 'csv': {
        const mod = await import(`./CsvRecognizer.js${v}`);
        return new mod.default();
      }
      case 'html': {
        const mod = await import(`./HtmlRecognizer.js${v}`);
        return new mod.default();
      }
      case 'xml': {
        const mod = await import(`./XmlRecognizer.js${v}`);
        return new mod.default();
      }
      case 'xlsx':
      case 'xls': {
        const mod = await import(`./SheetRecognizer.js${v}`);
        return new mod.default();
      }
      default:
        throw new Error(`Neznámý typ souboru: ${fileType}`);
    }
  }
}
