import { describe, it, expect, vi } from "vitest";
import { useDocumentRowActions } from "./useDocumentRowActions.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

const DOC = { id: 1, title: "Devis", fileUrl: "/uploads/devis.pdf" };

const keysFor = (options, doc = DOC) =>
    useDocumentRowActions({
        can: () => true,
        openEdit: vi.fn(),
        confirmDelete: vi.fn(),
        ...options,
    })(doc).map((action) => action.key);

describe("useDocumentRowActions", () => {
    it("offers the library's full list when the row can be opened", () => {
        expect(keysFor({ viewDoc: vi.fn(), openQr: vi.fn() })).toEqual([
            "view",
            "download",
            "qr",
            "edit",
            "delete",
        ]);
    });

    /**
     * The document's own page reads the same list. Opening it is where the
     * reader already is, and it hands out no QR codes.
     */
    it("leaves out opening and the QR code when neither is offered", () => {
        expect(keysFor({})).toEqual(["download", "edit", "delete"]);
    });

    // A record with no file behind it: downloading would offer an address that
    // resolves to nothing.
    it("offers no download for a document that has no file yet", () => {
        expect(
            keysFor({ viewDoc: vi.fn(), openQr: vi.fn() }, { id: 2 }),
        ).toEqual(["view", "edit", "delete"]);
    });

    it("offers relocation only once a second backend exists", () => {
        const relocate = vi.fn();

        expect(keysFor({ relocate, relocationAvailable: true })).toContain(
            "relocate",
        );
        expect(keysFor({ relocate, relocationAvailable: false })).not.toContain(
            "relocate",
        );
    });
});
