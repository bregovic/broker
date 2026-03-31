import { BaseRecognizer } from './BaseRecognizer.js';

export default class XmlRecognizer extends BaseRecognizer {
  defineRules() {
    return [
      {
        provider: 'cnb',
        patterns: [
          /<kurzy_tabulka/i,
          /kurzy_devizoveho_trhu/i,
          /<radek kod="/i
        ],
        required: 2
      }
    ];
  }
}
