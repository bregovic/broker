// /broker/js/parsers/ParserFactory.js
export default class ParserFactory {
  static async create(provider) {
    // Cache busting parameter to force browser to load fresh files
    const v = '?v=' + new Date().getTime();

    switch (provider) {
      case 'ibkr': {
        const mod = await import(`./ibkr/IbkrParser.js${v}`);
        return new mod.default();
      }
      case 'revolut_trading': {
        // Use V4 minimal to test loading
        const mod = await import(`./revolut/RevolutTradingParserV4.js${v}`);
        return new mod.default();
      }
      case 'revolut_crypto': {
        const mod = await import(`./revolut/RevolutCryptoParser.js${v}`);
        return new mod.default();
      }
      case 'revolut_commodity': {
        const mod = await import(`./revolut/RevolutCommodityParser.js${v}`);
        return new mod.default();
      }
      case 'fio': {
        const mod = await import(`./fio/FioParser.js${v}`);
        return new mod.default();
      }
      case 'coinbase': {
        const mod = await import(`./coinbase/CoinbaseParser.js${v}`);
        return new mod.default();
      }
      case 'etoro': {
        const mod = await import(`./etoro/EtoroParser.js${v}`);
        return new mod.default();
      }
      case 'trading212': {
        const mod = await import(`./trading212/Trading212Parser.js${v}`);
        return new mod.default();
      }
      default:
        throw new Error(`Neznámý poskytovatel: ${provider}`);
    }
  }
}