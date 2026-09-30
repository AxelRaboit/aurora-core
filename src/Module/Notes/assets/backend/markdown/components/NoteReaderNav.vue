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
 * Ce qui est partagé par les autres vient après, à part, comme dans le
 * panneau : ce qui n'est pas à soi ne se range pas dans son arbre.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, Users } from "lucide-vue-next";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import NoteTreeItem from "./NoteTreeItem.vue";
import { folderIdsIn, useNoteTree } from "../composables/useNoteTree.js";

const props = defineProps({
    noteId: { type: Number, required: true },
    folders: { type: Array, default: () => [] },
    notes: { type: Array, default: () => [] },
    sharedFolders: { type: Array, default: () => [] },
    sharedNotes: { type: Array, default: () => [] },
    readNotePath: { type: String, required: true },
});

const emit = defineEmits(["navigate"]);

const { t } = useI18n();

const EXPANDED_KEY = "aurora.notes.panel.expanded";

const query = ref("");
const searching = computed(() => "" !== query.value.trim());

const foldersRef = computed(() => props.folders);
const notesRef = computed(() => props.notes);
const { tree } = useNoteTree(foldersRef, query, notesRef);

const readUrl = (id) => props.readNotePath.replace("__id__", String(id));
const hrefFor = (node) => ("note" === node.kind ? readUrl(node.id) : "#");

function readStored() {
    try {
        const ids = JSON.parse(window.localStorage.getItem(EXPANDED_KEY) ?? "[]");

        return new Set(Array.isArray(ids) ? ids.map(Number) : []);
    } catch {
        return new Set();
    }
}

function store(ids) {
    try {
        window.localStorage.setItem(EXPANDED_KEY, JSON.stringify([...ids]));
    } catch {
        // Une habitude de lecture, pas un état du carnet.
    }
}

/**
 * Ce qui est ouvert : ce que la personne a déplié, plus le chemin de la note
 * lue - on arrive sur une note, on doit la voir dans l'arbre.
 */
function initialOpen() {
    const open = readStored();
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
    store(next);
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

/** Ce qu'on a partagé avec soi, regroupé par dossier partagé d'origine. */
const sharedGroups = computed(() => {
    if (searching.value) return [];

    const byId = new Map(props.sharedFolders.map((one) => [Number(one.id), one]));

    const rootOf = (folderId) => {
        let current = byId.get(Number(folderId));

        for (let guard = 0; current && guard <= byId.size; guard += 1) {
            if (!byId.has(Number(current.parentId))) return Number(current.id);

            current = byId.get(Number(current.parentId));
        }

        return null;
    };

    const groups = new Map();
    const loose = [];

    for (const note of props.sharedNotes) {
        const root = null == note.folderId ? null : rootOf(note.folderId);

        if (null === root) {
            loose.push(note);

            continue;
        }

        const sub = Number(note.folderId) === root ? null : byId.get(Number(note.folderId))?.name ?? null;

        if (!groups.has(root)) groups.set(root, []);

        groups.get(root).push({ ...note, subfolder: sub });
    }

    const list = [...groups.entries()].map(([id, notes]) => ({
        key: `folder:${id}`,
        name: byId.get(id)?.name ?? "",
        owner: byId.get(id)?.ownerName ?? null,
        notes,
    }));

    return loose.length ? [...list, { key: "loose", name: null, owner: null, notes: loose }] : list;
});
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

            <NoteTreeItem
                v-for="node in tree"
                :key="node.key"
                :node="node"
                :selected-key="`note:${noteId}`"
                :expanded="expanded"
                :readonly="true"
                :href-for="hrefFor"
                v-on:select="onSelect"
                v-on:toggle="toggle"
            />

            <div v-if="sharedGroups.length" class="mt-3 border-t border-line pt-2">
                <p class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                    {{ t('notes.markdown.library.shared.section') }}
                </p>

                <div v-for="group in sharedGroups" :key="group.key" class="mb-1">
                    <p
                        v-if="group.name"
                        class="flex min-w-0 items-center gap-2 px-3 py-1 text-sm text-secondary"
                        :title="group.owner ? t('notes.markdown.library.shared.by', { name: group.owner }) : undefined"
                    >
                        <Users class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                        <span class="min-w-0 flex-1 truncate">{{ group.name }}</span>
                    </p>

                    <a
                        v-for="note in group.notes"
                        :key="`shared-${note.id}`"
                        :href="readUrl(note.id)"
                        data-reader-shared
                        class="flex min-w-0 items-center gap-2 rounded-md py-1.5 pl-6 pr-3 text-sm no-underline transition-colors"
                        :class="note.id === noteId ? 'bg-accent-600/15 text-accent-400' : 'text-primary hover:bg-surface-2'"
                        :title="note.ownerName ? t('notes.markdown.library.shared.by', { name: note.ownerName }) : undefined"
                    >
                        <FileText class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                        <span class="min-w-0 flex-1 truncate">
                            <span v-if="note.subfolder" class="text-muted">{{ note.subfolder }} › </span>{{ note.title || t('notes.markdown.untitled') }}
                        </span>
                    </a>
                </div>
            </div>
        </nav>
    </div>
</template>
