// /broker/js/recognizers/SheetRecognizer.js
import { BaseRecognizer } from './BaseRecognizer.js';

export default class SheetRecognizer extends BaseRecognizer {
    defineRules() {
        return [
            // eToro Account Statement (XLSX)
            {
                provider: 'etoro',
                patterns: [
                    /eToro (Group|Europe|UK)/i,
                    /Account Statement/i,
                    /Closed Positions/i,
                    /Transactions Report/i
                ],
                // eToro Excel usually has specific sheet names or headers in the first few rows
                checkContent: (sheets) => {
                    // Check all sheets
                    for (const sheetName in sheets) {
                        const rows = sheets[sheetName];
                        if (!rows || rows.length === 0) continue;

                        // Convert first few rows to string to check for patterns
                        const headerText = JSON.stringify(rows.slice(0, 20));

                        if (/eToro/i.test(headerText) && /Account Statement/i.test(headerText)) {
                            return true;
                        }

                        // Check for specific eToro columns in "Closed Positions"
                        const hasPositionId = rows.some(row =>
                            Array.isArray(row) && row.some(cell => String(cell).includes('Position ID'))
                        );
                        const hasAction = rows.some(row =>
                            Array.isArray(row) && row.some(cell => String(cell).includes('Action'))
                        );

                        if (hasPositionId && hasAction) return true;
                    }
                    return false;
                }
            },

            // Trading 212 (CSV/XLSX)
            {
                provider: 'trading212',
                patterns: [
                    /Trading 212/i,
                    /Transaction ID/i,
                    /Financial Instrument/i,
                    /DeGiro/i // Just in case, but T212 is main target here
                ],
                checkContent: (sheets) => {
                    for (const sheetName in sheets) {
                        const rows = sheets[sheetName];
                        if (!rows || rows.length === 0) continue;

                        // T212 exports usually have headers in the first row
                        const firstRow = rows[0];
                        if (Array.isArray(firstRow)) {
                            const headerStr = firstRow.join(' ');
                            if (/Transaction ID/i.test(headerStr) && /Financial Instrument/i.test(headerStr)) {
                                return true;
                            }
                        }
                    }
                    return false;
                }
            }
        ];
    }

    /**
     * Override identify to handle structured sheet data
     */
    identify(content, filename) {
        // Content is { Sheet1: [[row1], [row2]], ... }

        for (const rule of this.rules) {
            if (rule.checkContent) {
                if (rule.checkContent(content)) {
                    return rule.provider;
                }
            }
        }

        return 'unknown';
    }
}
