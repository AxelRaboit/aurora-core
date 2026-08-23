import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * The list re-fetches the same payload the page was rendered with, so a
 * filter change and a first load cannot disagree about the shape. Filters
 * live in the query string too: a filtered list stays a link you can send.
 */
export function usePostsList(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const items = ref(props.posts.items ?? []);
    const total = ref(props.posts.total ?? 0);
    const page = ref(props.posts.page ?? 1);
    const totalPages = ref(props.posts.totalPages ?? 1);
    const loading = ref(false);

    const search = ref(props.search ?? "");
    const trashed = ref(Boolean(props.trashed));

    /**
     * Which list is on screen right now - not which one has been asked for.
     *
     * The two differ for the length of a request, and the trash controls used
     * to read the intent while the rows were still the previous list's. Ticking
     * "show the trash" therefore flashed an "Empty the trash" button, built
     * from a count of posts that were not in the trash, and took it away again
     * when the answer arrived.
     */
    const showingTrash = ref(Boolean(props.trashed));
    const postTypeIds = ref([...(props.postTypeIds ?? [])]);
    const termIds = ref([...(props.termIds ?? [])]);
    const statuses = ref([...(props.statuses ?? [])]);

    // The trash deliberately does not count. It is not a filter narrowing the
    // list, it is the choice of *which* list - the screen says so with its own
    // tabs - and counting it made "Clear" offer to leave the trash, which is a
    // different thing from clearing the filters applied inside it.
    const activeFilterCount = computed(
        () =>
            postTypeIds.value.length +
            termIds.value.length +
            statuses.value.length,
    );

    function queryString() {
        const params = new URLSearchParams();
        if (search.value) params.set("search", search.value);
        if (page.value > 1) params.set("page", String(page.value));
        if (trashed.value) params.set("trashed", "1");
        if (postTypeIds.value.length)
            params.set("postTypeIds", postTypeIds.value.join(","));
        if (termIds.value.length)
            params.set("termIds", termIds.value.join(","));
        if (statuses.value.length)
            params.set("statuses", statuses.value.join(","));

        return params.toString();
    }

    async function reload() {
        if (loading.value) return;

        loading.value = true;
        try {
            const query = queryString();
            const data = await request(
                `${props.listPath}${query ? `?${query}` : ""}`,
                null,
                { method: "GET" },
            );
            if (!data?.success) return;

            items.value = data.items;
            showingTrash.value = trashed.value;
            total.value = data.total;
            page.value = data.page;
            totalPages.value = data.totalPages;

            // Keeps the address bar in step without reloading the page, so a
            // filtered list survives a refresh and can be shared.
            window.history.replaceState(
                {},
                "",
                `${window.location.pathname}${query ? `?${query}` : ""}`,
            );
        } finally {
            loading.value = false;
        }
    }

    // Any filter change resets to the first page: staying on page 4 of a
    // result set that now has two pages shows an empty screen.
    watch([search, trashed, postTypeIds, termIds, statuses], () => {
        page.value = 1;
        reload();
    });

    function goToPage(target) {
        if (target < 1 || target > totalPages.value || target === page.value)
            return;

        page.value = target;
        reload();
    }

    function toggleIn(list, value) {
        list.value = list.value.includes(value)
            ? list.value.filter((item) => item !== value)
            : [...list.value, value];
    }

    // Clears the filters, and only those: you stay on the list you chose.
    function clearFilters() {
        postTypeIds.value = [];
        termIds.value = [];
        statuses.value = [];
    }

    const {
        pendingDelete,
        loading: deleteLoading,
        confirm: confirmDelete,
        submit: doDelete,
    } = useDelete(
        props.deletePathTemplate,
        () => reload(),
        "backend.posts.deleted",
    );

    const pendingForceDelete = ref(null);
    const emptyingTrash = ref(false);

    async function restore(post) {
        const data = await request(
            buildPath(props.restorePathTemplate, { id: post.id }),
        );
        if (data?.success) {
            toast.success(t("backend.posts.restored"));
            reload();
        }
    }

    async function forceDelete() {
        if (!pendingForceDelete.value) return;

        const data = await request(
            buildPath(props.forceDeletePathTemplate, {
                id: pendingForceDelete.value.id,
            }),
        );
        if (data?.success) {
            toast.success(t("backend.posts.force_deleted"));
            pendingForceDelete.value = null;
            reload();
        }
    }

    const confirmEmptyTrash = ref(false);

    async function emptyTrash() {
        if (emptyingTrash.value) return;

        emptyingTrash.value = true;
        try {
            const data = await request(props.emptyTrashPath);
            if (data?.success) {
                toast.success(
                    t("backend.posts.trash_emptied", { count: data.deleted }),
                );
                confirmEmptyTrash.value = false;
                reload();
            }
        } finally {
            emptyingTrash.value = false;
        }
    }

    function editPath(post) {
        return buildPath(props.editPathTemplate, { id: post.id });
    }

    return {
        items,
        total,
        page,
        totalPages,
        loading,
        search,
        trashed,
        postTypeIds,
        termIds,
        statuses,
        activeFilterCount,
        reload,
        goToPage,
        toggleIn,
        clearFilters,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
        pendingForceDelete,
        forceDelete,
        confirmEmptyTrash,
        emptyingTrash,
        emptyTrash,
        showingTrash,
        restore,
        editPath,
    };
}
