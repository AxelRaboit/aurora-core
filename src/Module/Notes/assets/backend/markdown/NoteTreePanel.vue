<script setup>
/**
 * Le carnet, dans le menu latéral.
 *
 * Il n'a porté que des dossiers pendant une version, et c'était une
 * demi-réponse : on voyait le rangement sans voir ce qui est rangé. **Un
 * dossier se déplie ici** et montre ses notes, comme dans n'importe quel
 * explorateur ; la bibliothèque reste l'écran où l'on regarde, trie et
 * range, le panneau celui d'où l'on atteint.
 *
 * **Le dépliage se retient.** Il vit dans le navigateur, pas dans le
 * carnet : c'est une habitude de lecture, elle ne regarde que la personne
 * assise là, et elle doit survivre au changement de page - le panneau est
 * remonté à chaque navigation.
 *
 * **Les lignes sont de vraies adresses.** Un dossier est une page
 * (`/backend/notes/markdown/folder/42`), une note aussi, donc les deux
 * s'envoient et le clic du milieu se comporte. Au clic simple le panneau
 * demande d'abord à la page, par `modulePanelBridge` : elle est montée, elle
 * prend le clic et change de dossier ou de note sur place. Personne à
 * l'écoute veut dire que le lecteur est ailleurs dans le module, et le lien
 * navigue.
 *
 * **La recherche reste globale**, et c'est le seul endroit où elle l'est :
 * elle traverse les titres, les étiquettes et le texte des notes - ce
 * dernier côté serveur, les corps étant chiffrés - et l'arbre s'ouvre sur ce
 * qu'elle a trouvé.
 */
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown, ChevronRight, Download, FileText, Folder, FolderPlus, Pin, PinOff, Plus, Tag, Upload, Users } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";
import { useModulePanelData } from "@/shared/nav/useModulePanelData.js";
import { folderIdsIn, useNoteTree } from "./composables/useNoteTree.js";
import { startNoteDrag } from "./composables/noteDrag.js";
import NoteTreeItem from "./components/NoteTreeItem.vue";

const FOLDERS_ENDPOINT = "/backend/notes/markdown/folders";
const NOTES_ENDPOINT = "/backend/notes/markdown/list";
const SEARCH_ENDPOINT = "/backend/notes/markdown/search";
const SHARED_ENDPOINT = "/backend/notes/markdown/shared";
const LIBRARY_URL = "/backend/notes/markdown";
const EXPANDED_KEY = "aurora.notes.panel.expanded";
const PINNED_TAGS_KEY = "aurora.notes.panel.pinnedTags";
const TAGS_OPEN_KEY = "aurora.notes.panel.tagsOpen";

/** Combien d'étiquettes non épinglées on montre avant de replier. */
const TAGS_SHOWN = 8;

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

const selectedKey = ref(null);
const treeQuery = ref("");

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

    contentMatchIds.value = new Set((payload?.ids ?? []).map((id) => Number(id)));
}, 300);

watch(treeQuery, (value) => {
    const trimmed = value.trim();

    if ("" === trimmed) {
        contentMatchIds.value = new Set();

        return;
    }

    runContentSearch(trimmed);
});

const { tree } = useNoteTree(folders, treeQuery, notes, contentMatchIds);

const isEmpty = computed(() => 0 === folders.value.length && 0 === notes.value.length);

// ── Plier, déplier ─────────────────────────────────────────────────

const openedIds = ref(readStoredExpanded());

/**
 * Ce qui est ouvert à l'écran : ce que la personne a déplié, et pendant une
 * recherche, tout ce que l'arbre filtré contient. Une recherche qui laisse
 * les branches fermées ne montre rien, puisque ce qu'elle a trouvé est
 * justement replié.
 */
const expanded = computed(() =>
    searching.value ? folderIdsIn(tree.value) : openedIds.value,
);

/**
 * Déplier ce qu'il faut pour qu'une note soit visible.
 *
 * **Le commentaire d'à côté promettait déjà que « son dossier s'ouvre », et
 * le code ne faisait que l'allumer.** On le voyait en créant une note dans un
 * dossier replié : la note était bien créée et bien sélectionnée, mais elle
 * restait cachée derrière une flèche fermée, et rien ne disait qu'il s'était
 * passé quelque chose.
 *
 * Toute la chaîne, pas seulement le dossier direct : une note rangée à trois
 * niveaux reste invisible si l'on n'ouvre que le dernier. La boucle se garde
 * des cycles en comptant ses tours, parce qu'un parent qui se pointerait
 * lui-même ferait tourner la page sans rien afficher.
 */
function revealNote(noteId) {
    const note = announcedNotes.value.find((n) => Number(n.id) === Number(noteId));

    if (!note?.folderId) return;

    const parents = new Map(
        announcedFolders.value.map((f) => [Number(f.id), Number(f.parentId) || null]),
    );

    const next = new Set(openedIds.value);
    let id = Number(note.folderId);

    for (let garde = 0; null !== id && garde <= parents.size; garde += 1) {
        if (next.has(id)) break;

        next.add(id);
        id = parents.get(id) ?? null;
    }

    openedIds.value = next;
    storeExpanded(next);
}

function toggle(node) {
    const id = Number(node.id);
    const next = new Set(openedIds.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    openedIds.value = next;
    storeExpanded(next);
}

function readStoredExpanded() {
    try {
        const raw = window.localStorage.getItem(EXPANDED_KEY);
        const ids = JSON.parse(raw ?? "[]");

        return new Set(Array.isArray(ids) ? ids.map(Number) : []);
    } catch {
        // Stockage indisponible ou contenu abîmé : l'arbre s'ouvre fermé,
        // ce qui est un défaut d'agrément, pas une panne.
        return new Set();
    }
}

function storeExpanded(ids) {
    try {
        window.localStorage.setItem(EXPANDED_KEY, JSON.stringify([...ids]));
    } catch {
        // Idem : une préférence de lecture, pas un état du carnet.
    }
}

// ── Les favoris ────────────────────────────────────────────────────

/** Un dossier est une page, une note aussi : chacun offre son adresse. */
const hrefFor = (node) =>
    "folder" === node.kind
        ? `${LIBRARY_URL}/folder/${node.id}`
        : `${LIBRARY_URL}/${node.id}`;

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
            .map((item) => ({ ...item, kind, key: `${kind}:${item.id}` }));

    return [
        ...pinned(folders.value, "folder"),
        ...pinned(notes.value, "note"),
    ].sort((a, b) => Date.parse(b.favoritedAt) - Date.parse(a.favoritedAt));
});

// ── Ce que les autres ont partagé ──────────────────────────────────

/**
 * Le carnet des autres, en lecture.
 *
 * **Jamais mêlé au sien.** Ce qui n'appartient pas à la personne ne se
 * range pas dans son arborescence : les mélanger ferait croire qu'on peut
 * déplacer le dossier d'un collègue, et un glisser qui échoue au bout de
 * trois secondes vaut moins qu'une section qui dit ce qu'elle est.
 *
 * Une note partagée mène à la vue de lecture, pas à l'éditeur : elle n'est
 * pas à écrire, et lui ouvrir l'éditeur promettrait le contraire.
 */
const shared = ref({ folders: [], notes: [] });

onMounted(async () => {
    const payload = await request(SHARED_ENDPOINT, null, {
        method: HttpMethod.Get,
        noGuard: true,
    });

    if (payload) {
        shared.value = {
            folders: payload.folders ?? [],
            notes: payload.notes ?? [],
        };
    }
});

/**
 * Les racines du partage, avec ce qu'elles portent.
 *
 * Le serveur rend les dossiers partagés **et** leurs descendants, parce
 * qu'il faut pouvoir descendre ; ici on ne montre que les racines, chacune
 * suivie de ses notes, pour que la section tienne dans un panneau.
 */
const sharedGroups = computed(() => {
    if (searching.value) return [];

    const ids = new Set(shared.value.folders.map((one) => Number(one.id)));
    const racines = shared.value.folders.filter(
        (one) => !ids.has(Number(one.parentId)),
    );

    const parDossier = new Map();
    for (const note of shared.value.notes) {
        const cle = Number(note.folderId) || 0;

        if (!parDossier.has(cle)) parDossier.set(cle, []);

        parDossier.get(cle).push(note);
    }

    const groupes = racines.map((dossier) => ({
        key: `folder:${dossier.id}`,
        name: dossier.name,
        owner: dossier.ownerName,
        notes: parDossier.get(Number(dossier.id)) ?? [],
    }));

    // Les notes partagées seules : celles dont le dossier n'est pas
    // lui-même partagé.
    const seules = shared.value.notes.filter(
        (note) => !ids.has(Number(note.folderId)),
    );

    return seules.length
        ? [...groupes, { key: "loose", name: null, owner: null, notes: seules }]
        : groupes;
});

const hasShared = computed(() => sharedGroups.value.length > 0);

// ── Les étiquettes ─────────────────────────────────────────────────

/**
 * Les étiquettes du carnet, les épinglées d'abord.
 *
 * **L'épinglage vit dans le navigateur, pas en base.** Une étiquette n'est
 * pas une ligne dans Aurora : c'est une chaîne dans le tableau `tags` d'une
 * note, sans identité propre. Lui donner une table serait la première dont
 * les lignes ne désignent rien, et l'écran d'administration des étiquettes
 * - qui renomme, fusionne et supprime - devrait la tenir à jour en trois
 * endroits de plus. Ici, une étiquette disparue disparaît d'elle-même de la
 * liste, puisqu'on n'affiche que celles qui existent encore.
 *
 * Les favoris, eux, sont en base : ils s'accrochent à une note ou à un
 * dossier, c'est-à-dire à quelque chose qui a une ligne.
 */
const pinnedTags = ref(readStoredTags());
const tagsOpen = ref("1" === readStored(TAGS_OPEN_KEY, "1"));
const showAllTags = ref(false);

function readStored(key, fallback) {
    try {
        return window.localStorage.getItem(key) ?? fallback;
    } catch {
        // Navigation privée, cadre restreint : la préférence est un
        // confort, pas un état du carnet.
        return fallback;
    }
}

function readStoredTags() {
    try {
        const raw = JSON.parse(window.localStorage.getItem(PINNED_TAGS_KEY) ?? "[]");

        return Array.isArray(raw) ? raw.filter((one) => "string" === typeof one) : [];
    } catch {
        return [];
    }
}

function store(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Idem : rien à rattraper, la session continue sans mémoire.
    }
}

/** Toutes les étiquettes portées par au moins une note, par fréquence. */
const allTags = computed(() => {
    const counts = new Map();

    for (const note of notes.value) {
        for (const one of note.tags ?? []) {
            if ("string" !== typeof one || "" === one.trim()) continue;

            counts.set(one, (counts.get(one) ?? 0) + 1);
        }
    }

    return [...counts.entries()]
        .map(([name, count]) => ({ name, count }))
        .sort(
            (a, b) =>
                b.count - a.count ||
                a.name.localeCompare(b.name, undefined, { sensitivity: "base" }),
        );
});

/**
 * Ce qu'on affiche : les épinglées, puis les plus portées.
 *
 * Une étiquette épinglée qui n'existe plus - renommée, fusionnée, effacée
 * depuis l'écran des étiquettes - tombe d'elle-même, puisque la liste part
 * de ce que les notes portent réellement.
 */
const visibleTags = computed(() => {
    if (searching.value) return [];

    const pinned = pinnedTags.value;
    const marked = allTags.value.map((one) => ({
        ...one,
        pinned: pinned.includes(one.name),
    }));

    const first = marked.filter((one) => one.pinned);
    const rest = marked.filter((one) => !one.pinned);

    return [...first, ...(showAllTags.value ? rest : rest.slice(0, TAGS_SHOWN))];
});

const hiddenTagCount = computed(() =>
    showAllTags.value
        ? 0
        : Math.max(0, allTags.value.length - pinnedTags.value.length - TAGS_SHOWN),
);

function togglePinned(name) {
    pinnedTags.value = pinnedTags.value.includes(name)
        ? pinnedTags.value.filter((one) => one !== name)
        : [...pinnedTags.value, name];

    store(PINNED_TAGS_KEY, JSON.stringify(pinnedTags.value));
}

function toggleTagsSection() {
    tagsOpen.value = !tagsOpen.value;
    store(TAGS_OPEN_KEY, tagsOpen.value ? "1" : "0");
}

function labelOf(node) {
    if ("folder" === node.kind) {
        return node.name || t("notes.markdown.folders.untitled");
    }

    return node.title || t("notes.markdown.untitled");
}

// ── Ce que le panneau demande à la page ────────────────────────────

/**
 * Notre propre copie de ce qui est glissé, pour que les lignes s'allument.
 *
 * La page tient le même état - il le faut, c'est elle qui écrit - mais le
 * refléter ici coûte une affectation par événement qu'on transmet déjà, là
 * où le relire demanderait une annonce à chaque `dragover`.
 */
const draggingKey = ref(null);
const dragOverKey = ref(null);

function forward(name, ...args) {
    askPage(`notes:${name}`, { args });
}

/**
 * Un clic ouvre : un dossier dans la bibliothèque, une note dans l'éditeur.
 *
 * La page répond aux deux ; si personne n'écoute, le lien de la ligne a déjà
 * l'adresse et le navigateur y va.
 */
function onSelect(node) {
    selectedKey.value = node.key;

    if ("folder" === node.kind) {
        // La racine n'a pas d'identifiant, et `Number(null)` vaut zéro :
        // le panneau demandait donc le dossier 0, que la bibliothèque
        // affichait vide et dont l'adresse rendait un 404.
        forward("open-folder", null === node.id ? null : Number(node.id));

        return;
    }

    forward("select", Number(node.id));
}

function onFavoriteClick(entry, event) {
    event.preventDefault();
    onSelect(entry);
}

/**
 * Le glisser part d'ici, donc le presse-papier se remplit ici.
 *
 * La page ne peut pas le faire à notre place : elle reçoit l'événement une
 * fois le glisser commencé, et `setData` n'a plus d'effet à ce moment. Les
 * lignes se laissaient saisir sans rien transporter, et le dépôt ne faisait
 * rien du tout.
 */
function onDragStart(node, event) {
    draggingKey.value = node.key;
    startNoteDrag(event, node.kind, node.id);
}

function onDragEnd() {
    draggingKey.value = null;
    dragOverKey.value = null;
}

function onDragOver(node, event) {
    // Une note ne reçoit rien : elle ne range pas.
    if ("folder" !== node.kind || node.key === draggingKey.value) return;

    event.preventDefault();
    event.stopPropagation();
    dragOverKey.value = node.key;
}

function onDragLeave(node, event) {
    const related = event.relatedTarget;
    if (related && event.currentTarget.contains(related)) return;
    if (dragOverKey.value === node.key) dragOverKey.value = null;
}

function onDrop(node, event) {
    dragOverKey.value = null;
    draggingKey.value = null;

    if ("folder" !== node.kind) return;

    forward("drop", { id: node.id }, event);
}

const stopListening = [];

onMounted(() => {
    stopListening.push(
        onPageNotice("notes:changed", (detail) => {
            if (Array.isArray(detail?.notes)) announcedNotes.value = detail.notes;
            if (Array.isArray(detail?.folders)) {
                announcedFolders.value = detail.folders;
            }

            // La page dit ce qu'elle montre : un dossier, une note, ou la
            // racine. La ligne correspondante s'allume, et son dossier
            // s'ouvre pour qu'elle soit visible.
            if (detail?.noteId) {
                selectedKey.value = `note:${detail.noteId}`;
                revealNote(detail.noteId);

                return;
            }

            if ("folderId" in (detail ?? {})) {
                selectedKey.value = detail.folderId
                    ? `folder:${detail.folderId}`
                    : null;
            }
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
                :title="t('notes.markdown.import.button')"
                v-on:click="forward('import')"
            >
                <Upload class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                :title="t('notes.markdown.export.all')"
                v-on:click="forward('export')"
            >
                <Download class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                :title="t('notes.markdown.folders.create')"
                v-on:click="forward('create-folder', null)"
            >
                <FolderPlus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                :title="t('notes.markdown.create_root')"
                v-on:click="forward('create', null)"
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

        <!-- Les favoris, avant l'arborescence : ce qu'on vient chercher tous
             les jours n'a pas à se retrouver dans un arbre. -->
        <div v-if="favorites.length" class="mb-2 border-b border-line pb-2">
            <p class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                {{ t('notes.markdown.library.favorites') }}
            </p>

            <a
                v-for="entry in favorites"
                :key="entry.key"
                :data-favorite-row="entry.key"
                :href="hrefFor(entry)"
                class="flex min-w-0 items-center gap-2 rounded-lg px-3 py-2 text-sm text-primary no-underline transition-colors hover:bg-surface-2"
                v-on:click="onFavoriteClick(entry, $event)"
            >
                <component
                    :is="'folder' === entry.kind ? Folder : FileText"
                    class="h-4 w-4 shrink-0 text-muted"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ labelOf(entry) }}</span>
            </a>
        </div>

        <!-- La racine est une ligne comme les autres : c'est là qu'on
             retombe, et une arborescence sans son sommet oblige à deviner
             comment y revenir. -->
        <a
            :href="LIBRARY_URL"
            data-root-row
            class="group mb-0.5 flex min-w-0 items-center gap-2 rounded-lg border border-transparent px-3 py-2 text-sm no-underline transition-colors"
            :class="null === selectedKey ? 'border-accent-600/30 bg-accent-600/15 text-accent-400' : 'text-primary hover:bg-surface-2'"
            v-on:click.prevent="onSelect({ kind: 'folder', id: null, key: null })"
        >
            <FileText class="h-4 w-4 shrink-0" :stroke-width="2" />
            <span class="flex-1 truncate">{{ t('notes.markdown.library.title') }}</span>
        </a>

        <p v-if="isEmpty && !searching" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.folders.tree_empty") }}
        </p>

        <p v-else-if="searching && !tree.length" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.search_no_results") }}
        </p>

        <NoteTreeItem
            v-for="node in tree"
            :key="node.key"
            :node="node"
            :selected-key="selectedKey"
            :expanded="expanded"
            :draggable="true"
            :dragging-key="draggingKey"
            :drag-over-key="dragOverKey"
            :href-for="hrefFor"
            v-on:select="onSelect"
            v-on:toggle="toggle"
            v-on:create-note="(id) => forward('create', id)"
            v-on:rename="(node) => forward('folder' === node.kind ? 'rename-folder' : 'rename-note', node)"
            v-on:delete="(node) => forward('folder' === node.kind ? 'delete-folder' : 'delete', node)"
            v-on:drag-start="onDragStart"
            v-on:drag-end="onDragEnd"
            v-on:drag-over="onDragOver"
            v-on:drag-leave="onDragLeave"
            v-on:drop="onDrop"
        />
        <!-- Le carnet des autres, en lecture, et à part. Ce qui n'est
             pas à soi ne se range pas dans son arborescence. -->
        <div v-if="hasShared" class="mt-2 border-t border-line pt-2">
            <p class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                {{ t('notes.markdown.library.shared.section') }}
            </p>

            <div v-for="groupe in sharedGroups" :key="groupe.key" class="mb-1">
                <p
                    v-if="groupe.name"
                    class="flex min-w-0 items-center gap-2 px-3 py-1 text-sm text-secondary"
                    :title="groupe.owner ? t('notes.markdown.library.shared.by', { name: groupe.owner }) : undefined"
                >
                    <Users class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="min-w-0 flex-1 truncate">{{ groupe.name }}</span>
                </p>

                <a
                    v-for="note in groupe.notes"
                    :key="`shared-${note.id}`"
                    :href="`${LIBRARY_URL}/${note.id}/read`"
                    class="flex min-w-0 items-center gap-2 rounded-lg py-1.5 pl-6 pr-3 text-sm text-primary no-underline transition-colors hover:bg-surface-2"
                    :title="note.ownerName ? t('notes.markdown.library.shared.by', { name: note.ownerName }) : undefined"
                >
                    <FileText class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                    <span class="min-w-0 flex-1 truncate">
                        {{ note.title || t('notes.markdown.untitled') }}
                    </span>
                </a>
            </div>
        </div>

        <!-- Les étiquettes, sous l'arborescence : elles traversent le
             rangement, donc elles ne peuvent pas y tenir une place. Cliquer
             l'une d'elles montre ses notes, où qu'elles soient. -->
        <div v-if="allTags.length && !searching" class="mt-2 border-t border-line pt-2">
            <button
                type="button"
                class="flex w-full items-center gap-1 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted transition-colors hover:text-primary"
                v-on:click="toggleTagsSection"
            >
                <ChevronDown v-if="tagsOpen" class="h-3 w-3 shrink-0" :stroke-width="2" />
                <ChevronRight v-else class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t('notes.markdown.library.tag.section') }}
            </button>

            <template v-if="tagsOpen">
                <div
                    v-for="one in visibleTags"
                    :key="one.name"
                    class="group flex min-w-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-primary transition-colors hover:bg-surface-2"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-2 text-left"
                        :title="t('notes.markdown.library.tag.filter', { tag: one.name })"
                        v-on:click="forward('filter-tag', one.name)"
                    >
                        <Tag class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                        <span class="min-w-0 flex-1 truncate">{{ one.name }}</span>
                        <span class="shrink-0 text-xs text-muted tabular-nums">{{ one.count }}</span>
                    </button>

                    <AppIconButton
                        size="sm"
                        class="shrink-0 sm:opacity-0 sm:group-hover:opacity-100"
                        :class="one.pinned ? 'sm:opacity-100' : ''"
                        :title="one.pinned ? t('notes.markdown.library.tag.unpin') : t('notes.markdown.library.tag.pin')"
                        v-on:click.stop="togglePinned(one.name)"
                    >
                        <PinOff v-if="one.pinned" class="h-3 w-3" :stroke-width="2" />
                        <Pin v-else class="h-3 w-3" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <button
                    v-if="hiddenTagCount"
                    type="button"
                    class="px-3 py-1 text-xs text-muted transition-colors hover:text-primary"
                    v-on:click="showAllTags = true"
                >
                    {{ t('notes.markdown.library.tag.show_all', { count: hiddenTagCount }) }}
                </button>
            </template>
        </div>
    </AppModulePanel>
</template>
