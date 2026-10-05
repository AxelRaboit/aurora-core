import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick } from "vue";
import { mount } from "@vue/test-utils";
import { toast } from "vue-sonner";
import { useDeliverableEditor } from "./useDeliverableEditor.js";

const request = vi.fn();

vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));
vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

/**
 * L'enregistrement d'un livrable, tel que l'éditeur le vit.
 *
 * Ce qui se casserait sans bruit : un lecteur seul dont Ctrl+S part au
 * serveur pour y recevoir un 403, un enregistrement fondé sur une version
 * périmée qui efface le travail d'un collègue, et un message d'erreur qui
 * dit « n'a pas pu être enregistré » quand la vraie raison est lisible.
 */
const DELIVERABLE = {
    id: 1,
    title: "Audit",
    summary: "",
    locale: "fr",
    gridLayout: { zones: [] },
    gridContent: { zones: {} },
    appearance: {},
    readingHeader: {},
    visibleToClient: false,
    updatedAt: "2026-10-05T10:00:00+00:00",
    scope: "shared",
    categoryId: null,
    thumbnail: null,
};

let editor;

function mountEditor(props = {}) {
    const Harness = defineComponent({
        setup() {
            editor = useDeliverableEditor({
                deliverable: DELIVERABLE,
                updatePath: "/update",
                canEdit: true,
                ...props,
            });

            return () => h("div");
        },
    });

    return mount(Harness);
}

describe("useDeliverableEditor", () => {
    beforeEach(() => {
        request.mockReset();
        toast.error.mockReset();
        toast.success.mockReset();
    });

    afterEach(() => vi.restoreAllMocks());

    it("sends the date of the version it holds, outside of what counts as a change", async () => {
        request.mockResolvedValue({
            success: true,
            deliverable: { updatedAt: "2026-10-05T10:05:00+00:00" },
        });
        const wrapper = mountEditor();

        // Opening is not a change: the date is not part of the fingerprint.
        expect(editor.dirty.value).toBe(false);
        editor.form.value.title = "Audit complet";
        await nextTick();
        expect(editor.dirty.value).toBe(true);

        await editor.save();

        const sent = request.mock.calls[0][1];
        expect(sent.updatedAt).toBe("2026-10-05T10:00:00+00:00");
        expect(sent.force).toBeUndefined();

        // It then holds the version the server answered, for the next save.
        expect(editor.form.value.updatedAt).toBe("2026-10-05T10:05:00+00:00");
        expect(editor.dirty.value).toBe(false);
        wrapper.unmount();
    });

    it("reports a version conflict as such instead of a failure", async () => {
        request.mockResolvedValue({ success: false, conflict: true });
        const wrapper = mountEditor();
        editor.form.value.title = "Mon titre";

        expect(await editor.save()).toBe(false);

        expect(editor.conflict.value).toBe(true);
        expect(toast.error).not.toHaveBeenCalled();
        expect(editor.dirty.value).toBe(true);
        wrapper.unmount();
    });

    it("overwrites on purpose when the author chooses to", async () => {
        request
            .mockResolvedValueOnce({ success: false, conflict: true })
            .mockResolvedValueOnce({
                success: true,
                deliverable: { updatedAt: "2026-10-05T11:00:00+00:00" },
            });
        const wrapper = mountEditor();
        editor.form.value.title = "Mon titre";

        await editor.save();
        await editor.saveAnyway();

        expect(request.mock.calls[1][1].force).toBe(true);
        expect(editor.conflict.value).toBe(false);
        expect(editor.dirty.value).toBe(false);
        wrapper.unmount();
    });

    it("can set a conflict aside to keep editing", async () => {
        request.mockResolvedValue({ success: false, conflict: true });
        const wrapper = mountEditor();
        editor.form.value.title = "x";
        await editor.save();

        editor.dismissConflict();

        expect(editor.conflict.value).toBe(false);
        wrapper.unmount();
    });

    it("says what the server refused, in its words", async () => {
        request.mockResolvedValue({
            success: false,
            errors: {
                title: "backend.studio.deliverables.errors.title_required",
            },
        });
        const wrapper = mountEditor();

        await editor.save();

        expect(toast.error).toHaveBeenCalledWith(
            "backend.studio.deliverables.errors.title_required",
        );
        expect(editor.errors.value.title).toBeDefined();
        wrapper.unmount();
    });

    it("falls back to the generic message when the refusal explains nothing", async () => {
        request.mockResolvedValue({ success: false });
        const wrapper = mountEditor();

        await editor.save();

        expect(toast.error).toHaveBeenCalledWith(
            "backend.studio.deliverables.save_failed",
        );
        wrapper.unmount();
    });

    describe("without the right to write", () => {
        it("never saves, and never claims unsaved changes", async () => {
            const wrapper = mountEditor({ canEdit: false });
            editor.form.value.title = "Je tape quand même";
            await nextTick();

            expect(editor.dirty.value).toBe(false);
            expect(await editor.save()).toBe(false);
            expect(request).not.toHaveBeenCalled();
            wrapper.unmount();
        });

        it("ignores Ctrl+S instead of sending it for a 403", async () => {
            const wrapper = mountEditor({ canEdit: false });
            const event = new KeyboardEvent("keydown", {
                key: "s",
                ctrlKey: true,
                cancelable: true,
            });

            window.dispatchEvent(event);
            await nextTick();

            // The browser's own « save page » is still kept out of the way.
            expect(event.defaultPrevented).toBe(true);
            expect(request).not.toHaveBeenCalled();
            wrapper.unmount();
        });

        it("does not arm the leave warning", async () => {
            const wrapper = mountEditor({ canEdit: false });
            editor.form.value.title = "x";
            await nextTick();
            const event = new Event("beforeunload", { cancelable: true });

            window.dispatchEvent(event);

            expect(event.defaultPrevented).toBe(false);
            wrapper.unmount();
        });
    });

    it("saves on Ctrl+S when it may", async () => {
        request.mockResolvedValue({ success: true, deliverable: {} });
        const wrapper = mountEditor();
        editor.form.value.title = "Nouveau";

        window.dispatchEvent(
            new KeyboardEvent("keydown", {
                key: "s",
                metaKey: true,
                cancelable: true,
            }),
        );
        await nextTick();
        await Promise.resolve();

        expect(request).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });
});
