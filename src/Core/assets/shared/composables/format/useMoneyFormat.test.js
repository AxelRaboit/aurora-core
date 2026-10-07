import { describe, it, expect } from "vitest";
import { defineComponent, h } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useMoneyFormat } from "./useMoneyFormat.js";

function mountWithComposable(locale = "fr") {
    let api;
    const Comp = defineComponent({
        setup() {
            api = useMoneyFormat();
            return () => h("div");
        },
    });
    mount(Comp, { global: { plugins: [createTestI18n({}, locale)] } });
    return api;
}

// Intl separates thousands and the currency with narrow or plain no-break
// spaces depending on the runtime: compare on ordinary spaces.
const plain = (value) => value?.replace(/[  ]/g, " ");

describe("useMoneyFormat", () => {
    it("writes the amount the French way, whatever the browser's language", () => {
        const { formatMoney } = mountWithComposable("fr");
        expect(plain(formatMoney(75000))).toBe("750 €");
        expect(plain(formatMoney(1000000))).toBe("10 000 €");
    });

    it("shows the cents only when they carry something", () => {
        const { formatMoney } = mountWithComposable("fr");
        expect(plain(formatMoney(75050))).toBe("750,50 €");
    });

    it("follows the suite's language", () => {
        const { formatMoney } = mountWithComposable("en");
        expect(formatMoney(75000)).toBe("€750");
    });

    it("keeps the currency it is given", () => {
        const { formatMoney } = mountWithComposable("fr");
        expect(plain(formatMoney(49000, "CHF"))).toBe("490 CHF");
    });

    it("returns the placeholder for a missing amount", () => {
        const { formatMoney } = mountWithComposable("fr");
        expect(formatMoney(null)).toBeNull();
        expect(formatMoney(undefined, "EUR", "-")).toBe("-");
    });
});
