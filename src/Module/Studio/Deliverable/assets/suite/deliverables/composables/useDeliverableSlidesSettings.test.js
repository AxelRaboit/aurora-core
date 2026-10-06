import { afterEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const request = vi.fn();
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));
vi.mock("vue-sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const { settingsPayload, useDeliverableSlidesSettings } =
    await import("./useDeliverableSlidesSettings.js");

/**
 * Les réglages d'un diaporama passent par l'enregistrement d'un livrable :
 * sans la date de modification (chaque diapositive enregistrée la change),
 * jamais ouverts au client, et repartant de ce qui est enregistré.
 */
const DELIVERABLE = {
    id: 4,
    title: "Lancement",
    summary: "",
    locale: "fr",
    format: "slides",
    template: false,
    customerId: null,
    gridLayout: { enabled: true, zones: [] },
    gridContent: { zones: [] },
    appearance: {},
    readingHeader: { preparedFor: null },
    visibleToClient: false,
    scope: "shared",
    categoryId: 3,
    thumbnail: { id: 12, url: "/thumb.jpg" },
    updatedAt: "2026-10-06T08:00:00+00:00",
};

function mountWith(onSaved) {
    let api;
    mount(
        defineComponent({
            setup() {
                api = useDeliverableSlidesSettings(
                    { deliverable: DELIVERABLE, updatePath: "/update" },
                    { onSaved },
                );

                return () => h("div");
            },
        }),
        { global: { plugins: [createTestI18n()] } },
    );

    return api;
}

afterEach(() => request.mockReset());

describe("settingsPayload", () => {
    it("sends what the update route reads, without the modification date", () => {
        const payload = settingsPayload({
            ...DELIVERABLE,
            template: true,
            visibleToClient: true,
        });

        expect(payload).not.toHaveProperty("updatedAt");
        expect(payload).toMatchObject({
            title: "Lancement",
            locale: "fr",
            scope: "shared",
            categoryId: 3,
            template: true,
            thumbnailId: 12,
        });
        // What it is: in a space, sending false would hide from the client a
        // presentation they read, or be refused to whoever may not show it.
        expect(payload.visibleToClient).toBe(true);
        expect(settingsPayload(DELIVERABLE).visibleToClient).toBe(false);
    });
});

describe("useDeliverableSlidesSettings", () => {
    it("saves, hands the saved deliverable back and closes", async () => {
        const onSaved = vi.fn();
        const api = mountWith(onSaved);
        request.mockResolvedValue({
            success: true,
            deliverable: { ...DELIVERABLE, title: "Lancement Fabre" },
        });

        api.openSettings();
        api.form.value.title = "Lancement Fabre";
        await api.save();
        await flushPromises();

        expect(request).toHaveBeenCalledWith(
            "/update",
            expect.objectContaining({ title: "Lancement Fabre" }),
        );
        expect(onSaved).toHaveBeenCalledWith(
            expect.objectContaining({ title: "Lancement Fabre" }),
        );
        expect(api.open.value).toBe(false);
    });

    it("keeps the window open with the field errors when refused, and reopens from what is saved", async () => {
        const api = mountWith(null);
        request.mockResolvedValue({
            success: false,
            errors: {
                title: "suite.studio.deliverables.errors.title_required",
            },
        });

        api.openSettings();
        api.form.value.title = "";
        await api.save();

        expect(api.open.value).toBe(true);
        expect(api.errors.value.title).toBe(
            "suite.studio.deliverables.errors.title_required",
        );

        api.openSettings();
        expect(api.form.value.title).toBe("Lancement");
    });
});
