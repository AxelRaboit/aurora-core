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

    const result = arithmetic(cleaned);

    if (result === null) return cleaned;
    if (!allowNegative && result < 0) return cleaned;

    return result.toFixed(decimals);
}

/**
 * Evaluates the four operations, with parentheses, and nothing else.
 *
 * **It exists so the application can refuse `unsafe-eval`.** This used to be
 * `new Function("return (" + cleaned + ")")`, which was safe in the narrow
 * sense - the string had already been stripped to digits and operators, so
 * there was nothing to inject - and fatal in the wider one: a single
 * `new Function` anywhere forces `script-src 'unsafe-eval'` on every page, and
 * a policy carrying that has given back most of what it was written for.
 *
 * A recursive descent over three levels: a sum of terms, a term of factors, a
 * factor being a number, a parenthesised sum, or one of those negated. Returns
 * null for anything malformed, which the caller reads as "leave what the
 * person typed alone".
 *
 * @param {string} input already stripped to `[0-9.+\-*\/()\s]`
 * @returns {number|null}
 */
function arithmetic(input) {
    let at = 0;

    // Declarations rather than const arrows: the three below call each
    // other in a cycle, and only a declaration is hoisted past its callers.
    function skip() {
        while (input[at] === " ") at += 1;
    }

    function sum() {
        let value = term();
        if (value === null) return null;

        for (;;) {
            skip();
            const operator = input[at];
            if (operator !== "+" && operator !== "-") return value;

            at += 1;
            const right = term();
            if (right === null) return null;

            value = operator === "+" ? value + right : value - right;
        }
    }

    function term() {
        let value = factor();
        if (value === null) return null;

        for (;;) {
            skip();
            const operator = input[at];
            if (operator !== "*" && operator !== "/") return value;

            at += 1;
            const right = factor();
            if (right === null) return null;

            // Division by zero yields Infinity rather than throwing, and an
            // infinite amount is not an amount.
            value = operator === "*" ? value * right : value / right;
            if (!Number.isFinite(value)) return null;
        }
    }

    function factor() {
        skip();

        if (input[at] === "+") {
            at += 1;

            return factor();
        }

        if (input[at] === "-") {
            at += 1;
            const negated = factor();

            return negated === null ? null : -negated;
        }

        if (input[at] === "(") {
            at += 1;
            const inner = sum();
            skip();
            if (inner === null || input[at] !== ")") return null;
            at += 1;

            return inner;
        }

        const start = at;
        while (
            at < input.length &&
            (input[at] === "." || (input[at] >= "0" && input[at] <= "9"))
        ) {
            at += 1;
        }

        if (at === start) return null;

        const number = Number(input.slice(start, at));

        return Number.isFinite(number) ? number : null;
    }

    const value = sum();
    skip();

    // Trailing characters mean the expression was not understood in full -
    // "1 2" is not two, it is a typo.
    return at === input.length ? value : null;
}
