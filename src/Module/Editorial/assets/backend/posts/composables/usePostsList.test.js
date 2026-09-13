import { describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

/**
 * What "Clear" clears, now that the list has one list to show.
 *
 * The trash used to be a second list reachable from here, and counting it
 * among the active filters made "Clear" throw the reader back to the live
 * posts. Both are gone: the trash lives on its own screen, and this list only
 * ever shows what has not been deleted. What remains worth pinning is that
 * clearing the filters clears the filters and nothing else.
 */

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
const requestedUrls = [];
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({
        request: vi.fn(async (url) => {
            requestedUrls.push(url);

            return {
                success: true,
                items: [],
                total: 0,
                page: 1,
                totalPages: 1,
            };
        }),
    }),
}));
vi.mock("@/shared/composables/form/useDelete.js", () => ({
    useDelete: () => ({
        pendingDelete: { value: null },
        loading: { value: false },
        confirm: vi.fn(),
        doDelete: vi.fn(),
    }),
}));

const { usePostsList } = await import("./usePostsList.js");

const props = (overrides = {}) => ({
    posts: { items: [], total: 0, page: 1, totalPages: 1 },
    search: "",
    postTypeIds: [],
    termIds: [],
    statuses: [],
    listPath: "/backend/editorial/posts",
    editPathTemplate: "/backend/editorial/posts/__id__/edit",
    deletePathTemplate: "/backend/editorial/posts/__id__/delete",
    ...overrides,
});

describe("usePostsList", () => {
    it("counts the filters that are applied, and only those", async () => {
        const list = usePostsList(
            props({ statuses: ["draft"], postTypeIds: [2] }),
        );
        await nextTick();

        expect(list.activeFilterCount.value).toBe(2);
    });

    it("clears every filter at once", async () => {
        const list = usePostsList(
            props({ statuses: ["draft"], postTypeIds: [2], termIds: [7] }),
        );
        await nextTick();

        list.clearFilters();
        await nextTick();

        expect(list.statuses.value).toEqual([]);
        expect(list.postTypeIds.value).toEqual([]);
        expect(list.termIds.value).toEqual([]);
        expect(list.activeFilterCount.value).toBe(0);
    });

    it("never asks the server for trashed rows", async () => {
        const list = usePostsList(props());
        requestedUrls.length = 0;

        list.statuses.value = ["draft"];
        await nextTick();
        await nextTick();

        expect(requestedUrls.length).toBeGreaterThan(0);
        expect(requestedUrls.join(" ")).not.toContain("trashed");
    });
});
