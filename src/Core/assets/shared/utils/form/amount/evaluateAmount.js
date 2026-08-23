/**
 * Evaluate a decimal amount expression and return the result as a fixed-
 * decimal string. Used by AppAmountInput (on blur) and by callers that
 * want to normalize an expression before submit (in case the user clicks
 * the submit button without blurring the field first - some browsers fire
 * click before blur in modals).
 *
 * Allowed expression chars: digits, `.`, operators `+ - * /`, parens, spaces.
 * Other chars are stripped before evaluation. Empty or already-numeric
 * input is normalized to the same fixed-decimal format. Non-finite or
 * negative (when not allowed) results fall back to the raw cleaned string.
 *
 * @param {string} raw - Raw user input (possibly an expression).
 * @param {object} [options]
 * @param {number} [options.decimals=2] - Output precision.
 * @param {boolean} [options.allowNegative=false]
 * @returns {string} formatted amount, or the cleaned raw string if evaluation fails
 */
export function evaluateAmount(
    raw,
    { decimals = 2, allowNegative = false } = {},
) {
    if (raw === null || raw === undefined) return "";
    const cleaned = String(raw)
        .replace(/[^0-9.+\-*/()\s]/g, "")
        .trim();
    if (cleaned === "") return "";

    const hasOperator = /[+\-*/]/.test(cleaned.slice(1));

    if (!hasOperator) {
        const n = Number(cleaned);
        if (!Number.isFinite(n)) return cleaned;
        if (!allowNegative && n < 0) return cleaned;
        return n.toFixed(decimals);
    }

    try {
        const result = new Function(`"use strict"; return (${cleaned});`)();
        if (typeof result !== "number" || !Number.isFinite(result))
            return cleaned;
        if (!allowNegative && result < 0) return cleaned;
        return result.toFixed(decimals);
    } catch {
        return cleaned;
    }
}
