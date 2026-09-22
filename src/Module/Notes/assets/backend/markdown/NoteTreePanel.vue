<script setup>
/**
 * Le rangement, dans le menu latéral.
 *
 * C'était l'arborescence des notes, du temps où une note qui avait des
 * enfants tenait lieu de dossier : neuf cents notes n'ont jamais été une
 * arborescence lisible. Le panneau ne porte plus que **des dossiers**, avec
 * « Tous les documents » en tête, et ce qu'il y a dedans se regarde dans la
 * bibliothèque, qui est faite pour ça.
 *
 * **Les lignes sont de vraies adresses.** Un dossier est une page
 * (`/backend/notes/markdown/folder/42`), donc il s'envoie et le clic du
 * milieu se comporte. Au clic simple le panneau demande d'abord à la page,
 * par `modulePanelBridge` : la bibliothèque est montée, elle prend le clic et
 * change de dossier sur place. Personne à l'écoute veut dire que le lecteur
 * est ailleurs dans le module, et le lien navigue.
 *
 * **La recherche, elle, reste globale.** C'est le seul endroit d'où l'on
 * cherche une note dans tout le carnet : la bibliothèque filtre le dossier
 * ouvert, ce qui est ce qu'on attend d'un explorateur, et pas ce qu'on
 * attend d'un champ de recherche. Les notes trouvées s'affichent sous les
 * dossiers, à plat, avec leur dossier en légende.
 */
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Download, FileText, Folder, FolderPlus, Plus, Upload } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";
import { useModulePanelData } from "@/shared/nav/useModulePanelData.js";
import { useNoteTree } from "./composables/useNoteTree.js";
import NoteTreeItem from "./components/NoteTreeItem.vue";

const FOLDERS_ENDPOINT = "/backend/notes/markdown/folders";
const NOTES_ENDPOINT = "/backend/notes/markdown/list";
const SEARCH_ENDPOINT = "/backend/notes/markdown/search";
const LIBRARY_URL = "/backend/notes/markdown";

const { t } = useI18n();

const {
    data: fetchedFolders,
    loading,
    failed,
} = useModulePanelData(FOLDERS_ENDPOINT, { key: "folders" });

const { data: fetchedNotes } = useModulePanelData(NOTES_ENDPOINT, {
    key: "notes",
});

/**
 * Ce que la page annonce l'emporte sur ce que le panneau a cherché.
 *
 * On charge à l'arrivée parce que le panneau peut être rendu avant que la
 * page soit montée ; ensuite la page annonce chaque changement, ce qui fait
 * apparaître ici un dossier créé là-bas sans recharger.
 */
const announcedFolders = ref(null);
const announcedNotes = ref(null);

const folders = computed(() => announcedFolders.value ?? fetchedFolders.value);
const notes = computed(() => announcedNotes.value ?? fetchedNotes.value);

const selectedId = ref(null);
const treeQuery = ref("");

const { tree } = useNoteTree(folders, treeQuery);

const isEmpty = computed(() => 0 === folders.value.length);

const searching = computed(() => "" !== treeQuery.value.trim());

/**
 * Le texte des notes, cherché côté serveur.
 *
 * Les corps ne sont pas dans le navigateur, et ils sont chiffrés en base :
 * c'est l'endpoint `/search` qui déchiffre les notes de la personne et rend
 * les identifiants qui correspondent. Sans lui, chercher « facture » ne
 * trouverait que les notes qui ont ce mot dans leur titre, ce qui est
 * rarement là où on l'a écrit.
 */
const { request } = useRequest();
const contentMatchIds = ref(new Set());

const runContentSearch = useDebounce(async (query) => {
    const payload = await request(
        `${SEARCH_ENDPOINT}?q=${encodeURIComponent(query)}`,
        null,
        { method: HttpMethod.Get, noGuard: true },
    );

    contentMatchIds.value = new Set(
        (payload?.ids ?? []).map((id) => Number(id)),
    );
}, 300);

watch(treeQuery, (value) => {
    const trimmed = value.trim();

    if ("" === trimmed) {
        contentMatchIds.value = new Set();

        return;
    }

    runContentSearch(trimmed);
});

/** Les notes qui correspondent, dans tout le carnet, titre et étiquettes. */
const matchingNotes = computed(() => {
    if (!searching.value) return [];

    const needle = treeQuery.value.trim().toLowerCase();

    return notes.value
        .filter((note) => {
            const title = String(note.title ?? "").toLowerCase();
            if (title.includes(needle)) return true;

            if (contentMatchIds.value.has(Number(note.id))) return true;

            return (note.tags ?? []).some((tag) =>
                String(tag).toLowerCase().includes(needle),
            );
        })
        .slice(0, 50);
});

/** Un dossier est une page : la ligne offre son adresse pour tout le reste. */
const hrefFor = (folder) => `${LIBRARY_URL}/folder/${folder.id}`;

const noteHrefFor = (note) => `${LIBRARY_URL}/${note.id}`;

/**
 * Ce qui est épinglé, dossiers puis notes, le plus récent d'abord.
 *
 * Craft ouvre son menu là-dessus, et c'est le seul endroit du module d'où
 * l'on atteint une note en un clic sans savoir où elle est rangée. Caché
 * pendant une recherche : la liste des résultats répond déjà à la question
 * posée.
 */
const favorites = computed(() => {
    if (searching.value) return [];

    const pinned = (items, kind) =>
        items
            .filter((one) => Boolean(one.favoritedAt))
            .map((item) => ({ kind, item }));

    return [
        ...pinned(folders.value, "folder"),
        ...pinned(notes.value, "note"),
    ].sort((a, b) => Date.parse(b.item.favoritedAt) - Date.parse(a.item.favoritedAt));
});

function favoriteLabel({ kind, item }) {
    if ("folder" === kind) {
        return item.name || t("notes.markdown.folders.untitled");
    }

    return item.title || t("notes.markdown.untitled");
}

function favoriteHref({ kind, item }) {
    return "folder" === kind ? hrefFor(item) : noteHrefFor(item);
}

function onFavoriteClick(entry, event) {
    event.preventDefault();

    if ("folder" === entry.kind) {
        onSelect(entry.item.id);

        return;
    }

    forward("select", entry.item.id);
}

const foldersById = computed(() => {
    const map = new Map();
    for (const folder of folders.value) map.set(Number(folder.id), folder);

    return map;
});

function folderNameOf(note) {
    const folder = note.folderId
        ? foldersById.value.get(Number(note.folderId))
        : null;

    return folder?.name || t("notes.markdown.library.title");
}

/**
 * Notre propre copie de ce qui est glissé, pour que les lignes s'allument.
 *
 * La page tient le même état - il le faut, c'est elle qui écrit - mais le
 * refléter ici coûte une affectation par événement qu'on transmet déjà, là
 * où le relire demanderait une annonce à chaque `dragover`.
 */
const draggingId = ref(null);
const dragOverId = ref(null);

function forward(name, ...args) {
    askPage(`notes:${name}`, { args });
}

function onSelect(id) {
    selectedId.value = id;
    forward("open-folder", id);
}

function onDragStart(folder, event) {
    draggingId.value = folder.id;
    forward("drag-start", folder, event);
}

function onDragEnd() {
    draggingId.value = null;
    dragOverId.value = null;
    forward("drag-end");
}

function onDragOver(folder, event) {
    if (folder.id !== draggingId.value) dragOverId.value = folder.id;
    forward("drag-over", folder, event);
}

function onDragLeave(folder, event) {
    if (dragOverId.value === folder.id) dragOverId.value = null;
    forward("drag-leave", folder, event);
}

function onDrop(folder, event) {
    dragOverId.value = null;
    draggingId.value = null;
    forward("drop", folder, event);
}

function onNoteClick(note, event) {
    event.preventDefault();
    forward("select", note.id);
}

const stopListening = [];

onMounted(() => {
    stopListening.push(
        onPageNotice("notes:changed", (detail) => {
            if (Array.isArray(detail?.notes)) announcedNotes.value = detail.notes;
            if (Array.isArray(detail?.folders)) {
                announcedFolders.value = detail.folders;
            }
            if ("folderId" in (detail ?? {})) selectedId.value = detail.folderId;
        }),
    );
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});
</script>

<template>
    <AppModulePanel
        :title="t('notes.markdown.title')"
        :loading="loading"
        :failed="failed"
    >
        <template #action>
            <!-- Emporter et rendre, à côté de « nouveau dossier » et
                 « nouvelle note » : ce sont des gestes sur le carnet entier,
                 pas sur une note. -->
            <AppIconButton
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.import.button')"
                v-on:click="forward('import')"
            >
                <Upload class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.export.all')"
                v-on:click="forward('export')"
            >
                <Download class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.folders.create')"
                v-on:click="forward('create-folder', selectedId)"
            >
                <FolderPlus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.create_root')"
                v-on:click="forward('create', selectedId)"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
        </template>

        <!-- Pas de retrait horizontal : les lignes de l'arborescence portent
             le leur à l'intérieur et occupent toute la largeur du panneau. -->
        <div class="pb-1">
            <AppSearchInput
                v-model="treeQuery"
                :placeholder="t('notes.markdown.search_placeholder')"
            />
        </div>

        <!-- La racine est une ligne comme les autres : c'est là qu'on
             retombe, et une arborescence sans son sommet oblige à deviner
             comment y revenir. -->
        <a
            :href="LIBRARY_URL"
            class="group mb-0.5 flex min-w-0 items-center gap-2 rounded-lg border border-transparent px-3 py-2 text-sm no-underline transition-colors"
            :class="null === selectedId ? 'border-accent-600/30 bg-accent-600/15 text-accent-400' : 'text-primary hover:bg-surface-2'"
            v-on:click.prevent="onSelect(null)"
        >
            <FileText class="h-4 w-4 shrink-0" :stroke-width="2" />
            <span class="flex-1 truncate">{{ t('notes.markdown.library.title') }}</span>
        </a>

        <!-- Les favoris, avant l'arborescence : ce qu'on vient chercher
             tous les jours n'a pas à se retrouver dans un arbre. -->
        <div v-if="favorites.length" class="mb-2 border-b border-line pb-2">
            <p class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                {{ t('notes.markdown.library.favorites') }}
            </p>

            <a
                v-for="entry in favorites"
                :key="`${entry.kind}-${entry.item.id}`"
                :data-favorite-row="`${entry.kind}-${entry.item.id}`"
                :href="favoriteHref(entry)"
                class="flex min-w-0 items-center gap-2 rounded-lg px-3 py-2 text-sm text-primary no-underline transition-colors hover:bg-surface-2"
                v-on:click="onFavoriteClick(entry, $event)"
            >
                <component
                    :is="'folder' === entry.kind ? Folder : FileText"
                    class="h-4 w-4 shrink-0 text-muted"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ favoriteLabel(entry) }}</span>
            </a>
        </div>

        <p v-if="isEmpty && !searching" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.folders.tree_empty") }}
        </p>

        <NoteTreeItem
            v-for="node in tree"
            :key="node.id"
            :node="node"
            :selected-id="selectedId"
            :draggable="true"
            :dragging-id="draggingId"
            :drag-over-id="dragOverId"
            :href-for="hrefFor"
            v-on:select="onSelect"
            v-on:create-note="(id) => forward('create', id)"
            v-on:delete="(folder) => forward('delete-folder', folder)"
            v-on:drag-start="onDragStart"
            v-on:drag-end="onDragEnd"
            v-on:drag-over="onDragOver"
            v-on:drag-leave="onDragLeave"
            v-on:drop="onDrop"
        />

        <!-- Les notes trouvées, à plat : une recherche ne répond pas par une
             arborescence, elle répond par une liste. -->
        <div v-if="searching" class="mt-2 border-t border-line pt-2">
            <p v-if="!matchingNotes.length" class="px-3 py-1 text-xs text-muted">
                {{ t("notes.markdown.search_no_results") }}
            </p>

            <a
                v-for="note in matchingNotes"
                :key="note.id"
                :href="noteHrefFor(note)"
                class="flex min-w-0 items-center gap-2 rounded-lg px-3 py-2 text-sm no-underline text-primary transition-colors hover:bg-surface-2"
                v-on:click="onNoteClick(note, $event)"
            >
                <FileText class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                <span class="min-w-0 flex-1 truncate">
                    {{ note.title || t('notes.markdown.untitled') }}
                </span>
                <span class="shrink-0 truncate text-xs text-muted">{{ folderNameOf(note) }}</span>
            </a>
        </div>
    </AppModulePanel>
</template>
