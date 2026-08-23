import { describe, it, expect, vi } from "vitest";
import { ref } from "vue";
import { useRelativeTime } from "./useRelativeTime.js";

// onBeforeUnmount needs a component context - stub it for plain unit tests.
vi.mock("vue", async () => {
    const actual = await vi.importActual("vue");
    return { ...actual, onBeforeUnmount: () => {} };
});

// Force the i18n locale to a known value so the snapshot strings are stable.
vi.mock("vue-i18n", () => ({
    useI18n: () => ({ locale: ref("en") }),
}));

describe("useRelativeTime", () => {
    it("returns an empty string when the date is null", () => {
        const date = ref(null);
        const { relative } = useRelativeTime(date);

        expect(relative.value).toBe("");
    });

    it("formats a recent past date in seconds", () => {
        const date = ref(new Date(Date.now() - 3_000));
        const { relative } = useRelativeTime(date);

        // Intl.RelativeTimeFormat with numeric:"auto" produces strings like
        // "3 seconds ago" - we only assert the shape since exact wording is
        // locale/Node-version dependent.
        expect(relative.value).toMatch(/seconds? ago|now/);
    });

    it("formats a date a few minutes ago", () => {
        const date = ref(new Date(Date.now() - 5 * 60_000));
        const { relative } = useRelativeTime(date);

        expect(relative.value).toMatch(/5 minutes ago/);
    });

    it("formats a date a few hours ago", () => {
        const date = ref(new Date(Date.now() - 3 * 3_600_000));
        const { relative } = useRelativeTime(date);

        expect(relative.value).toMatch(/3 hours ago/);
    });

    it("formats a date a few days ago", () => {
        const date = ref(new Date(Date.now() - 2 * 86_400_000));
        const { relative } = useRelativeTime(date);

        expect(relative.value).toMatch(/2 days ago/);
    });
});
