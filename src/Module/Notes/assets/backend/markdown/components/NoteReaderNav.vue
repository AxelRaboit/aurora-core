<script setup>
/**
 * L'arborescence du lecteur : tout le carnet, en lecture.
 *
 * Les mêmes lignes que le panneau du menu, en lecture seule : un dossier se
 * déplie, une note mène à sa lecture, rien ne s'ajoute, ne se glisse ni ne se
 * renomme. Le dépliage est le même que celui du panneau - on retrouve les
 * dossiers ouverts d'un espace à l'autre - et la note lue s'allume, son
 * dossier ouvert.
 *
 * Rangé par espace, comme le panneau : le sien d'abord, puis chaque espace
 * partagé sous son nom. Un seul espace n'a pas besoin d'en-tête.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { User, Users } from "lucide-vue-next";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import NoteTreeItem from "./NoteTreeItem.vue";
import { folderIdsIn, useNoteTree } from "../composables/useNoteTree.js";
import { sortSpaces, spaceLabel } from "../composables/noteSpaces.js";
import { readExpanded, storeExpanded } from "../composables/expandedStore.js";

const props = defineProps({
    noteId: { type: Number, required: true },
    folders: { type: Array, default: () => [] },
    notes: { type: Array, default: () => [] },
    /** Les espaces lisibles ; l'arbre se découpe par eux. */
    spaces: { type: Array, default: () => [] },
    readNotePath: { type: String, required: true },
    /** La recherche dans le texte, côté serveur : les corps sont chiffrés. */
    searchPath: { type: String, default: "" },
});

const emit = defineEmits(["navigate"]);

const { t } = useI18n();

const query = ref("");
const searching = computed(() => "" !== query.value.trim());

const foldersRef = computed(() => props.folders);
const notesRef = computed(() => props.notes);
/**
 * Le texte des notes, cherché côté serveur, comme dans le panneau : sans lui
 * la même boîte de recherche trouvait par le corps d'un côté et seulement par
 * le titre de l'autre.
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
 * Ce qui est ouvert : ce que la personne a déplié, plus le chemin de la note
 * lue - on arrive sur une note, on doit la voir dans l'arbre.
 */
function initialOpen() {
    const open = readExpanded();
    const parents = new Map(props.folders.map((f) => [Number(f.id), null == f.parentId ? null : Number(f.parentId)]));
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

/** Une note s'ouvre en lecture, un dossier se déplie. */
function onSelect(node) {
    if ("note" !== node.kind) {
        toggle(node);

        return;
    }

    emit("navigate");
    window.location.assign(readUrl(node.id));
}

/** L'arbre découpé par espace ; une ligne de premier niveau dit le sien. */
const groups = computed(() => {
    const spaces = sortSpaces(props.spaces);

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

// Toujours, comme le panneau : sans en-tête, rien ne disait dans quel
// espace on lisait. La lecture publique n'en reçoit pas, et n'en montre pas.
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
                        :is="group.space.personal ? User : Users"
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
