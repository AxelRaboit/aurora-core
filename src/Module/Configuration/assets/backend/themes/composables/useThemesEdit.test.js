import { afterEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useThemesEdit } from "./useThemesEdit.js";

const THEME = {
    id: 1,
    name: "Default",
    description: "",
    config: { header_logo_media_id: "674", primary_color: "#8b6cff" },
    headerLogoUrl: "/uploads/ged/2026/09/logo.png",
};

function setup() {
    let api;
    const host = defineComponent({
        setup() {
            api = useThemesEdit(
                ref([THEME]),
                "/backend/configuration/themes/__id__/edit",
            );

            return () => null;
        },
    });
    mount(host, { global: { plugins: [createTestI18n()] } });

    return api;
}

function answer() {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ success: true, theme: THEME }),
    });
}

const sentConfig = () =>
    JSON.parse(global.fetch.mock.calls.at(-1)[1].body).config;

afterEach(() => vi.restoreAllMocks());

describe("the theme's header logo", () => {
    it("opens as a picked picture, not a bare number", () => {
        const api = setup();
        api.openEdit(THEME);

        expect(api.headerMode.value).toBe("image");
        expect(api.headerLogo.value).toEqual({
            id: 674,
            url: "/uploads/ged/2026/09/logo.png",
        });
    });

    it("saves the id of the picture picked in the library", async () => {
        answer();
        const api = setup();
        api.openEdit(THEME);
        api.headerLogo.value = { id: 700, url: "/uploads/ged/other.png" };

        await api.submitEdit();
        await flushPromises();

        expect(sentConfig().header_logo_media_id).toBe("700");
    });

    it("saves no logo once the header goes back to the site name", async () => {
        answer();
        const api = setup();
        api.openEdit(THEME);
        api.headerMode.value = "default";

        await api.submitEdit();
        await flushPromises();

        expect(sentConfig()).not.toHaveProperty("header_logo_media_id");
    });
});
