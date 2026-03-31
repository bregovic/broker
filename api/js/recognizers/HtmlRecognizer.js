import { BaseRecognizer } from './BaseRecognizer.js';

export default class HtmlRecognizer extends BaseRecognizer {
  defineRules() {
    return [
      {
        provider: 'coinbase',
        patterns: [
          /Coinbase/i,
          /<tr[^>]*>.*?Transaction History.*?<\/tr>/i,
          /Buy|Sell|Convert|Reward/i
        ],
        required: 2
      }
    ];
  }
}
