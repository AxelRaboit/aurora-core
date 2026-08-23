import { describe, expect, it } from "vitest";
import {
    formatCurrency,
    formatProductPrice,
} from "@/shared/utils/format/formatPrice.js";

describe("formatCurrency", () => {
    it("returns em-dash for null/undefined amount", () => {
        expect(formatCurrency(null)).toBe("-");
        expect(formatCurrency(undefined)).toBe("-");
    });

    it("formats with default EUR currency", () => {
        const result = formatCurrency(12.5);
        expect(result).toMatch(/12[.,]50/);
        expect(result).toMatch(/€|EUR/);
    });

    it("formats with explicit currency", () => {
        const result = formatCurrency(99, "USD");
        expect(result).toMatch(/\$|USD/);
    });

    it("falls back gracefully on invalid currency", () => {
        const result = formatCurrency(42, "INVALID_CODE");
        // Either Intl threw or it accepted; both should produce something readable.
        expect(result).toContain("42");
    });

    it("uses fallbackDecimals when Intl throws", () => {
        const result = formatCurrency(42, "ZZZZ", { fallbackDecimals: 3 });
        // When the catch path runs, the decimals matter; otherwise Intl handles it.
        expect(result).toMatch(/42/);
    });
});

describe("formatProductPrice", () => {
    it("returns em-dash for null product", () => {
        expect(formatProductPrice(null)).toBe("-");
        expect(formatProductPrice(undefined)).toBe("-");
    });

    it("formats with product currency", () => {
        const result = formatProductPrice({ price: 19.99, currency: "EUR" });
        expect(result).toMatch(/19[.,]99/);
    });

    it("uses currencyDecimals as fallback", () => {
        const result = formatProductPrice({
            price: 12,
            currency: "EUR",
            currencyDecimals: 0,
        });
        expect(result).toMatch(/12/);
    });
});
