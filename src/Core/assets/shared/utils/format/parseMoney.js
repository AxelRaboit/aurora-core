/**
 * Parse a user-typed or OCR-extracted monetary string into integer cents.
 *
 * Handles all common European and international separator conventions:
 *   - dot  as decimal  : "19.90"      → 1990
 *   - comma as decimal : "5,00"       → 500
 *   - dot  as thousands, comma as decimal : "1.200,00"   → 120000
 *   - comma as thousands, dot  as decimal : "2,290.00"   → 229000
 *   - same separator for both             : "1.200.00"   → 120000
 *   - thousands only, no decimal          : "1.000"      → 100000
 *
 * Rule: a separator followed by 1-2 digits at the END of the string is the
 * decimal separator; a separator followed by 3 digits is a thousands separator.
 *
 * @param {string} raw
 * @returns {number|null} integer cents, or null if unparseable
 */
export function parseMoney(raw) {
    if (!raw && raw !== 0) return null;

    const str = raw.toString().trim();
    if (str === "") return null;

    // Match the last separator + its trailing digits to detect decimal position.
    const decimalMatch = str.match(/[.,](\d{1,2})$/);

    let normalized;
    if (decimalMatch) {
        const decimalSep = str[str.length - decimalMatch[1].length - 1];
        // Everything before the decimal separator: strip all separators (thousands)
        const integerPart = str
            .slice(0, str.length - decimalMatch[1].length - 1)
            .replace(/[.,]/g, "");
        normalized = `${integerPart}.${decimalMatch[1]}`;
    } else {
        // No decimal part: strip all separators (pure thousands)
        normalized = str.replace(/[.,]/g, "");
    }

    const num = Number(normalized);
    if (Number.isNaN(num)) return null;

    return Math.round(num * 100);
}
