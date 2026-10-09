<script setup>
/**
 * Searching the notebook (10/10/2026), as Obsidian's search pane: everything
 * a note holds, best match first, the passages that matched, highlighted,
 * and chips that narrow by tag, folder or space.
 *
 * What is typed is read by the server (`NoteSearchQuery.php`): words, a
 * `"phrase"`, `-word`, and filters such as `tag:client` or `statut:"En
 * cours"`. The « Filtres » menu writes them, so nobody has to learn them.
 *
 * Recent searches and the ones pinned are this browser's: conveniences.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Clock, FileText, Filter, MessageSquare, Pin, PinOff, Search, SlidersHorizontal, Tag as TagIcon, X } from "lucide-vue-next";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { highlightParts } from "@notes/suite/markdown/composables/noteSearchHighlight.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** What to search on opening, from another search box. */
    initialQuery: { type: String, default: "" },
    /** `(query, sort) => Promise<{ok, payload}>`. */
    searchFull: { type: Function, required: true },
});

const emit = defineEmits(["close", "open"]);

const { t } = useI18n();
const { formatDateShort } = useDateFormat();

const RECENT_KEY = "aurora.notes.search.recent";
const PINNED_KEY = "aurora.notes.search.pinned";

const query = ref("");
const sort = ref("relevance");
const loading = ref(false);
const results = ref([]);
const total = ref(0);
const facets = ref({ tags: {}, folders: [], spaces: [] });
const needles = ref([]);
const tookMs = ref(0);
const active = ref(0);
const field = ref(null);
const filtersOpen = ref(false);
const recent = ref(readList(RECENT_KEY));
const pinned = ref(readList(PINNED_KEY));

function readList(key) {
    try {
        const list = JSON.parse(window.localStorage.getItem(key) ?? "[]");

        return Array.isArray(list) ? list.filter((item) => "string" === typeof item).slice(0, 12) : [];
    } catch {
        return [];
    }
}

function writeList(key, list) {
    try {
        window.localStorage.setItem(key, JSON.stringify(list));
    } catch {
        // Remembered for this visit only.
    }
}

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        query.value = props.initialQuery || query.value;
        recent.value = readList(RECENT_KEY);
        pinned.value = readList(PINNED_KEY);
        await nextTick();
        field.value?.focus();
        field.value?.select();
        if (query.value.trim()) void run();
    },
);

let timer = null;
let asked = 0;
watch([query, sort], () => {
    clearTimeout(timer);
    timer = setTimeout(() => void run(), 220);
});

async function run() {
    const wanted = query.value.trim();
    if ("" === wanted) {
        results.value = [];
        total.value = 0;
        facets.value = { tags: {}, folders: [], spaces: [] };
        return;
    }
    asked += 1;
    const question = asked;
    loading.value = true;
    const { ok, payload } = await props.searchFull(wanted, sort.value);
    if (question !== asked) return;
    loading.value = false;
    if (!ok) return;
    results.value = payload?.results ?? [];
    total.value = payload?.total ?? 0;
    facets.value = payload?.facets ?? { tags: {}, folders: [], spaces: [] };
    needles.value = payload?.needles ?? [];
    tookMs.value = payload?.tookMs ?? 0;
    active.value = 0;
}

function remember(wanted) {
    const value = wanted.trim();
    if ("" === value) return;
    recent.value = [value, ...recent.value.filter((one) => one !== value)].slice(0, 8);
    writeList(RECENT_KEY, recent.value);
}

const isPinned = computed(() => pinned.value.includes(query.value.trim()));
function togglePin() {
    const value = query.value.trim();
    if ("" === value) return;
    pinned.value = isPinned.value ? pinned.value.filter((one) => one !== value) : [value, ...pinned.value].slice(0, 12);
    writeList(PINNED_KEY, pinned.value);
}

function open(result) {
    if (!result) return;
    remember(query.value);
    emit("open", { id: result.id, needles: needles.value });
    emit("close");
}

function onKeydown(event) {
    if ("ArrowDown" === event.key) {
        event.preventDefault();
        active.value = Math.min(active.value + 1, results.value.length - 1);
        document.querySelector(`[data-note-search-result="${active.value}"]`)?.scrollIntoView({ block: "nearest" });
    } else if ("ArrowUp" === event.key) {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
        document.querySelector(`[data-note-search-result="${active.value}"]`)?.scrollIntoView({ block: "nearest" });
    } else if ("Enter" === event.key) {
        event.preventDefault();
        open(results.value[active.value]);
    }
}

/**
 * Escape closes the filters' menu first, and only then the window: caught
 * before the window's own handler, while the menu is open.
 */
function closeMenuFirst(event) {
    if ("Escape" !== event.key || !filtersOpen.value) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    filtersOpen.value = false;
}
watch(filtersOpen, (open) => {
    if (open) window.addEventListener("keydown", closeMenuFirst, true);
    else window.removeEventListener("keydown", closeMenuFirst, true);
});
onBeforeUnmount(() => window.removeEventListener("keydown", closeMenuFirst, true));

/** Adds a filter to what is typed, quoted when it holds a space. */
function addFilter(key, value = "") {
    const quoted = /\s/.test(value) ? `"${value}"` : value;
    const token = `${key}:${quoted}`;
    if (query.value.includes(token)) return;
    query.value = `${query.value.trim()} ${token}`.trim();
    filtersOpen.value = false;
    void nextTick(() => {
        field.value?.focus();
        if ("" === value) field.value?.setSelectionRange(query.value.length, query.value.length);
    });
}

const FILTERS = computed(() => [
    { key: "tag", example: t("notes.markdown.search.filters.tag") },
    { key: "dossier", example: t("notes.markdown.search.filters.folder") },
    { key: "espace", example: t("notes.markdown.search.filters.space") },
    { key: "tâche", value: "à-faire", example: t("notes.markdown.search.filters.task_todo") },
    { key: "tâche", value: "faite", example: t("notes.markdown.search.filters.task_done") },
    { key: "a", value: "commentaire", example: t("notes.markdown.search.filters.has_comment") },
    { key: "a", value: "image", example: t("notes.markdown.search.filters.has_image") },
    { key: "modifiée", value: `>${new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10)}`, example: t("notes.markdown.search.filters.modified") },
    { key: "titre", example: t("notes.markdown.search.filters.in_title") },
]);

const tagFacets = computed(() => Object.entries(facets.value.tags ?? {}));

const FIELD_ICONS = { heading: FileText, property: SlidersHorizontal, comment: MessageSquare, content: null };

function placeOf(result) {
    const where = [];
    if (!result.spacePersonal && result.spaceName) where.push(result.spaceName);
    if (result.folderName) where.push(result.folderName);

    return where.join(" › ");
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="4xl"
        :title="t('notes.markdown.search.title')"
        :icon="Search"
        mobile-fullscreen
        v-on:close="emit('close')"
    >
        <div data-note-search class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <div class="relative min-w-0 flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" :stroke-width="2" />
                    <input
                        ref="field"
                        v-model="query"
                        data-note-search-field
                        type="search"
                        class="block w-full rounded-md border border-line bg-surface py-2 pl-9 pr-3 text-sm text-primary placeholder-muted transition focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                        :placeholder="t('notes.markdown.search.placeholder')"
                        :aria-label="t('notes.markdown.search.title')"
                        v-on:keydown="onKeydown"
                    >
                </div>
                <div class="relative">
                    <button
                        type="button"
                        data-note-search-filters
                        class="inline-flex h-9 items-center gap-1.5 rounded-md border border-line px-3 text-sm text-secondary transition-colors hover:bg-surface-2 hover:text-primary"
                        :aria-expanded="filtersOpen"
                        v-on:click="filtersOpen = !filtersOpen"
                    >
                        <Filter class="h-4 w-4" :stroke-width="2" />
                        {{ t('notes.markdown.search.filters.title') }}
                    </button>
                    <ul
                        v-if="filtersOpen"
                        class="absolute right-0 top-full z-20 m-0 mt-1 w-80 list-none rounded-md border border-line bg-surface p-1 shadow-xl"
                    >
                        <li v-for="filter in FILTERS" :key="filter.key + (filter.value ?? '')">
                            <button
                                type="button"
                                class="flex w-full items-baseline gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-surface-2"
                                v-on:click="addFilter(filter.key, filter.value ?? '')"
                            >
                                <code class="shrink-0 font-mono text-xs text-accent-600 dark:text-accent-300">{{ filter.key }}:{{ filter.value ?? '' }}</code>
                                <span class="min-w-0 truncate text-xs text-muted">{{ filter.example }}</span>
                            </button>
                        </li>
                        <li class="border-t border-line px-2 pt-1.5 pb-1 text-xs text-muted">{{ t('notes.markdown.search.filters.syntax') }}</li>
                    </ul>
                </div>
                <button
                    v-if="query.trim()"
                    type="button"
                    data-note-search-pin
                    class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-line text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                    :title="isPinned ? t('notes.markdown.search.unpin') : t('notes.markdown.search.pin')"
                    :aria-label="isPinned ? t('notes.markdown.search.unpin') : t('notes.markdown.search.pin')"
                    v-on:click="togglePin"
                >
                    <PinOff v-if="isPinned" class="h-4 w-4" :stroke-width="2" />
                    <Pin v-else class="h-4 w-4" :stroke-width="2" />
                </button>
            </div>

            <!-- Before anything is typed: the pinned searches and the recent ones. -->
            <template v-if="!query.trim()">
                <section v-if="pinned.length" class="flex flex-col gap-1">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ t('notes.markdown.search.pinned') }}</h3>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="saved in pinned"
                            :key="saved"
                            type="button"
                            data-note-search-saved
                            class="inline-flex items-center gap-1 rounded-full border border-line px-2.5 py-1 text-xs text-secondary hover:bg-surface-2 hover:text-primary"
                            v-on:click="query = saved"
                        >
                            <Pin class="h-3 w-3" :stroke-width="2" />{{ saved }}
                        </button>
                    </div>
                </section>
                <section v-if="recent.length" class="flex flex-col gap-1">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ t('notes.markdown.search.recent') }}</h3>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="past in recent"
                            :key="past"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border border-line px-2.5 py-1 text-xs text-secondary hover:bg-surface-2 hover:text-primary"
                            v-on:click="query = past"
                        >
                            <Clock class="h-3 w-3" :stroke-width="2" />{{ past }}
                        </button>
                    </div>
                </section>
                <p class="text-sm text-muted">{{ t('notes.markdown.search.hint') }}</p>
            </template>

            <template v-else>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex gap-1 rounded-lg border border-line bg-surface-2 p-1">
                        <AppTab
                            v-for="option in ['relevance', 'date']"
                            :key="option"
                            size="xs"
                            :data-note-search-sort="option"
                            :active="sort === option"
                            active-class="bg-surface text-primary shadow-sm"
                            inactive-class="text-secondary hover:text-primary"
                            v-on:click="sort = option"
                        >
                            {{ t(`notes.markdown.search.sort.${option}`) }}
                        </AppTab>
                    </div>
                    <span data-note-search-count class="text-xs text-muted">
                        {{ loading ? '…' : t('notes.markdown.search.count', { count: total, ms: Math.round(tookMs) }) }}
                    </span>
                </div>

                <!-- What narrows the search: one click adds the filter. -->
                <div v-if="tagFacets.length || facets.folders.length || facets.spaces.length > 1" class="flex flex-wrap gap-1.5">
                    <button
                        v-for="[tag, count] in tagFacets"
                        :key="`tag-${tag}`"
                        type="button"
                        data-note-search-facet
                        class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs text-secondary hover:text-primary"
                        v-on:click="addFilter('tag', tag)"
                    >
                        <TagIcon class="h-3 w-3" :stroke-width="2" />{{ tag }} <span class="text-muted">{{ count }}</span>
                    </button>
                    <button
                        v-for="folder in facets.folders"
                        :key="`folder-${folder.id}`"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs text-secondary hover:text-primary"
                        v-on:click="addFilter('dossier', folder.name)"
                    >
                        › {{ folder.name }} <span class="text-muted">{{ folder.count }}</span>
                    </button>
                    <template v-if="facets.spaces.length > 1">
                        <button
                            v-for="space in facets.spaces.filter((one) => one.name)"
                            :key="`space-${space.id}`"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs text-secondary hover:text-primary"
                            v-on:click="addFilter('espace', space.name)"
                        >
                            {{ space.name }} <span class="text-muted">{{ space.count }}</span>
                        </button>
                    </template>
                </div>

                <p v-if="!loading && 0 === results.length" data-note-search-empty class="py-8 text-center text-sm text-muted">
                    {{ t('notes.markdown.search.none') }}
                </p>

                <ul class="m-0 flex list-none flex-col gap-1 p-0">
                    <li v-for="(result, index) in results" :key="result.id">
                        <button
                            type="button"
                            :data-note-search-result="index"
                            class="flex w-full flex-col gap-1 rounded-lg border px-3 py-2 text-left transition-colors"
                            :class="index === active ? 'border-accent-500/50 bg-surface-2' : 'border-transparent hover:bg-surface-2'"
                            v-on:mouseenter="active = index"
                            v-on:click="open(result)"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <span v-if="result.icon" class="shrink-0">{{ result.icon }}</span>
                                <FileText v-else class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                                <span class="min-w-0 truncate text-sm font-medium text-primary">
                                    <template v-for="(part, partIndex) in highlightParts(result.title || t('notes.markdown.untitled'), result.titleRanges)" :key="partIndex">
                                        <mark v-if="part.mark" class="rounded bg-amber-300/40 px-0.5 text-inherit dark:bg-amber-400/30">{{ part.text }}</mark>
                                        <template v-else>{{ part.text }}</template>
                                    </template>
                                </span>
                                <span v-if="placeOf(result)" class="min-w-0 truncate text-xs text-muted">{{ placeOf(result) }}</span>
                                <span class="ml-auto shrink-0 text-2xs text-muted">{{ formatDateShort(result.updatedAt) }}</span>
                                <span v-if="result.count > 1" class="shrink-0 rounded-full bg-surface-3 px-1.5 text-2xs text-secondary">{{ result.count }}</span>
                            </span>
                            <span
                                v-for="(snippet, snippetIndex) in result.snippets"
                                :key="snippetIndex"
                                data-note-search-snippet
                                class="flex min-w-0 items-start gap-1.5 pl-6 text-xs text-secondary"
                            >
                                <component :is="FIELD_ICONS[snippet.field]" v-if="FIELD_ICONS[snippet.field]" class="mt-0.5 h-3 w-3 shrink-0 text-muted" :stroke-width="2" />
                                <span class="line-clamp-2 min-w-0 break-words">
                                    <template v-for="(part, partIndex) in highlightParts(snippet.text, snippet.ranges)" :key="partIndex">
                                        <mark v-if="part.mark" class="rounded bg-amber-300/40 px-0.5 text-inherit dark:bg-amber-400/30">{{ part.text }}</mark>
                                        <template v-else>{{ part.text }}</template>
                                    </template>
                                </span>
                            </span>
                        </button>
                    </li>
                </ul>
                <p v-if="total > results.length" class="text-center text-xs text-muted">{{ t('notes.markdown.search.more', { shown: results.length, total }) }}</p>
            </template>
        </div>
        <template #footer>
            <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-2xs text-muted">
                <span><kbd class="rounded border border-line px-1">↑</kbd> <kbd class="rounded border border-line px-1">↓</kbd> {{ t('notes.markdown.search.keys.move') }}</span>
                <span><kbd class="rounded border border-line px-1">↵</kbd> {{ t('notes.markdown.search.keys.open') }}</span>
                <span><kbd class="rounded border border-line px-1">Esc</kbd> {{ t('notes.markdown.search.keys.close') }}</span>
                <button type="button" class="ml-auto inline-flex items-center gap-1 hover:text-primary" v-on:click="query = ''">
                    <X class="h-3 w-3" :stroke-width="2" />{{ t('notes.markdown.search.clear') }}
                </button>
            </p>
        </template>
    </AppModal>
</template>
