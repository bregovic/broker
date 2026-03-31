// /broker/js/recognizers/BaseRecognizer.js
export class BaseRecognizer {
  constructor() {
    this.rules = this.defineRules();
  }

  /**
   * Define recognition rules - must be overridden
   */
  defineRules() {
    return [];
  }

  /**
   * Identify provider from content
   */
  async identify(content, filename = '') {
    for (const rule of this.rules) {
      if (this.matchRule(rule, content, filename)) {
        return rule.provider;
      }
    }
    return 'unknown';
  }

  /**
   * Check if rule matches content
   */
  matchRule(rule, content, filename) {
    // Check filename patterns
    if (rule.filenamePatterns) {
      const matches = rule.filenamePatterns.some(pattern => 
        pattern.test(filename)
      );
      if (matches) return true;
    }

    // Check content patterns
    if (rule.patterns) {
      const requiredMatches = rule.required || 1;
      let matchCount = 0;
      
      for (const pattern of rule.patterns) {
        if (pattern.test(content)) {
          matchCount++;
          if (matchCount >= requiredMatches) {
            return true;
          }
        }
      }
    }

    return false;
  }
}
