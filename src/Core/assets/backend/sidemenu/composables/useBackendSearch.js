import {
    ref,
    computed,
    watch,
    onMounted,
    onBeforeUnmount,
    nextTick,
} from "vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import {
    searchSectionKeys,
    searchSections,
} from "@/shared/search/searchSectionRegistry.js";

/**
 * Announced by the search button in the page header.
 *
 * The button lives in one Vue app and this palette in another, so the two
 * cannot see each other's refs. The event is how the trigger reaches the
 * feature without the feature having to move - see pattern_cross_mount_state_sync.
 */
export const SEARCH_OPEN_EVENT = "aurora:open-search";

// ── Recent pages ─────────────────────────────────────────────────────────────

const RECENT_KEY = "aurora-search-recent";
const RECENT_MAX = 6;

/**
 * Recent visits, stored by an entry's **stable key** rather than its route name.
 *
 * For an ordinary entry the two are the same string, so what is already in a
 * reader's browser keeps resolving. They part company for entries that share one
 * route name - the eleven settings tabs - where a route name identifies the set,
 * not the member, and "recently visited" would always resolve back to the first.
 */
function loadRecentRoutes() {
    try {
        const raw = localStorage.getItem(RECENT_KEY);
        return Array.isArray(JSON.parse(raw ?? "null")) ? JSON.parse(raw) : [];
    } catch {
        return [];
    }
}

function recordRecentRoute(route) {
    if (!route) return;
    const routes = [
        route,
        ...loadRecentRoutes().filter((r) => r !== route),
    ].slice(0, RECENT_MAX);
    localStorage.setItem(RECENT_KEY, JSON.stringify(routes));
}

// ── Item identity (nav/recent use the stable key; API items use id) ──────────

function itemKey(kind, item) {
    return kind === "nav" || kind === "recent"
        ? (item.key ?? item.route)
        : item.id;
}

// ── Composable ────────────────────────────────────────────────────────────────

export function useBackendSearch({ searchPath, navItems, currentRoute }) {
    // ── State ─────────────────────────────────────────────────────────────────

    const { request } = useRequest();

    const searchOpen = ref(false);
    const searchQuery = ref("");
    const searchLoading = ref(false);
    const searchHighlightedIndex = ref(0);
    const searchInputRef = ref(null);

    /**
     * Whatever the registered sections returned, keyed as the API sent it.
     *
     * Built from the registry rather than written out here. It used to be a fixed
     * object, and a module contributing a section without editing this file had
     * its results dropped after its provider had already run - so the registry the
     * PHP interface promises did not exist on this side.
     */
    const apiResults = ref(emptyResults());

    function emptyResults() {
        return Object.fromEntries(searchSectionKeys().map((key) => [key, []]));
    }

    // ── Local results ─────────────────────────────────────────────────────────

    const recentPages = computed(() => {
        const keys = loadRecentRoutes();
        return keys
            .map((key) =>
                navItems.value?.find((i) => (i.key ?? i.route) === key),
            )
            .filter(Boolean);
    });

    const navResults = computed(() => {
        const q = searchQuery.value.trim().toLowerCase();
        if (!q) return [];
        return (navItems.value ?? []).filter((i) =>
            i.label.toLowerCase().includes(q),
        );
    });

    // ── Sections (drives the template) ───────────────────────────────────────
    //
    // Empty query → show recent pages (if any).
    // Active query → show all non-empty result sections.

    const sections = computed(() => {
        if (!searchQuery.value.trim()) {
            return recentPages.value.length
                ? [{ kind: "recent", items: recentPages.value }]
                : [];
        }
        return [
            // Navigation is core's own and always first: a reader typing a page
            // name wants the page, not a record that mentions it.
            { kind: "nav", items: navResults.value },
            ...searchSections().map((section) => ({
                kind: section.kind,
                items: apiResults.value[section.key] ?? [],
            })),
        ].filter((s) => s.items.length > 0);
    });

    const flatResults = computed(() =>
        sections.value.flatMap((s) =>
            s.items.map((item) => ({ kind: s.kind, item })),
        ),
    );

    const totalResults = computed(() => flatResults.value.length);

    // ── API search ────────────────────────────────────────────────────────────

    async function runSearch() {
        const trimmed = searchQuery.value.trim();
        if (!trimmed) {
            apiResults.value = emptyResults();
            return;
        }
        searchLoading.value = true;
        try {
            const url = new URL(searchPath, window.location.origin);
            url.searchParams.set("q", trimmed);
            const data = await request(url.toString(), null, {
                method: HttpMethod.Get,
                noGuard: true,
            });
            if (data) {
                // Every registered key, and nothing else: a provider answering
                // with a key nobody registered has no section to be drawn in, and
                // silently keeping it would be a row that never appears.
                apiResults.value = Object.fromEntries(
                    searchSectionKeys().map((key) => [key, data[key] ?? []]),
                );
                searchHighlightedIndex.value = 0;
            }
        } finally {
            searchLoading.value = false;
        }
    }

    watch(searchQuery, useDebounce(runSearch, 180));

    // ── Palette actions ───────────────────────────────────────────────────────

    function openPalette() {
        searchOpen.value = true;
        searchQuery.value = "";
        apiResults.value = { posts: [], terms: [], media: [] };
        searchHighlightedIndex.value = 0;
        nextTick(() => searchInputRef.value?.focus());
    }

    // The button that opens this sits in the page header - a different Vue app,
    // which cannot reach `openPalette` directly. Registered here rather than in
    // the menu's SFC so the composable that owns the palette also owns the way
    // in, which is where `useSidemenuCollapse` and `useSidemenuLiveColors` keep
    // theirs. See pattern_cross_mount_state_sync.
    onMounted(() => window.addEventListener(SEARCH_OPEN_EVENT, openPalette));
    onBeforeUnmount(() =>
        window.removeEventListener(SEARCH_OPEN_EVENT, openPalette),
    );

    function closePalette() {
        searchOpen.value = false;
    }

    // ── Navigation ────────────────────────────────────────────────────────────

    function entryIndex(kind, item) {
        const key = itemKey(kind, item);
        return flatResults.value.findIndex(
            (e) => e.kind === kind && itemKey(e.kind, e.item) === key,
        );
    }

    function findNavPath(routePrefix) {
        return (
            navItems.value?.find((i) => i.route.startsWith(routePrefix))
                ?.path ?? null
        );
    }

    function activateResult(entry) {
        if (!entry) return;
        const { kind, item } = entry;

        // A row that carries its own path goes there, whatever kind it is. The
        // branches below predate this and stay because those rows do not: adding
        // one per module was how core's search ended up knowing route names that
        // belong to modules.
        if (kind === "recent" || kind === "nav" || item.path) {
            window.location.href = item.path;
        } else if (kind === "post") {
            const path = findNavPath("admin_posts");
            if (!path) return;
            const url = new URL(path, window.location.origin);
            if (item.trashed) url.searchParams.set("trashed", "1");
            window.location.href = url.toString();
        } else if (kind === "term") {
            const path = findNavPath("admin_taxonomies");
            if (path) window.location.href = path;
        } else if (kind === "media") {
            const path = findNavPath("admin_media");
            if (path) window.location.href = path;
        } else if (kind === "project") {
            const path = findNavPath("backend_project_projects");
            if (path) window.location.href = path;
        } else if (kind === "task") {
            const path = findNavPath("backend_project_projects");
            if (path) window.location.href = path;
        }
    }

    // ── Keyboard ──────────────────────────────────────────────────────────────

    function onGlobalKeydown(event) {
        if (
            (event.ctrlKey || event.metaKey) &&
            event.key.toLowerCase() === "k"
        ) {
            event.preventDefault();
            searchOpen.value ? closePalette() : openPalette();
            return;
        }
        if (!searchOpen.value) return;

        if (event.key === "Escape") {
            event.preventDefault();
            closePalette();
        } else if (event.key === "ArrowDown") {
            event.preventDefault();
            if (totalResults.value)
                searchHighlightedIndex.value =
                    (searchHighlightedIndex.value + 1) % totalResults.value;
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            if (totalResults.value)
                searchHighlightedIndex.value =
                    (searchHighlightedIndex.value - 1 + totalResults.value) %
                    totalResults.value;
        } else if (event.key === "Enter") {
            event.preventDefault();
            activateResult(flatResults.value[searchHighlightedIndex.value]);
        }
    }

    onMounted(() => {
        window.addEventListener("keydown", onGlobalKeydown);
        // Record the current page as a recent visit. Exact path first: entries
        // that share a route name can only be told apart that way, and a prefix
        // test would record whichever of them happens to come first.
        const path =
            "undefined" !== typeof window ? window.location.pathname : null;
        const matchingItem =
            navItems.value?.find((i) => i.matchPath && i.path === path) ??
            navItems.value?.find((i) => currentRoute?.startsWith(i.route));
        if (matchingItem)
            recordRecentRoute(matchingItem.key ?? matchingItem.route);
    });

    onBeforeUnmount(() =>
        window.removeEventListener("keydown", onGlobalKeydown),
    );

    // ── Public API ────────────────────────────────────────────────────────────

    return {
        searchOpen,
        searchQuery,
        searchLoading,
        searchHighlightedIndex,
        searchInputRef,
        sections,
        flatResults,
        totalResults,
        openPalette,
        closePalette,
        activateResult,
        entryIndex,
    };
}
