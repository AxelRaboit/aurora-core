import { afterEach, describe, it, expect } from "vitest";
import { defineComponent, h } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useDateFormat } from "./useDateFormat.js";

function mountWithComposable(locale = "fr") {
    let api;
    const Comp = defineComponent({
        setup() {
            api = useDateFormat();
            return () => h("div");
        },
    });
    mount(Comp, { global: { plugins: [createTestI18n({}, locale)] } });
    return api;
}

describe("useDateFormat", () => {
    it("formatDateNumeric returns placeholder for null", () => {
        const { formatDateNumeric } = mountWithComposable();
        expect(formatDateNumeric(null)).toBe("-");
        expect(formatDateNumeric(null, "N/A")).toBe("N/A");
    });

    it("formatDateNumeric returns placeholder for empty string", () => {
        const { formatDateNumeric } = mountWithComposable();
        expect(formatDateNumeric("")).toBe("-");
    });

    it("formatDateTimeNumeric returns placeholder for null", () => {
        const { formatDateTimeNumeric } = mountWithComposable();
        expect(formatDateTimeNumeric(null)).toBe("-");
    });

    it("formatDateNumeric formats a date string (FR locale → DD/MM/YYYY)", () => {
        const { formatDateNumeric } = mountWithComposable("fr");
        const result = formatDateNumeric("2024-03-15T00:00:00Z");
        // FR locale: DD/MM/YYYY
        expect(result).toMatch(/15\/03\/2024/);
    });

    it("formatDateShort formats a date without time", () => {
        const { formatDateShort } = mountWithComposable("fr");
        const result = formatDateShort("2024-03-15T00:00:00Z");
        expect(result).toContain("2024");
        expect(result).toContain("15");
    });

    it("formatDateShort, formatDateTime and formatDate return the placeholder for an empty value", () => {
        const { formatDateShort, formatDateTime, formatDate } =
            mountWithComposable("fr");
        expect(formatDateShort(null)).toBe("-");
        expect(formatDateTime("")).toBe("-");
        expect(formatDate(undefined, "")).toBe("");
    });

    it("formatDateTime leaves out the current year", () => {
        const { formatDateTime } = mountWithComposable("fr");
        const thisYear = new Date().getUTCFullYear();
        const result = formatDateTime(`${thisYear}-03-15T12:00:00Z`);
        expect(result).toContain("15");
        expect(result).not.toContain(String(thisYear));
    });

    it("formatDateTime writes any other year", () => {
        const { formatDateTime } = mountWithComposable("fr");
        expect(formatDateTime("2019-03-15T12:00:00Z")).toContain("2019");
    });

    it("formatMonthYear accepts a YYYY-MM prefix (FR)", () => {
        const { formatMonthYear } = mountWithComposable("fr");
        expect(formatMonthYear("2026-05")).toBe("Mai 2026");
    });

    it("formatMonthYear accepts a YYYY-MM prefix (EN)", () => {
        const { formatMonthYear } = mountWithComposable("en");
        expect(formatMonthYear("2026-05")).toBe("May 2026");
    });

    it("formatMonthYear accepts a full ISO string", () => {
        const { formatMonthYear } = mountWithComposable("fr");
        expect(formatMonthYear("2026-12-31T00:00:00Z")).toBe("Décembre 2026");
    });

    it("formatMonthYear returns placeholder for null/empty", () => {
        const { formatMonthYear } = mountWithComposable("fr");
        expect(formatMonthYear(null)).toBe("-");
        expect(formatMonthYear("")).toBe("-");
        expect(formatMonthYear(null, "N/A")).toBe("N/A");
    });

    describe("at the site's time", () => {
        // The layout hands the zone over in `window.__auroraConfig`; the list
        // must read 09:00 for a post scheduled for 09:00 in Paris, whatever
        // zone the machine running the test is in.
        afterEach(() => {
            delete window.__auroraConfig;
        });

        it("shows an instant in the site's zone", () => {
            window.__auroraConfig = { timezone: "Europe/Paris" };
            const { formatDateTimeNumeric } = mountWithComposable("fr");

            expect(
                formatDateTimeNumeric("2026-10-02T07:00:00+00:00"),
            ).toContain("09:00");
        });

        it("never moves a bare day", () => {
            window.__auroraConfig = { timezone: "America/New_York" };
            const { formatDateNumeric, formatMonthYear } =
                mountWithComposable("fr");

            expect(formatDateNumeric("2026-10-02")).toBe("02/10/2026");
            expect(formatMonthYear("2026-05")).toBe("Mai 2026");
        });
    });
});
