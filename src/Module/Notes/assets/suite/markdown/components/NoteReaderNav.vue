<script setup>
/**
 * The reader's tree: the whole notebook, for reading.
 *
 * The same rows as the menu panel, read-only: a folder expands, a note leads
 * to its reading, nothing is added, dragged or renamed. Expansion is the
 * same as the panel's - the open folders carry over from one space to the
 * other - and the note being read lights up, its folder open.
 *
 * Grouped by space, like the panel: one's own first, then each shared space
 * under its name. A single space needs no header.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Share2, User, Users } from "lucide-vue-next";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import NoteTreeItem from "./NoteTreeItem.vue";
import { folderIdsIn, useNoteTree } from "../composables/useNoteTree.js";
import { detachSharedNotes, sortSpaces, spaceLabel, spacesWithShared } from "../composables/noteSpaces.js";
import { readExpanded, storeExpanded } from "../composables/expandedStore.js";

const props = defineProps({
    noteId: { type: Number, required: true },
    folders: { type: Array, default: () => [] },
    notes: { type: Array, default: () => [] },
    /** The readable spaces; the tree is split by them. */
    spaces: { type: Array, default: () => [] },
    /**
     * The notes handed to this reader one by one, as `id => role`.
     *
     * They live in spaces this reader does not have, so they are grouped
     * apart rather than filed under a notebook that means nothing to them.
     */
    sharedNotes: { type: Object, default: () => ({}) },
    readNotePath: { type: String, required: true },
    /** The search in the text, server side: the bodies are encrypted. */
    searchPath: { type: String, default: "" },
});

const emit = defineEmits(["navigate"]);

const { t } = useI18n();

const query = ref("");
const searching = computed(() => "" !== query.value.trim());

const foldersRef = computed(() => props.folders);
// Detached first: a note handed over on its own is filed in a folder of a
// space this reader does not have, and the tree only draws notes whose folder
// it knows - so it would vanish with it.
const notesRef = computed(() => detachSharedNotes(props.notes, props.sharedNotes));
/**
 * The notes' text, searched on the server side, as in the panel: without it
 * the same search box found by body on one side and only by title on the
 * other.
 */
const { request } = useRequest();
const contentMatchIds = ref(new Set());

const runContentSearch = useDebounce(async (value) => {
    const payload = await request(`${props.searchPath}?q=${encodeURIComponent(value)}`, null, {
        method: HttpMethod.Get,
        noGuard: true,
    });

    contentMatchIds.value = new Set((payload?.ids ?? []).map(Number));
}, 300);

watch(query, (value) => {
    const trimmed = value.trim();

    if ("" === trimmed || !props.searchPath) {
        contentMatchIds.value = new Set();

        return;
    }

    runContentSearch(trimmed);
});

const { tree } = useNoteTree(foldersRef, query, notesRef, contentMatchIds);

const readUrl = (id) => props.readNotePath.replace("__id__", String(id));
const hrefFor = (node) => ("note" === node.kind ? readUrl(node.id) : "#");

/**
 * What is open: what the person expanded, plus the path of the note being
 * read - one lands on a note, one must see it in the tree.
 */
function initialOpen() {
    const open = readExpanded();
    const parents = new Map(props.folders.map((folder) => [Number(folder.id), null == folder.parentId ? null : Number(folder.parentId)]));
    const note = props.notes.find((one) => Number(one.id) === props.noteId);

    let id = null == note?.folderId ? null : Number(note.folderId);

    for (let guard = 0; null !== id && guard <= parents.size; guard += 1) {
        open.add(id);
        id = parents.get(id) ?? null;
    }

    return open;
}

const opened = ref(initialOpen());
const expanded = computed(() => (searching.value ? folderIdsIn(tree.value) : opened.value));

function toggle(node) {
    const next = new Set(opened.value);
    const id = Number(node.id);

    if (next.has(id)) next.delete(id);
    else next.add(id);

    opened.value = next;
    storeExpanded(next);
}

/** A note opens for reading, a folder expands. */
function onSelect(node) {
    if ("note" !== node.kind) {
        toggle(node);

        return;
    }

    emit("navigate");
    window.location.assign(readUrl(node.id));
}

/** The tree split by space; a top-level row tells its own. */
const groups = computed(() => {
    const spaces = sortSpaces(spacesWithShared(props.spaces, props.sharedNotes));

    if (!spaces.length) return [{ space: null, nodes: tree.value }];

    const bySpace = new Map(spaces.map((space) => [Number(space.id), []]));
    const unplaced = [];

    for (const node of tree.value) {
        (bySpace.get(Number(node.spaceId)) ?? unplaced).push(node);
    }

    const list = spaces.map((space) => ({ space, nodes: bySpace.get(Number(space.id)) }));
    if (unplaced.length) list[0].nodes = [...list[0].nodes, ...unplaced];

    return list.filter((group) => group.nodes.length);
});

// Always, like the panel: without a header, nothing said which space one was
// reading in. Public reading receives none, and shows none.
const showHeaders = computed(() => groups.value.some((group) => null !== group.space));
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-2">
        <AppSearchInput v-model="query" :placeholder="t('notes.markdown.search_placeholder')" />

        <nav
            data-reader-tree
            role="tree"
            class="min-h-0 flex-1 space-y-0.5 overflow-y-auto"
            :aria-label="t('notes.markdown.title')"
        >
            <p v-if="searching && !tree.length" class="px-3 py-1 text-xs text-muted">
                {{ t('notes.markdown.search_no_results') }}
            </p>

            <template v-for="group in groups" :key="group.space ? `space:${group.space.id}` : 'all'">
                <p
                    v-if="showHeaders && group.space"
                    :data-reader-space="group.space.id"
                    class="mt-3 flex min-w-0 items-center gap-1.5 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted first:mt-0"
                >
                    <component
                        :is="group.space.shared ? Share2 : (group.space.personal ? User : Users)"
                        class="h-3.5 w-3.5 shrink-0"
                        :style="group.space.color ? { color: group.space.color } : null"
                        :stroke-width="2"
                    />
                    <span class="min-w-0 truncate">{{ spaceLabel(group.space, t) }}</span>
                </p>

                <NoteTreeItem
                    v-for="node in group.nodes"
                    :key="node.key"
                    :node="node"
                    :selected-key="`note:${noteId}`"
                    :expanded="expanded"
                    :readonly="true"
                    :href-for="hrefFor"
                    v-on:select="onSelect"
                    v-on:toggle="toggle"
                />
            </template>
        </nav>
    </div>
</template>
