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

/**
 * Announced by the search button in the page header.
 *
 * The button lives in one Vue app and this palette in another, so the two
 * cannot see each other's refs. The event is how the trigger reaches the
 * feature without the feature having to move — see pattern_cross_mount_state_sync.
 */
export const SEARCH_OPEN_EVENT = "aurora:open-search";

// ── Recent pages ─────────────────────────────────────────────────────────────

const RECENT_KEY = "aurora-search-recent";
const RECENT_MAX = 6;

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

// ── Item identity (nav/recent use route; API items use id) ────────────────────

function itemKey(kind, item) {
    return kind === "nav" || kind === "recent" ? item.route : item.id;
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

    const apiResults = ref({
        posts: [],
        terms: [],
        media: [],
        projects: [],
        tasks: [],
    });

    // ── Local results ─────────────────────────────────────────────────────────

    const recentPages = computed(() => {
        const routes = loadRecentRoutes();
        return routes
            .map((route) => navItems.value?.find((i) => i.route === route))
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
            { kind: "nav", items: navResults.value },
            { kind: "project", items: apiResults.value.projects },
            { kind: "task", items: apiResults.value.tasks },
            { kind: "post", items: apiResults.value.posts },
            { kind: "term", items: apiResults.value.terms },
            { kind: "media", items: apiResults.value.media },
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
            apiResults.value = {
                posts: [],
                terms: [],
                media: [],
                projects: [],
                tasks: [],
            };
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
                apiResults.value = {
                    posts: data.posts ?? [],
                    terms: data.terms ?? [],
                    media: data.media ?? [],
                    projects: data.projects ?? [],
                    tasks: data.tasks ?? [],
                };
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

    // The button that opens this sits in the page header — a different Vue app,
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

        if (kind === "recent" || kind === "nav") {
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
        // Record the current page as a recent visit
        const matchingItem = navItems.value?.find((i) =>
            currentRoute?.startsWith(i.route),
        );
        if (matchingItem) recordRecentRoute(matchingItem.route);
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
