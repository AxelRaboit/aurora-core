<script setup>
/**
 * Le carnet, vu de dehors.
 *
 * Avant elle, le module n'avait pas d'écran pour regarder ce qu'il contenait :
 * l'adresse rendait la première note et l'arborescence vivait dans le menu.
 * Cette page est la contrepartie de la séparation dossier/note - un endroit
 * où l'on voit ce qu'on a écrit, rangé, et où l'on range.
 *
 * **Trois façons de regarder, une seule source.** Mosaïque, cartes et liste
 * dessinent la même liste triée ; le tri et l'affichage sont des préférences
 * de lecture, retenues dans le navigateur, pas des états du carnet.
 *
 * **Le rangement se fait ici.** Une carte se glisse sur un dossier ou sur un
 * maillon du fil d'Ariane, et la modale « Déplacer vers » fait la même chose
 * au clavier et au doigt, parce qu'un glisser-déposer n'existe pas sur un
 * téléphone.
 */
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import {
    ArrowDownWideNarrow,
    ArrowUpNarrowWide,
    ChevronRight,
    FileText,
    Folder,
    FolderInput,
    FolderPlus,
    LayoutGrid,
    List,
    Pencil,
    Plus,
    Rows3,
    Trash2,
    X,
} from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useNoteLibrary } from "@notes/backend/markdown/composables/useNoteLibrary.js";

const props = defineProps({
    /** Every folder of the reader, flat, `{id, parentId, name, noteCount, …}`. */
    folders: { type: Array, default: () => [] },
    /** Every note of the reader, flat and without its body. */
    notes: { type: Array, default: () => [] },
    /** {@see useNoteFoldersApi} */
    foldersApi: { type: Object, required: true },
    /** {@see useMarkdownNotesApi} */
    notesApi: { type: Object, required: true },
    /** The folder the address names, null at the root. */
    initialFolderId: { type: Number, default: null },
    /** The chain the server resolved, so the first paint is not the root. */
    breadcrumb: { type: Array, default: () => [] },
    rootUrl: { type: String, required: true },
    /** Builds a note's own address, for a link that middle-click can open. */
    noteUrlFor: { type: Function, required: true },
});

const emit = defineEmits(["open-note", "create-note", "changed"]);

const { t } = useI18n();
const { formatDateTimeNumeric } = useDateFormat();

const foldersRef = computed(() => props.folders);
const notesRef = computed(() => props.notes);

const library = useNoteLibrary({
    folders: foldersRef,
    notes: notesRef,
    initialFolderId: props.initialFolderId,
    breadcrumb: props.breadcrumb,
    urlFor: props.foldersApi.urlFor,
    rootUrl: props.rootUrl,
});

const {
    currentFolderId,
    path,
    view,
    sort,
    direction,
    folders: visibleFolders,
    notes: visibleNotes,
    isEmpty,
    openFolder,
    onPopState,
    setView,
    setSort,
    toggleDirection,
} = library;

onMounted(() => window.addEventListener("popstate", onPopState));
onUnmounted(() => window.removeEventListener("popstate", onPopState));

const query = ref("");

/**
 * La recherche filtre ce qui est sous les yeux, pas le carnet entier.
 *
 * Chercher dans tout le carnet est le travail du panneau du menu, qui a le
 * champ pour ça et qui remonte les résultats de toute l'arborescence. Ici on
 * filtre le dossier ouvert, ce qui est ce qu'on attend d'un explorateur.
 */
function matches(label) {
    const needle = query.value.trim().toLowerCase();

    return "" === needle || String(label ?? "").toLowerCase().includes(needle);
}

const shownFolders = computed(() =>
    visibleFolders.value.filter((folder) => matches(folder.name)),
);

const shownNotes = computed(() =>
    visibleNotes.value.filter((note) => matches(note.title)),
);

const nothingShown = computed(
    () => 0 === shownFolders.value.length && 0 === shownNotes.value.length,
);

const viewOptions = computed(() => [
    { value: "mosaic", icon: LayoutGrid, label: t("notes.markdown.library.view.mosaic") },
    { value: "cards", icon: Rows3, label: t("notes.markdown.library.view.cards") },
    { value: "list", icon: List, label: t("notes.markdown.library.view.list") },
]);

const sortOptions = computed(() => [
    { value: "name", label: t("notes.markdown.library.sort.name") },
    { value: "updated", label: t("notes.markdown.library.sort.updated") },
    { value: "created", label: t("notes.markdown.library.sort.created") },
    { value: "manual", label: t("notes.markdown.library.sort.manual") },
]);

function folderLabel(folder) {
    return folder.name || t("notes.markdown.folders.untitled");
}

function noteLabel(note) {
    return note.title || t("notes.markdown.untitled");
}

// ── Créer, renommer ────────────────────────────────────────────────
const nameModal = ref(null);
const nameValue = ref("");
const nameSaving = ref(false);

function askForFolderName(folder = null, parentId = undefined) {
    nameModal.value = folder ?? {
        id: null,
        parentId: undefined === parentId ? currentFolderId.value : parentId,
    };
    nameValue.value = folder?.name ?? "";
}

async function submitName() {
    if (!nameModal.value) return;

    nameSaving.value = true;

    const { id } = nameModal.value;
    const { ok, reported } = null === id
        ? await props.foldersApi.create(
            nameValue.value,
            nameModal.value.parentId ?? null,
        )
        : await props.foldersApi.rename(
            id,
            nameValue.value,
            nameModal.value.parentId ?? null,
        );

    nameSaving.value = false;

    if (!ok) {
        if (!reported) {
            toast.error(
                t(null === id
                    ? "notes.markdown.folders.errors.create_failed"
                    : "notes.markdown.folders.errors.rename_failed"),
            );
        }

        return;
    }

    toast.success(t(null === id ? "notes.markdown.folders.created" : "notes.markdown.folders.renamed"));
    nameModal.value = null;
    emit("changed");
}

// ── Supprimer ──────────────────────────────────────────────────────
const pendingDelete = ref(null);
const deleting = ref(false);

async function confirmDelete() {
    if (!pendingDelete.value) return;

    deleting.value = true;
    const { ok, reported } = await props.foldersApi.remove(pendingDelete.value.id);
    deleting.value = false;

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.folders.errors.delete_failed"));

        return;
    }

    toast.success(t("notes.markdown.folders.deleted"));
    pendingDelete.value = null;
    emit("changed");
}

// ── Déplacer ───────────────────────────────────────────────────────
const moving = ref(null);

/**
 * Les destinations possibles pour ce qu'on déplace.
 *
 * Un dossier ne peut pas se ranger dans lui-même ni dans sa propre branche :
 * le serveur le refuse, et l'offrir dans une liste pour le refuser ensuite
 * serait une porte peinte sur un mur.
 */
const moveTargets = computed(() => {
    if (!moving.value) return [];

    const excluded = new Set();

    if ("folder" === moving.value.kind) {
        const queue = [Number(moving.value.item.id)];

        while (queue.length) {
            const id = queue.shift();
            excluded.add(id);

            for (const folder of props.folders) {
                if (Number(folder.parentId) === id) queue.push(Number(folder.id));
            }
        }
    }

    return [
        { value: "", label: t("notes.markdown.folders.move_root") },
        ...props.folders
            .filter((folder) => !excluded.has(Number(folder.id)))
            .map((folder) => ({
                value: String(folder.id),
                label: pathLabel(folder),
            }))
            .sort((a, b) => a.label.localeCompare(b.label, undefined, { numeric: true })),
    ];
});

const moveTarget = ref("");

/** Le chemin complet d'un dossier, pour que deux homonymes se distinguent. */
function pathLabel(folder) {
    const names = [];
    const seen = new Set();
    let node = folder;

    while (node && !seen.has(Number(node.id))) {
        seen.add(Number(node.id));
        names.unshift(node.name || t("notes.markdown.folders.untitled"));
        node = props.folders.find((one) => Number(one.id) === Number(node.parentId)) ?? null;
    }

    return names.join(" / ");
}

function askToMove(kind, item) {
    moving.value = { kind, item };
    moveTarget.value = "";
}

async function submitMove() {
    if (!moving.value) return;

    const target = "" === moveTarget.value ? null : Number(moveTarget.value);

    await applyMove(moving.value.kind, Number(moving.value.item.id), target);
    moving.value = null;
}

async function applyMove(kind, id, targetFolderId) {
    const { ok, reported, payload } =
        "folder" === kind
            ? await props.foldersApi.move(id, targetFolderId)
            : await props.notesApi.move(id, targetFolderId);

    if (!ok) {
        if (!reported) {
            toast.error(
                "refused" === payload?.error
                    ? t("notes.markdown.folders.errors.move_refused", { max: 8 })
                    : t("notes.markdown.folders.errors.move_failed"),
            );
        }

        return;
    }

    toast.success(t("notes.markdown.folders.moved"));
    emit("changed");
}

// ── Glisser-déposer ────────────────────────────────────────────────
const DATA_KIND = "application/x-aurora-note-item";
const dragOverId = ref(null);
const rootDragOver = ref(false);
const dragging = ref(null);

function onDragStart(kind, item, event) {
    if (!event.dataTransfer) return;

    dragging.value = { kind, id: Number(item.id) };
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData(DATA_KIND, `${kind}:${item.id}`);
}

function onDragEnd() {
    dragging.value = null;
    dragOverId.value = null;
    rootDragOver.value = false;
}

function acceptsDrop(event) {
    return Boolean(event.dataTransfer?.types.includes(DATA_KIND));
}

function onDragOverFolder(folder, event) {
    if (!acceptsDrop(event)) return;

    // On ne dépose pas un dossier sur lui-même : le serveur refuserait, et
    // la cible ne doit pas s'allumer pour un geste qui ne peut pas aboutir.
    if (dragging.value?.kind === "folder" && dragging.value.id === Number(folder.id)) return;

    event.preventDefault();
    event.stopPropagation();
    event.dataTransfer.dropEffect = "move";
    dragOverId.value = `folder:${folder.id}`;
    rootDragOver.value = false;
}

function onDragLeaveFolder(folder, event) {
    const related = event.relatedTarget;
    if (related && event.currentTarget.contains(related)) return;
    if (dragOverId.value === `folder:${folder.id}`) dragOverId.value = null;
}

function onDragOverCrumb(crumb, event) {
    if (!acceptsDrop(event)) return;

    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    dragOverId.value = `crumb:${crumb?.id ?? "root"}`;
}

async function onDropOn(targetFolderId, event) {
    if (!acceptsDrop(event)) return;

    event.preventDefault();
    event.stopPropagation();

    const raw = String(event.dataTransfer.getData(DATA_KIND));
    const [kind, id] = raw.split(":");

    dragOverId.value = null;
    rootDragOver.value = false;
    dragging.value = null;

    if (!id) return;
    if ("folder" === kind && Number(id) === targetFolderId) return;

    await applyMove(kind, Number(id), targetFolderId);
}

// ── Les actions d'une carte ────────────────────────────────────────
function folderActions(folder) {
    return [
        {
            key: "open",
            title: t("notes.markdown.folders.open"),
            icon: Folder,
            href: props.foldersApi.urlFor(folder.id),
            onSelect: () => openFolder(folder.id),
        },
        {
            key: "rename",
            title: t("notes.markdown.folders.rename"),
            icon: Pencil,
            onSelect: () => askForFolderName(folder),
        },
        {
            key: "move",
            title: t("notes.markdown.folders.move_to"),
            icon: FolderInput,
            onSelect: () => askToMove("folder", folder),
        },
        {
            key: "delete",
            title: t("notes.markdown.folders.delete"),
            icon: Trash2,
            color: "rose",
            onSelect: () => {
                pendingDelete.value = folder;
            },
        },
    ];
}

function noteActions(note) {
    return [
        {
            key: "open",
            title: t("notes.markdown.folders.open"),
            icon: FileText,
            href: props.noteUrlFor(note.id),
            onSelect: () => emit("open-note", note.id),
        },
        {
            key: "move",
            title: t("notes.markdown.folders.move_to"),
            icon: FolderInput,
            onSelect: () => askToMove("note", note),
        },
    ];
}

function updatedLabel(item) {
    return item.updatedAt ? formatDateTimeNumeric(item.updatedAt) : "";
}

/**
 * Ce que le panneau du menu peut demander à cette page.
 *
 * Le pont `modulePanelBridge` parle à l'application, qui est montée en
 * permanence ; la bibliothèque, elle, ne l'est que quand aucune note n'est
 * ouverte. L'application relaie donc, et ces quatre fonctions sont le
 * contrat entre les deux.
 */
defineExpose({
    openFolder,
    askForFolderName,
    askToDelete: (folder) => {
        pendingDelete.value = folder;
    },
    dropInto: (folderId, event) => onDropOn(folderId, event),
});
</script>

<template>
    <div class="flex flex-col min-h-0 flex-1">
        <header class="flex flex-col gap-3 border-b border-line p-3 sm:p-4">
            <!-- Le fil d'Ariane est aussi une cible : remonter d'un niveau se
                 fait en y glissant ce qu'on tient, sans ouvrir de modale. -->
            <nav class="flex items-center gap-1 text-sm flex-wrap" :aria-label="t('notes.markdown.library.title')">
                <button
                    type="button"
                    class="rounded px-2 py-1 transition-colors"
                    :class="[
                        null === currentFolderId ? 'font-semibold text-primary' : 'text-muted hover:text-primary',
                        'crumb:root' === dragOverId ? 'bg-accent-500/15 ring-1 ring-accent-500' : '',
                    ]"
                    v-on:click="openFolder(null)"
                    v-on:dragover="onDragOverCrumb(null, $event)"
                    v-on:dragleave="dragOverId = null"
                    v-on:drop="onDropOn(null, $event)"
                >
                    {{ t('notes.markdown.library.title') }}
                </button>

                <template v-for="crumb in path" :key="crumb.id">
                    <ChevronRight class="w-3.5 h-3.5 text-muted shrink-0" :stroke-width="2" />
                    <button
                        type="button"
                        class="rounded px-2 py-1 transition-colors"
                        :class="[
                            crumb.id === currentFolderId ? 'font-semibold text-primary' : 'text-muted hover:text-primary',
                            `crumb:${crumb.id}` === dragOverId ? 'bg-accent-500/15 ring-1 ring-accent-500' : '',
                        ]"
                        v-on:click="openFolder(crumb.id)"
                        v-on:dragover="onDragOverCrumb(crumb, $event)"
                        v-on:dragleave="dragOverId = null"
                        v-on:drop="onDropOn(crumb.id, $event)"
                    >
                        {{ crumb.name || t('notes.markdown.folders.untitled') }}
                    </button>
                </template>
            </nav>

            <div class="flex flex-wrap items-center gap-2">
                <AppSearchInput
                    v-model="query"
                    :placeholder="t('notes.markdown.library.search_placeholder')"
                    class="flex-1 min-w-[12rem]"
                />

                <div class="inline-flex rounded-md border border-line overflow-hidden">
                    <AppTab
                        v-for="opt in viewOptions"
                        :key="opt.value"
                        size="sm"
                        align="center"
                        shape-class="rounded-none"
                        :active="view === opt.value"
                        :title="opt.label"
                        v-on:click="setView(opt.value)"
                    >
                        <component :is="opt.icon" class="w-4 h-4" :stroke-width="2" />
                    </AppTab>
                </div>

                <!-- Le sens est un bouton séparé du critère, comme chez Craft :
                     changer d'ordre ne doit pas demander de rouvrir la liste
                     des critères. -->
                <div class="flex items-center gap-1">
                    <AppSelect
                        :model-value="sort"
                        :options="sortOptions"
                        class="min-w-[9rem]"
                        v-on:update:model-value="setSort($event)"
                    />
                    <AppIconButton
                        :title="'asc' === direction ? t('notes.markdown.library.sort.asc') : t('notes.markdown.library.sort.desc')"
                        size="md"
                        variant="ghost"
                        v-on:click="toggleDirection"
                    >
                        <ArrowUpNarrowWide v-if="'asc' === direction" class="w-4 h-4" :stroke-width="2" />
                        <ArrowDownWideNarrow v-else class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <!-- Pleine largeur sur téléphone, libellé centré : la règle
                     d'affichage mobile de la maison. -->
                <AppButton
                    variant="ghost"
                    size="md"
                    class="w-full justify-center sm:w-auto"
                    v-on:click="askForFolderName()"
                >
                    <FolderPlus class="w-4 h-4" :stroke-width="2" />
                    {{ t('notes.markdown.library.new_folder') }}
                </AppButton>

                <AppButton
                    variant="primary"
                    size="md"
                    class="w-full justify-center sm:w-auto"
                    v-on:click="emit('create-note', currentFolderId)"
                >
                    <Plus class="w-4 h-4" :stroke-width="2" />
                    {{ t('notes.markdown.library.new_note') }}
                </AppButton>
            </div>
        </header>

        <div
            class="flex-1 min-h-0 overflow-auto p-3 sm:p-4"
            :class="rootDragOver ? 'bg-accent-500/5' : ''"
            v-on:dragover="onDragOverCrumb(null, $event)"
            v-on:drop="onDropOn(null, $event)"
        >
            <AppNoData
                v-if="isEmpty"
                :title="null === currentFolderId ? t('notes.markdown.library.empty_root.title') : t('notes.markdown.library.empty.title')"
                :description="null === currentFolderId ? t('notes.markdown.library.empty_root.description') : t('notes.markdown.library.empty.description')"
                :icon="Folder"
            />

            <AppNoData
                v-else-if="nothingShown"
                :title="t('notes.markdown.search_no_results')"
                :description="t('notes.markdown.search_no_results_description', { query })"
                :icon="FileText"
            />

            <!-- Mosaïque et cartes partagent la grille et ne diffèrent que par
                 la hauteur des tuiles : une seule colonne sur téléphone, c'est
                 la règle de la maison depuis le 14/09. -->
            <div
                v-else-if="'list' !== view"
                class="grid grid-cols-1 gap-3"
                :class="'mosaic' === view ? 'sm:grid-cols-2 xl:grid-cols-3' : 'sm:grid-cols-3 xl:grid-cols-4'"
            >
                <article
                    v-for="folder in shownFolders"
                    :key="`folder-${folder.id}`"
                    class="group flex flex-col rounded-lg border bg-surface transition-colors"
                    :class="[
                        `folder:${folder.id}` === dragOverId ? 'border-accent-500 bg-accent-500/10' : 'border-line hover:border-accent-500/50',
                        'mosaic' === view ? 'p-4' : 'p-3',
                    ]"
                    draggable="true"
                    v-on:dragstart="onDragStart('folder', folder, $event)"
                    v-on:dragend="onDragEnd"
                    v-on:dragover="onDragOverFolder(folder, $event)"
                    v-on:dragleave="onDragLeaveFolder(folder, $event)"
                    v-on:drop="onDropOn(Number(folder.id), $event)"
                >
                    <div class="flex items-start gap-2">
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-center gap-2 text-left"
                            v-on:click="openFolder(folder.id)"
                        >
                            <Folder class="w-5 h-5 shrink-0 text-accent-500" :stroke-width="2" />
                            <span class="truncate font-medium text-primary">{{ folderLabel(folder) }}</span>
                        </button>

                        <AppRowActions :actions="folderActions(folder)" :label="folderLabel(folder)" />
                    </div>

                    <p class="mt-2 text-xs text-muted">
                        {{ t('notes.markdown.folders.contents', { folders: folder.folderCount ?? 0, notes: folder.noteCount ?? 0 }) }}
                    </p>
                </article>

                <article
                    v-for="note in shownNotes"
                    :key="`note-${note.id}`"
                    class="group flex flex-col rounded-lg border border-line bg-surface transition-colors hover:border-accent-500/50"
                    :class="'mosaic' === view ? 'p-4 min-h-[8rem]' : 'p-3'"
                    draggable="true"
                    v-on:dragstart="onDragStart('note', note, $event)"
                    v-on:dragend="onDragEnd"
                >
                    <div class="flex items-start gap-2">
                        <a
                            :href="noteUrlFor(note.id)"
                            class="flex min-w-0 flex-1 items-center gap-2"
                            v-on:click.prevent="emit('open-note', note.id)"
                        >
                            <FileText class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                            <span class="truncate font-medium text-primary">{{ noteLabel(note) }}</span>
                        </a>

                        <AppRowActions :actions="noteActions(note)" :label="noteLabel(note)" />
                    </div>

                    <div v-if="note.tags?.length" class="mt-2 flex flex-wrap gap-1">
                        <AppBadge v-for="tag in note.tags" :key="tag" color="gray" size="xs">
                            {{ tag }}
                        </AppBadge>
                    </div>

                    <p class="mt-auto pt-2 text-xs text-muted">{{ updatedLabel(note) }}</p>
                </article>
            </div>

            <!-- La liste : un tableau sur écran large, des lignes empilées en
                 dessous. Le tableau défile dans son propre conteneur pour que
                 la page ne parte jamais de côté. -->
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-2 py-2 font-medium">{{ t('notes.markdown.library.columns.name') }}</th>
                            <th scope="col" class="hidden px-2 py-2 font-medium sm:table-cell">{{ t('notes.markdown.library.columns.tags') }}</th>
                            <th scope="col" class="hidden px-2 py-2 font-medium sm:table-cell">{{ t('notes.markdown.library.columns.updated') }}</th>
                            <th scope="col" class="px-2 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="folder in shownFolders"
                            :key="`row-folder-${folder.id}`"
                            class="border-t border-line transition-colors"
                            :class="`folder:${folder.id}` === dragOverId ? 'bg-accent-500/10' : ''"
                            draggable="true"
                            v-on:dragstart="onDragStart('folder', folder, $event)"
                            v-on:dragend="onDragEnd"
                            v-on:dragover="onDragOverFolder(folder, $event)"
                            v-on:dragleave="onDragLeaveFolder(folder, $event)"
                            v-on:drop="onDropOn(Number(folder.id), $event)"
                        >
                            <td class="px-2 py-2">
                                <button type="button" class="flex items-center gap-2 text-left" v-on:click="openFolder(folder.id)">
                                    <Folder class="w-4 h-4 shrink-0 text-accent-500" :stroke-width="2" />
                                    <span class="truncate font-medium text-primary">{{ folderLabel(folder) }}</span>
                                </button>
                            </td>
                            <td class="hidden px-2 py-2 text-muted sm:table-cell">
                                {{ t('notes.markdown.library.count', { count: (folder.noteCount ?? 0) + (folder.folderCount ?? 0) }) }}
                            </td>
                            <td class="hidden px-2 py-2 text-muted sm:table-cell">{{ updatedLabel(folder) }}</td>
                            <td class="px-2 py-2">
                                <AppRowActions :actions="folderActions(folder)" :label="folderLabel(folder)" />
                            </td>
                        </tr>

                        <tr
                            v-for="note in shownNotes"
                            :key="`row-note-${note.id}`"
                            class="border-t border-line"
                            draggable="true"
                            v-on:dragstart="onDragStart('note', note, $event)"
                            v-on:dragend="onDragEnd"
                        >
                            <td class="px-2 py-2">
                                <a
                                    :href="noteUrlFor(note.id)"
                                    class="flex items-center gap-2"
                                    v-on:click.prevent="emit('open-note', note.id)"
                                >
                                    <FileText class="w-4 h-4 shrink-0 text-muted" :stroke-width="2" />
                                    <span class="truncate font-medium text-primary">{{ noteLabel(note) }}</span>
                                </a>
                            </td>
                            <td class="hidden px-2 py-2 sm:table-cell">
                                <div class="flex flex-wrap gap-1">
                                    <AppBadge v-for="tag in note.tags ?? []" :key="tag" color="gray" size="xs">
                                        {{ tag }}
                                    </AppBadge>
                                </div>
                            </td>
                            <td class="hidden px-2 py-2 text-muted sm:table-cell">{{ updatedLabel(note) }}</td>
                            <td class="px-2 py-2">
                                <AppRowActions :actions="noteActions(note)" :label="noteLabel(note)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppModal
            :show="null !== nameModal"
            max-width="sm"
            :closeable="!nameSaving"
            :title="nameModal?.id ? t('notes.markdown.folders.rename') : t('notes.markdown.folders.create')"
            :icon="FolderPlus"
            v-on:close="nameModal = null"
        >
            <AppInput
                v-model="nameValue"
                :placeholder="t('notes.markdown.folders.name_placeholder')"
                class="w-full"
                v-on:keyup.enter="submitName"
            />

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" :disabled="nameSaving" v-on:click="nameModal = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.cancel') }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="nameSaving" v-on:click="submitName">
                        {{ t('notes.markdown.save') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="null !== moving"
            max-width="sm"
            :title="t('notes.markdown.folders.move_to')"
            :icon="FolderInput"
            v-on:close="moving = null"
        >
            <AppSelect v-model="moveTarget" :options="moveTargets" class="w-full" />

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="moving = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.cancel') }}
                    </AppButton>
                    <AppButton variant="primary" size="md" v-on:click="submitMove">
                        <FolderInput class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.folders.move_to') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="null !== pendingDelete"
            max-width="sm"
            :closeable="!deleting"
            :title="t('notes.markdown.folders.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t('notes.markdown.folders.confirm_delete', { name: pendingDelete ? folderLabel(pendingDelete) : '' }) }}
            </p>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" :disabled="deleting" v-on:click="pendingDelete = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.cancel') }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="deleting" v-on:click="confirmDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.delete') }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
