import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { SEARCH_OPEN_EVENT, useBackendSearch } from "./useBackendSearch.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request: vi.fn().mockResolvedValue({}) }),
}));

function mountSearch() {
    return mount({
        setup() {
            return {
                api: useBackendSearch({
                    searchPath: "/backend/search",
                    navItems: { value: [] },
                    currentRoute: "backend_dashboard",
                }),
            };
        },
        template: "<i />",
    });
}

describe("the search palette", () => {
    it("starts closed", () => {
        expect(mountSearch().vm.api.searchOpen.value).toBe(false);
    });

    /**
     * The button that opens it lives in the page header — a different Vue app,
     * which cannot reach `openPalette` directly. The palette stays in the menu
     * because it is a large piece of markup, and moving a feature to move a
     * button would be the wrong trade; the event is the way in.
     *
     * The name is a contract between two files that never import each other,
     * so it is asserted rather than assumed.
     */
    it("opens when the header's button announces itself", async () => {
        const wrapper = mountSearch();

        expect(SEARCH_OPEN_EVENT).toBe("aurora:open-search");

        window.dispatchEvent(new CustomEvent(SEARCH_OPEN_EVENT));

        expect(wrapper.vm.api.searchOpen.value).toBe(true);
        wrapper.unmount();
    });

    // Unmounted means gone: a listener left on `window` would keep a dead
    // component's palette reacting, and there is one of these per page.
    it("stops listening once it is gone", () => {
        const wrapper = mountSearch();
        const { api } = wrapper.vm;

        wrapper.unmount();
        window.dispatchEvent(new CustomEvent(SEARCH_OPEN_EVENT));

        expect(api.searchOpen.value).toBe(false);
    });
});
