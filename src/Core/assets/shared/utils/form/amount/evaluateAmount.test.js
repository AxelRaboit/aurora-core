import { describe, it, expect } from "vitest";
import { evaluateAmount } from "./evaluateAmount.js";

/**
 * The evaluator exists so the application can refuse `unsafe-eval`.
 *
 * It replaced a `new Function(...)` that was safe in the narrow sense - the
 * string had already been stripped to digits and operators - and fatal in the
 * wider one: one `new Function` anywhere forces `script-src 'unsafe-eval'` on
 * every page, and a policy carrying that has given back most of what it was
 * written for.
 *
 * So the point of these cases is not arithmetic, it is that the replacement
 * behaves like what it replaced, including when it refuses.
 */
describe("evaluateAmount", () => {
    it.each([
        ["12", "12.00"],
        ["12+3", "15.00"],
        ["2*3.5", "7.00"],
        ["(1+2)*4", "12.00"],
        ["10/4", "2.50"],
        ["  8 + 2  ", "10.00"],
        ["2*(3+(4-1))", "12.00"],
        ["-3+5", "2.00"],
    ])("evaluates %s", (input, expected) => {
        expect(evaluateAmount(input)).toBe(expected);
    });

    /**
     * Everything it cannot read comes back untouched, which is what the field
     * needs: a person mid-typing must not have their input rewritten.
     */
    it.each([
        ["1 2", "1 2"],
        ["((1+2)", "((1+2)"],
        ["1+", "1+"],
        ["*3", "*3"],
        ["10/0", "10/0"],
    ])("leaves %s alone", (input, expected) => {
        expect(evaluateAmount(input)).toBe(expected);
    });

    it("refuses a negative result unless asked", () => {
        expect(evaluateAmount("3-10")).toBe("3-10");
        expect(evaluateAmount("3-10", { allowNegative: true })).toBe("-7.00");
    });

    it("strips what is not arithmetic before reading it", () => {
        // The field accepts a pasted "1 200,50 €"; the comma and the symbol go
        // before anything is evaluated.
        expect(evaluateAmount("12abc+3")).toBe("15.00");
    });

    it("honours the requested precision", () => {
        expect(evaluateAmount("10/3", { decimals: 4 })).toBe("3.3333");
    });
});
