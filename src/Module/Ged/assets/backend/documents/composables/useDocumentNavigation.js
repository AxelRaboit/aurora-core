import { ref, onMounted, onUnmounted } from "vue";

/**
 * Sidebar navigation state - mirrors Media's pattern of (currentFolderId,
 * allDocumentsView) refs synced with the URL via history.pushState.
 *
 * The list itself is fetched by `useListPage` (pagination + search), which
 * reads its extra query params from `extraParams()`. This composable just
 * mutates the refs and triggers `reload()`; the URL only stores folder/view
 * (search is already synced by useListPage).
 *
 * `folders` is held here too because the list endpoint returns refreshed
 * folder counts on every page load (badges next to folder names stay live).
 */
export function useDocumentNavigation(props, reload, clearSelection) {
    const folders = ref([...(props.folders ?? [])]);

    const initialUrl = new URL(window.location.href);
    const initialFolderParam = initialUrl.searchParams.get("folderId");
    const initialAll = initialUrl.searchParams.get("all") === "1";
    const initialRoot = initialUrl.searchParams.get("rootOnly") === "1";

    const currentFolderId = ref(
        initialFolderParam ? Number(initialFolderParam) || null : null,
    );
    const allDocumentsView = ref(
        initialAll || (!initialRoot && !initialFolderParam),
    );
    const rootOnly = ref(initialRoot);

    function buildUrl() {
        const url = new URL(window.location.pathname, window.location.origin);
        // Preserve search if present in current URL
        const search = new URLSearchParams(window.location.search).get(
            "search",
        );
        if (search) url.searchParams.set("search", search);
        if (currentFolderId.value) {
            url.searchParams.set("folderId", String(currentFolderId.value));
        } else if (rootOnly.value) {
            url.searchParams.set("rootOnly", "1");
        } else if (allDocumentsView.value) {
            url.searchParams.set("all", "1");
        }
        return url;
    }

    function pushState() {
        history.pushState(
            {
                folderId: currentFolderId.value,
                rootOnly: rootOnly.value,
                all: allDocumentsView.value,
            },
            "",
            buildUrl(),
        );
    }

    async function navigateTo(folderId) {
        currentFolderId.value = folderId ?? null;
        rootOnly.value = false;
        allDocumentsView.value = false;
        clearSelection?.();
        pushState();
        await reload();
    }

    async function navigateToRoot() {
        currentFolderId.value = null;
        rootOnly.value = true;
        allDocumentsView.value = false;
        clearSelection?.();
        pushState();
        await reload();
    }

    async function navigateToAll() {
        currentFolderId.value = null;
        rootOnly.value = false;
        allDocumentsView.value = true;
        clearSelection?.();
        pushState();
        await reload();
    }

    function onPopState(event) {
        const state = event.state ?? {};
        currentFolderId.value = state.folderId ?? null;
        rootOnly.value = !!state.rootOnly;
        allDocumentsView.value =
            state.all ?? (!state.rootOnly && !state.folderId);
        reload();
    }

    onMounted(() => {
        history.replaceState(
            {
                folderId: currentFolderId.value,
                rootOnly: rootOnly.value,
                all: allDocumentsView.value,
            },
            "",
            buildUrl(),
        );
        window.addEventListener("popstate", onPopState);
    });

    onUnmounted(() => {
        window.removeEventListener("popstate", onPopState);
    });

    // Called by useListPage's onData callback so the sidebar badges refresh
    // after every list response (move, delete, upload, …).
    function onListResponse(data) {
        if (Array.isArray(data?.folders)) folders.value = data.folders;
    }

    function extraParams() {
        if (currentFolderId.value) {
            return { folderId: currentFolderId.value };
        }
        if (rootOnly.value) {
            return { rootOnly: 1 };
        }
        return {};
    }

    return {
        folders,
        currentFolderId,
        allDocumentsView,
        rootOnly,
        navigateTo,
        navigateToRoot,
        navigateToAll,
        onListResponse,
        extraParams,
    };
}
