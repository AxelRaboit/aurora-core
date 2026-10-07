import { describe, it, expect } from "vitest";
import { defineComponent, h } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useFileSize } from "./useFileSize.js";

function mountWithComposable(locale = "fr") {
    let api;
    const Comp = defineComponent({
        setup() {
            api = useFileSize();
            return () => h("div");
        },
    });
    mount(Comp, { global: { plugins: [createTestI18n({}, locale)] } });
    return api;
}

describe("useFileSize", () => {
    it("formats bytes with FR units (o/Ko/Mo/Go)", () => {
        const { formatSize } = mountWithComposable("fr");
        expect(formatSize(512)).toBe("512 o");
        expect(formatSize(1024)).toBe("1 Ko");
        expect(formatSize(43827)).toBe("42,8 Ko");
        expect(formatSize(1024 * 1024)).toBe("1 Mo");
        expect(formatSize(1024 * 1024 * 1024)).toBe("1 Go");
    });

    it("formats bytes with EN units (B/KB/MB/GB)", () => {
        const { formatSize } = mountWithComposable("en");
        expect(formatSize(512)).toBe("512 B");
        expect(formatSize(2048)).toBe("2 KB");
        expect(formatSize(2 * 1024 * 1024)).toBe("2 MB");
    });

    it("uses EN units as fallback for unknown locale", () => {
        const { formatSize } = mountWithComposable("ja");
        expect(formatSize(512)).toBe("512 B");
    });

    it("shows decimal precision for KB/MB", () => {
        const { formatSize } = mountWithComposable("en");
        expect(formatSize(1536)).toBe("1.5 KB");
        expect(formatSize(1.5 * 1024 * 1024)).toBe("1.5 MB");
    });

    it("shows up to two decimal places for GB", () => {
        const { formatSize } = mountWithComposable("en");
        expect(formatSize(1.25 * 1024 * 1024 * 1024)).toBe("1.25 GB");
    });
});
