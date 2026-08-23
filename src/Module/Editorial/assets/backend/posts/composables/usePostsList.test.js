import { describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

/**
 * The trash is a list, not a filter - and the composable has to agree with the
 * screen about that.
 *
 * The list used to count the trash among the active filters, so "Clear" offered
 * itself as soon as you opened the trash and, pressed, threw you back to the
 * live posts. That reads as a bug even when you know why: clearing the filters
 * applied *inside* a list is a different act from leaving it.
 *
 * These pin the separation, since it is the kind of thing a later "let's make
 * Clear reset everything" tidy-up would quietly undo.
 */

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({
        request: vi.fn(async () => ({
            ok: true,
            data: { items: [], total: 0, page: 1, totalPages: 1 },
        })),
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
    trashed: false,
    postTypeIds: [],
    termIds: [],
    statuses: [],
    listPath: "/backend/editorial/posts",
    editPathTemplate: "/backend/editorial/posts/__id__/edit",
    deletePathTemplate: "/backend/editorial/posts/__id__/delete",
    restorePathTemplate: "/backend/editorial/posts/__id__/restore",
    forceDeletePathTemplate: "/backend/editorial/posts/__id__/force-delete",
    emptyTrashPath: "/backend/editorial/posts/empty-trash",
    ...overrides,
});

describe("usePostsList", () => {
    it("does not count the chosen list among the active filters", async () => {
        const list = usePostsList(props({ trashed: true }));
        await nextTick();

        expect(list.activeFilterCount.value).toBe(0);
    });

    it("counts the filters applied inside it", async () => {
        const list = usePostsList(
            props({ trashed: true, statuses: ["draft"], postTypeIds: [2] }),
        );
        await nextTick();

        expect(list.activeFilterCount.value).toBe(2);
    });

    it("clears the filters without leaving the trash", async () => {
        const list = usePostsList(
            props({ trashed: true, statuses: ["draft"] }),
        );
        await nextTick();

        list.clearFilters();
        await nextTick();

        expect(list.statuses.value).toEqual([]);
        expect(list.trashed.value).toBe(true);
    });
});
