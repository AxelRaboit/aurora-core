/**
 * @vitest-environment happy-dom
 */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useListPage } from "@/shared/composables/list/useListPage.js";

const PAYLOAD = {
    ok: true,
    items: [{ id: 1 }, { id: 2 }],
    total: 2,
    page: 1,
    totalPages: 1,
};

function mountWithComposable(setupComposable) {
    const Comp = defineComponent({
        setup: () => {
            const result = setupComposable();
            return () => h("div");
        },
    });
    return mount(Comp, { global: { plugins: [createTestI18n()] } });
}

beforeEach(() => {
    history.replaceState(null, "", "/?other=keep");
    vi.stubGlobal(
        "fetch",
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => PAYLOAD,
        }),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe("useListPage", () => {
    it("does not auto-fetch when initialData is provided (SSR happy path)", async () => {
        let api;
        mountWithComposable(() => {
            api = useListPage("/list", { initialData: PAYLOAD });
            return api;
        });
        await nextTick();
        expect(fetch).not.toHaveBeenCalled();
        expect(api.items.value).toHaveLength(2);
        expect(api.total.value).toBe(2);
    });

    it("auto-fetches on mount when no initialData", async () => {
        mountWithComposable(() => useListPage("/list"));
        await nextTick();
        await nextTick();
        expect(fetch).toHaveBeenCalledOnce();
        expect(fetch.mock.calls[0][0]).toBe("/list?page=1");
    });

    it("syncs search to URL and resets to page 1 on onSearch", async () => {
        let api;
        mountWithComposable(() => {
            api = useListPage("/list", { initialData: PAYLOAD });
            return api;
        });
        api.onSearch("aurora");
        await nextTick();
        expect(new URL(window.location.href).searchParams.get("search")).toBe(
            "aurora",
        );
        expect(api.search.value).toBe("aurora");
        expect(fetch).toHaveBeenCalled();
        const url = fetch.mock.calls[0][0];
        expect(url).toContain("search=aurora");
        expect(url).toContain("page=1");
    });

    it("removes the search param when search becomes empty", async () => {
        let api;
        mountWithComposable(() => {
            api = useListPage("/list", {
                initialData: PAYLOAD,
                initialSearch: "old",
            });
            return api;
        });
        api.onSearch("");
        await nextTick();
        const searchParameters = new URL(window.location.href).searchParams;
        expect(searchParameters.has("search")).toBe(false);
        expect(searchParameters.get("other")).toBe("keep");
    });

    it("merges extraParams into the request URL", async () => {
        let api;
        mountWithComposable(() => {
            api = useListPage("/list", {
                initialData: PAYLOAD,
                extraParams: () => ({ status: "active" }),
            });
            return api;
        });
        api.goToPage(2);
        await nextTick();
        const url = fetch.mock.calls.at(-1)[0];
        expect(url).toContain("status=active");
        expect(url).toContain("page=2");
    });
});
