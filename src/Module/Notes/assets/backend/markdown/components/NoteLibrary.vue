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
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import {
    ArrowDown,
    ArrowDownWideNarrow,
    ArrowUp,
    ArrowUpDown,
    ArrowUpNarrowWide,
    ChevronRight,
    FileDown,
    FileText,
    Folder,
    FolderInput,
    FolderPlus,
    FolderTree,
    Layers,
    LayoutGrid,
    List,
    Pencil,
    Pin,
    PinOff,
    Plus,
    Rows3,
    Search,
    Tag,
    Trash2,
    X,
} from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppLoadMore from "@/shared/components/nav/AppLoadMore.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppSelectionCheck from "@/shared/components/feedback/AppSelectionCheck.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useNoteLibrary } from "@notes/backend/markdown/composables/useNoteLibrary.js";
import { useFoldable } from "@notes/backend/markdown/composables/useFoldable.js";
import { useNotePreview } from "@notes/backend/markdown/composables/useNotePreview.js";
import NotePreview from "@notes/backend/markdown/components/NotePreview.vue";
import {
    NOTE_DRAG_MIME,
    readNoteDrag,
    startNoteDrag,
} from "@notes/backend/markdown/composables/noteDrag.js";

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
    /** Builds the address that downloads one note as Markdown. */
    noteExportUrlFor: { type: Function, default: () => "" },
    /** Ce que le serveur accepte comme profondeur, pour le dire au refus. */
    maxDepth: { type: Number, default: 8 },
});

const emit = defineEmits([
    "open-note",
    "create-note",
    "changed",
    "folder-changed",
]);

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
    flat,
    tag: activeTag,
    folders: visibleFolders,
    notes: visibleNotes,
    isEmpty,
    openFolder,
    onPopState,
    setView,
    setSort,
    toggleDirection,
    toggleFlat,
    setTag,
    folderNameOf,
} = library;

onMounted(() => window.addEventListener("popstate", onPopState));
onUnmounted(() => window.removeEventListener("popstate", onPopState));

// La page suit le dossier ouvert : c'est là qu'un import doit atterrir, et
// c'est ce que le panneau du menu met en évidence. Sans cela, importer
// après avoir changé de dossier déposait les fichiers dans celui d'où l'on
// était parti, c'est-à-dire celui que le serveur avait rendu.
watch(currentFolderId, (id) => emit("folder-changed", id), { immediate: true });

const query = ref("");

/**
 * La recherche se replie en loupe, comme chez Craft.
 *
 * Un champ vide qui occupe le tiers de la barre coûte cette place à tout le
 * reste, et on ne cherche pas en permanence. L'icône l'ouvre, la barre
 * oblique aussi, Échap la referme - mais seulement si elle est vide : un
 * filtre actif et invisible ferait chercher pourquoi la liste est courte.
 */
const {
    open: searchOpen,
    box: searchBox,
    reveal: openSearch,
    fold: foldSearch,
} = useFoldable();

function closeSearch() {
    // Tant qu'il y a quelque chose dans le champ, il reste ouvert : un
    // filtre actif et invisible ferait chercher pourquoi la liste est
    // courte.
    if ("" !== query.value.trim()) return;

    foldSearch();
}

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

/**
 * Ce qui est dessiné d'un coup, et ce qui attend.
 *
 * Le carnet entier est déjà dans la page - le tri se fait ici, faute de
 * pouvoir trier des colonnes chiffrées en SQL - mais dessiner mille cartes
 * d'un coup fige l'écran pour rien : on n'en lit jamais mille. Le reste
 * arrive à la demande, et le compteur repart dès qu'on change de dossier ou
 * qu'on tape autre chose.
 */
const PAGE = 60;
const shown = ref(PAGE);

watch([currentFolderId, query, sort, direction, flat, activeTag], () => {
    shown.value = PAGE;
});

const pagedFolders = computed(() => shownFolders.value.slice(0, shown.value));

const pagedNotes = computed(() =>
    shownNotes.value.slice(0, Math.max(0, shown.value - pagedFolders.value.length)),
);

const hasMore = computed(
    () => shownFolders.value.length + shownNotes.value.length > shown.value,
);

/**
 * Les dernières notes touchées, en tête du carnet.
 *
 * Craft ouvre sur elles, et c'est la question posée neuf fois sur dix en
 * arrivant : « où en étais-je ». Seulement à la racine, et seulement sans
 * recherche : dans un dossier, ce qu'on cherche est le contenu du dossier.
 */
const recent = computed(() => {
    if (
        null !== currentFolderId.value ||
        null !== activeTag.value ||
        "" !== query.value.trim()
    ) {
        return [];
    }

    return [...props.notes]
        .sort((a, b) => Date.parse(b.updatedAt ?? 0) - Date.parse(a.updatedAt ?? 0))
        .slice(0, 4);
});

const viewOptions = computed(() => [
    { value: "mosaic", icon: LayoutGrid, label: t("notes.markdown.library.view.mosaic") },
    { value: "cards", icon: Rows3, label: t("notes.markdown.library.view.cards") },
    { value: "list", icon: List, label: t("notes.markdown.library.view.list") },
]);

/**
 * Le tri se replie comme la recherche, mais pas sur le même signal.
 *
 * Son panneau est téléporté hors du bouton : un `focusout` posé là le
 * fermerait au moment même où l'on clique une option, et le clic
 * n'arriverait jamais. C'est donc le sélecteur lui-même qui dit quand il se
 * referme - qu'on ait choisi, appuyé sur Échap, ou cliqué ailleurs - et le
 * contrôle se replie avec lui. L'icône dit en infobulle quel critère est en
 * vigueur : un tri replié dont on ignore la valeur serait pire qu'un tri
 * qui prend de la place.
 *
 * C'est aussi pourquoi le clavier va au sélecteur lui-même et non à un
 * champ : sans recherche dedans, il n'y a pas d'`input`, et c'est la racine
 * du contrôle qui porte le focus. Elle ouvre la liste en le recevant, donc
 * un clic sur l'icône déroule les critères au lieu de poser une boîte
 * fermée que le lecteur devrait cliquer une seconde fois.
 */
const {
    open: sortOpen,
    box: sortBox,
    reveal: openSort,
    fold: foldSort,
} = useFoldable();

function chooseSort(value) {
    setSort(value);
    foldSort();
}

const sortOptions = computed(() =>
    [
        { value: "name", label: t("notes.markdown.library.sort.name") },
        { value: "updated", label: t("notes.markdown.library.sort.updated") },
        { value: "created", label: t("notes.markdown.library.sort.created") },
        // L'ordre manuel se range dans un dossier ; à plat, deux notes de
        // deux dossiers n'ont pas de position commune à comparer.
        ...(flat.value
            ? []
            : [{ value: "manual", label: t("notes.markdown.library.sort.manual") }]),
    ],
);

/** Le critère en vigueur, écrit, pour que l'icône puisse le dire. */
const sortLabel = computed(
    () => sortOptions.value.find((one) => one.value === sort.value)?.label ?? "",
);

/**
 * La couleur d'un dossier, s'il en porte une.
 *
 * Posée en style plutôt qu'en classe : c'est une valeur libre, choisie par
 * le lecteur, et Tailwind ne génère que les classes qu'il voit écrites.
 */
function folderTint(folder) {
    return folder.color ? { color: folder.color } : null;
}

function folderLabel(folder) {
    return folder.name || t("notes.markdown.folders.untitled");
}

/**
 * Où vit une note, dit sur sa carte - seulement quand la liste est à plat.
 *
 * Rangée, la réponse est le dossier qu'on vient d'ouvrir, et la répéter sur
 * chaque carte serait du bruit. À plat, c'est l'information qui manque : on
 * voit tout, et on ne sait plus d'où ça vient.
 */
function noteFolderLabel(note) {
    if (!flat.value && null === activeTag.value) return null;

    const name = folderNameOf(note.folderId);

    return null === name
        ? null
        : name || t("notes.markdown.folders.untitled");
}

function noteLabel(note) {
    return note.title || t("notes.markdown.untitled");
}

// ── Choisir plusieurs choses à la fois ─────────────────────────────

/**
 * La sélection, et les deux gestes qu'elle sert.
 *
 * Ranger un carnet, c'est rarement déplacer une note : c'est en déplacer
 * douze. Une case sur chaque carte, une barre qui dit combien, et les deux
 * actions qui valaient la peine d'être groupées - déplacer et supprimer. Le
 * reste (renommer, exporter) n'a pas de sens au pluriel.
 *
 * Les clés portent la nature avec l'identifiant : une note 3 et un dossier 3
 * ne sont pas la même chose, et un simple identifiant les aurait confondus.
 */
const selected = ref(new Set());

const keyOf = (kind, item) => `${kind}:${item.id}`;

const selectionCount = computed(() => selected.value.size);

function isSelected(kind, item) {
    return selected.value.has(keyOf(kind, item));
}

function toggleSelection(kind, item) {
    const key = keyOf(kind, item);
    const next = new Set(selected.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    selected.value = next;
}

function clearSelection() {
    selected.value = new Set();
}

// Changer de dossier vide la sélection : ce qu'elle contient n'est plus à
// l'écran, et agir dessus de loin est la meilleure façon de déplacer ce
// qu'on ne regardait pas.
watch(currentFolderId, clearSelection);

/** Les éléments choisis, rendus à leur nature et à leur objet. */
function selectedItems() {
    const items = [];

    for (const key of selected.value) {
        const [kind, rawId] = key.split(":");
        const id = Number(rawId);
        const source = "folder" === kind ? props.folders : props.notes;
        const item = source.find((one) => Number(one.id) === id);

        if (item) items.push({ kind, item });
    }

    return items;
}

async function moveSelection(targetFolderId) {
    const items = selectedItems();
    let refused = 0;

    // En série plutôt qu'en parallèle : chaque déplacement est une écriture,
    // et le serveur refuse un dossier rangé dans sa propre branche - une
    // rafale rendrait l'ordre des refus imprévisible.
    for (const { kind, item } of items) {
        if ("folder" === kind && Number(item.id) === targetFolderId) continue;

        const moved = await applyMove(kind, Number(item.id), targetFolderId, {
            quiet: true,
        });

        if (!moved) ++refused;
    }

    clearSelection();
    emit("changed");

    // Un message par refus, pour douze éléments, c'est douze messages
    // empilés sur ce qu'on voulait lire : la fin du geste se dit une fois.
    if (refused) {
        toast.error(t("notes.markdown.library.some_refused", { count: refused }));

        return;
    }

    toast.success(t("notes.markdown.folders.moved"));
}

async function deleteSelection() {
    let failed = 0;

    for (const { kind, item } of selectedItems()) {
        const { ok } =
            "folder" === kind
                ? await props.foldersApi.remove(item.id)
                : await props.notesApi.remove(item.id);

        if (!ok) ++failed;
    }

    clearSelection();
    emit("changed");

    if (failed) {
        toast.error(t("notes.markdown.library.some_refused", { count: failed }));
    }
}

// ── Créer, renommer ────────────────────────────────────────────────
const nameModal = ref(null);
const nameValue = ref("");
const nameColor = ref(null);
const nameSaving = ref(false);

function askForFolderName(folder = null, parentId = undefined) {
    nameModal.value = folder ?? {
        id: null,
        parentId: undefined === parentId ? currentFolderId.value : parentId,
    };
    nameValue.value = folder?.name ?? "";
    nameColor.value = folder?.color ?? null;
}

async function submitName() {
    if (!nameModal.value) return;

    nameSaving.value = true;

    const { id } = nameModal.value;
    const { ok, reported } = null === id
        ? await props.foldersApi.create(
            nameValue.value,
            nameModal.value.parentId ?? null,
            nameColor.value,
        )
        : await props.foldersApi.rename(
            id,
            nameValue.value,
            nameModal.value.parentId ?? null,
            nameColor.value,
        );

    nameSaving.value = false;

    if (!ok) {
        if (!reported) {
            const failed = null === id
                ? "notes.markdown.folders.errors.create_failed"
                : "notes.markdown.folders.errors.rename_failed";

            toast.error(t(failed));
        }

        return;
    }

    const done = null === id
        ? "notes.markdown.folders.created"
        : "notes.markdown.folders.renamed";

    toast.success(t(done));
    nameModal.value = null;
    emit("changed");
}

// ── Supprimer ──────────────────────────────────────────────────────

/**
 * Une note aussi se supprime d'ici.
 *
 * Elle ne se supprimait que depuis l'éditeur, ce qui obligeait à ouvrir une
 * note pour s'en défaire - et à lire d'abord ce qu'on voulait jeter. Les
 * deux natures passent par la même confirmation, avec le mot juste : un
 * dossier emporte ce qu'il contient, une note part seule.
 */
const pendingDelete = ref(null);
const deleting = ref(false);

function askToDelete(kind, item) {
    pendingDelete.value = { kind, item };
}

async function confirmDelete() {
    if (!pendingDelete.value) return;

    const { kind, item } = pendingDelete.value;

    if ("selection" === kind) {
        deleting.value = true;
        await deleteSelection();
        deleting.value = false;
        pendingDelete.value = null;

        return;
    }

    deleting.value = true;
    const { ok, reported } =
        "folder" === kind
            ? await props.foldersApi.remove(item.id)
            : await props.notesApi.remove(item.id);
    deleting.value = false;

    const isFolder = "folder" === kind;

    if (!ok) {
        if (!reported) {
            // La clé se choisit avant l'appel : `t()` est lu par un test qui
            // relève les clés du fichier, et une ternaire dans ses
            // parenthèses lui fait relever « folder ».
            const failed = isFolder
                ? "notes.markdown.folders.errors.delete_failed"
                : "notes.markdown.errors.delete_failed";

            toast.error(t(failed));
        }

        return;
    }

    const done = isFolder
        ? "notes.markdown.folders.deleted"
        : "notes.markdown.saved";

    toast.success(t(done));
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

    if ("selection" === moving.value.kind) {
        for (const { kind, item } of selectedItems()) {
            if ("folder" === kind) excludeBranch(Number(item.id), excluded);
        }
    }

    if ("folder" === moving.value.kind) {
        excludeBranch(Number(moving.value.item.id), excluded);
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

/**
 * Un dossier et tout ce qui pend dessous, marqués comme interdits.
 *
 * Le parcours tient une liste de ce qu'il a déjà vu : le serveur refuse les
 * cycles, mais une ligne modifiée à la main en ferait un, et une boucle sans
 * garde bloquerait l'onglet plutôt que d'afficher un menu incomplet.
 */
function excludeBranch(rootId, excluded) {
    const queue = [rootId];

    while (queue.length) {
        const id = queue.shift();

        if (excluded.has(id)) continue;

        excluded.add(id);

        for (const folder of props.folders) {
            if (Number(folder.parentId) === id) queue.push(Number(folder.id));
        }
    }
}

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

function askToMove(kind, item = null) {
    moving.value = { kind, item };
    moveTarget.value = "";
}

async function submitMove() {
    if (!moving.value) return;

    const target = "" === moveTarget.value ? null : Number(moveTarget.value);

    if ("selection" === moving.value.kind) {
        await moveSelection(target);
    } else {
        await applyMove(moving.value.kind, Number(moving.value.item.id), target);
    }

    moving.value = null;
}

async function applyMove(kind, id, targetFolderId, { quiet = false } = {}) {
    const { ok, reported, payload } =
        "folder" === kind
            ? await props.foldersApi.move(id, targetFolderId)
            : await props.notesApi.move(id, targetFolderId);

    if (!ok) {
        // Un déplacement de groupe compte ses refus et le dit une fois ;
        // seul, il se dit tout de suite.
        if (!reported && !quiet) {
            const failed =
                "refused" === payload?.error
                    ? "notes.markdown.folders.errors.move_refused"
                    : "notes.markdown.folders.errors.move_failed";

            toast.error(t(failed, { max: props.maxDepth }));
        }

        return false;
    }

    if (!quiet) {
        toast.success(t("notes.markdown.folders.moved"));
        emit("changed");
    }

    return true;
}

// ── Glisser-déposer ────────────────────────────────────────────────
//
// Le format du presse-papier est partagé avec le panneau du menu, d'où l'on
// glisse aussi : voir `noteDrag.js`.
const dragOverId = ref(null);
const rootDragOver = ref(false);
const dragging = ref(null);

function onDragStart(kind, item, event) {
    if (!event.dataTransfer) return;

    dragging.value = { kind, id: Number(item.id) };
    startNoteDrag(event, kind, item.id);
}

function onDragEnd() {
    dragging.value = null;
    dragOverId.value = null;
    rootDragOver.value = false;
}

function acceptsDrop(event) {
    return Boolean(event.dataTransfer?.types.includes(NOTE_DRAG_MIME));
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

    const dragged = readNoteDrag(event);

    dragOverId.value = null;
    rootDragOver.value = false;
    dragging.value = null;

    if (!dragged) return;
    if ("folder" === dragged.kind && dragged.id === targetFolderId) return;

    await applyMove(dragged.kind, dragged.id, targetFolderId);
}

// ── L'aperçu au survol ─────────────────────────────────────────────

/**
 * L'aperçu au survol : le rendu de la note, pas sa source.
 *
 * Le contenu est chiffré, donc il n'arrive pas avec la liste ; l'aperçu le
 * demande à la carte survolée, une fois, et le garde. Il ne s'ouvre pas
 * pendant un glisser : on est en train de ranger, pas de lire.
 */
const {
    noteId: previewId,
    content: previewContent,
    loading: previewLoading,
    position: previewAt,
    open: openPreview,
    close: closePreview,
} = useNotePreview({
    fetchNote: (id) =>
        "function" === typeof props.notesApi.show
            ? props.notesApi.show(id)
            : Promise.resolve({ ok: false, payload: {} }),
});

function hoverNote(note, event) {
    if (dragging.value) return;

    openPreview(note, event.currentTarget);
}

// ── Le clavier ─────────────────────────────────────────────────────

/**
 * Se déplacer sans la souris.
 *
 * Les cartes sont une liste : les flèches y descendent, Entrée ouvre,
 * Retour arrière remonte d'un dossier, Espace choisit, Échap lâche tout.
 * `n` fait une note, `N` un dossier - pas `Cmd+N`, que le navigateur garde
 * pour lui et qui ouvrirait une fenêtre par-dessus l'écran.
 *
 * Rien de tout cela quand on écrit : un champ, une zone de texte ou un
 * contenu éditable garde ses touches, sinon taper « nouvelle » dans la
 * recherche créerait deux notes.
 */
const focused = ref(-1);

const navigable = computed(() => [
    ...pagedFolders.value.map((item) => ({ kind: "folder", item })),
    ...pagedNotes.value.map((item) => ({ kind: "note", item })),
]);

function isFocused(kind, item) {
    const current = navigable.value[focused.value];

    return Boolean(
        current && current.kind === kind && Number(current.item.id) === Number(item.id),
    );
}

function typing(event) {
    const node = event.target;

    if (!node || !node.tagName) return false;

    return (
        ["INPUT", "TEXTAREA", "SELECT"].includes(node.tagName) ||
        true === node.isContentEditable
    );
}

function onKeydown(event) {
    // Une modale ouverte a ses propres touches, et le clavier de la
    // bibliothèque n'a rien à dire par-dessus.
    if (typing(event) || nameModal.value || moving.value || pendingDelete.value) {
        return;
    }

    const total = navigable.value.length;

    switch (event.key) {
    case "ArrowDown":
    case "ArrowRight":
        if (!total) return;
        event.preventDefault();
        focused.value = (focused.value + 1) % total;

        return;

    case "ArrowUp":
    case "ArrowLeft":
        if (!total) return;
        event.preventDefault();
        focused.value = (focused.value - 1 + total) % total;

        return;

    case "Enter": {
        const current = navigable.value[focused.value];
        if (!current) return;
        event.preventDefault();

        if ("folder" === current.kind) {
            openFolder(current.item.id);
        } else {
            emit("open-note", current.item.id);
        }

        return;
    }

    case " ": {
        const current = navigable.value[focused.value];
        if (!current) return;
        event.preventDefault();
        toggleSelection(current.kind, current.item);

        return;
    }

    case "Backspace":
        if (null === currentFolderId.value) return;
        event.preventDefault();
        openFolder(path.value.at(-2)?.id ?? null);

        return;

    case "Escape":
        clearSelection();

        return;

    // La barre oblique ouvre la recherche : la convention est celle de
    // GitHub et de Craft, et elle évite d'aller viser la loupe.
    case "/":
        event.preventDefault();
        void openSearch();

        return;

    case "n":
        event.preventDefault();
        emit("create-note", currentFolderId.value);

        return;

    case "N":
        event.preventDefault();
        askForFolderName();
    }
}

onMounted(() => window.addEventListener("keydown", onKeydown));
onUnmounted(() => window.removeEventListener("keydown", onKeydown));

// Ce qui était visé peut disparaître : changer de dossier, filtrer, trier.
watch([currentFolderId, query, sort, direction, flat, activeTag], () => {
    focused.value = -1;
});

// ── L'ordre manuel ─────────────────────────────────────────────────

/**
 * Monter et descendre, plutôt qu'une ligne d'insertion au glisser.
 *
 * Le glisser sert déjà à ranger : lâcher une carte sur un dossier la met
 * dedans. Lui faire dire aussi « insère-toi ici » demande de distinguer le
 * bord d'une carte de son milieu, ce qui se rate au doigt. Deux entrées dans
 * le menu de la carte disent la même chose sans ambiguïté, et marchent au
 * clavier.
 *
 * Offert seulement quand le tri est manuel : déplacer une carte d'un cran
 * dans une liste triée par date ne voudrait rien dire, puisque le tri la
 * remettrait où elle était.
 */
const manualOrder = computed(() => "manual" === sort.value && !flat.value);

async function nudge(kind, item, delta) {
    const list = "folder" === kind ? [...shownFolders.value] : [...shownNotes.value];
    const from = list.findIndex((one) => Number(one.id) === Number(item.id));
    const to = from + delta;

    if (from < 0 || to < 0 || to >= list.length) return;

    list.splice(to, 0, ...list.splice(from, 1));

    // Les positions se comptent dans l'ordre croissant, pas dans celui de
    // l'écran : en ordre décroissant, « monter » veut dire une position plus
    // grande, et numéroter ce qu'on voit inverserait la liste à chaque clic.
    const ordered = "desc" === direction.value ? [...list].reverse() : list;

    // Toute la liste repart avec des positions contiguës : renuméroter deux
    // lignes suffirait tant que personne n'a jamais partagé une position,
    // et une importation en donne toujours.
    const entries = ordered.map((one, position) =>
        "folder" === kind
            ? { id: Number(one.id), parentId: currentFolderId.value, position }
            : { id: Number(one.id), folderId: currentFolderId.value, position },
    );

    const { ok, reported } =
        "folder" === kind
            ? await props.foldersApi.reorder(entries)
            : await props.notesApi.reorder(entries);

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.errors.reorder_failed"));

        return;
    }

    emit("changed");
}

/**
 * Épingler, ou décrocher.
 *
 * Un carnet a trois ou quatre endroits où l'on retourne tous les jours, et
 * les chercher dans l'arbre à chaque fois est une corvée que Craft supprime
 * avec ses favoris. L'action dit ce qu'elle va faire, pas l'état actuel :
 * « Épingler » sur ce qui ne l'est pas.
 */
function favoriteAction(kind, item) {
    const pinned = Boolean(item.favoritedAt);

    return {
        key: "favorite",
        title: pinned
            ? t("notes.markdown.library.unpin")
            : t("notes.markdown.library.pin"),
        icon: pinned ? PinOff : Pin,
        onSelect: () => togglePin(kind, item),
    };
}

async function togglePin(kind, item) {
    const { ok, reported } =
        "folder" === kind
            ? await props.foldersApi.favorite(item.id)
            : await props.notesApi.favorite(item.id);

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.library.pin_failed"));

        return;
    }

    emit("changed");
}

function orderActions(kind, item) {
    if (!manualOrder.value) return [];

    return [
        {
            key: "up",
            title: t("notes.markdown.library.sort.move_up"),
            icon: ArrowUp,
            onSelect: () => nudge(kind, item, -1),
        },
        {
            key: "down",
            title: t("notes.markdown.library.sort.move_down"),
            icon: ArrowDown,
            onSelect: () => nudge(kind, item, 1),
        },
    ];
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
        favoriteAction("folder", folder),
        ...orderActions("folder", folder),
        {
            key: "delete",
            title: t("notes.markdown.folders.delete"),
            icon: Trash2,
            color: "rose",
            onSelect: () => askToDelete("folder", folder),
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
        favoriteAction("note", note),
        ...orderActions("note", note),
        {
            key: "export",
            title: t("notes.markdown.export.one"),
            icon: FileDown,
            href: props.noteExportUrlFor(note.id),
        },
        {
            key: "delete",
            title: t("notes.markdown.delete"),
            icon: Trash2,
            color: "rose",
            onSelect: () => askToDelete("note", note),
        },
    ];
}

/**
 * La date d'une carte, ou rien.
 *
 * `Intl` lève une `RangeError` sur une date qu'il ne comprend pas, et une
 * exception pendant le rendu emporte le composant entier : la page devient
 * un cadre vide, sans un mot. C'est arrivé le 23/09, avec des dates que le
 * serveur envoyait en objets plutôt qu'en chaînes. Le serveur est corrigé,
 * et l'affichage ne dépend plus de sa bonne volonté.
 */
/**
 * Toute la carte ouvre, pas seulement son titre.
 *
 * Une carte est une cible large, c'est ce qu'elle promet en occupant cette
 * place ; ne rendre cliquable que ses vingt pixels de titre oblige à viser.
 * Le titre reste un lien, pour le clic du milieu et pour « ouvrir dans un
 * nouvel onglet ».
 *
 * Deux gestes ne sont pas des ouvertures et sont laissés tranquilles : ce
 * qui part d'un bouton ou d'un lien (la case à cocher, le menu, le titre
 * lui-même, qui ont leur propre réponse), et un clic qui vient de terminer
 * une sélection de texte - lire un extrait en le surlignant ne doit pas
 * quitter la page.
 */
function onCardClick(kind, item, event) {
    if (event.target.closest("button, a")) return;

    const selection = window.getSelection?.();
    if (selection && "" !== String(selection)) return;

    if ("folder" === kind) {
        openFolder(item.id);

        return;
    }

    emit("open-note", item.id);
}

function updatedLabel(item) {
    return Number.isFinite(Date.parse(item.updatedAt))
        ? formatDateTimeNumeric(item.updatedAt)
        : "";
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
    filterByTag: (value) => setTag(value),
    askForFolderName,
    askToDelete: (folder) => askToDelete("folder", folder),
    dropInto: (folderId, event) => onDropOn(folderId, event),
});
</script>

<template>
    <div class="flex flex-col min-h-0 flex-1">
        <header class="flex flex-col gap-3 border-b border-line p-3 sm:p-4">
            <!-- Le fil d'Ariane est aussi une cible : remonter d'un niveau se
                 fait en y glissant ce qu'on tient, sans ouvrir de modale. -->
            <!-- Première ligne : où l'on est, et ce qu'on peut y créer. -->
            <div class="flex items-start justify-between gap-3">
                <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm" :aria-label="t('notes.markdown.library.title')">
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

                    <!-- L'étiquette regardée prend la place du fil : elle
                         traverse le carnet, donc le chemin d'un dossier ne
                         décrit plus ce qui est à l'écran. La croix rend le
                         dossier où l'on était, qui n'a pas bougé. -->
                    <template v-if="null !== activeTag">
                        <ChevronRight class="w-3.5 h-3.5 text-muted shrink-0" :stroke-width="2" />
                        <span class="inline-flex items-center gap-1 rounded-full bg-accent-600/15 px-2 py-1 text-xs font-medium text-accent-400">
                            <Tag class="h-3 w-3" :stroke-width="2" />
                            {{ activeTag }}
                            <button
                                type="button"
                                class="transition-colors hover:text-primary"
                                :title="t('notes.markdown.library.tag.clear')"
                                :aria-label="t('notes.markdown.library.tag.clear')"
                                v-on:click="setTag(null)"
                            >
                                <X class="h-3 w-3" :stroke-width="2" />
                            </button>
                        </span>
                    </template>

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

                <!-- Icônes seules : le libellé prenait la moitié de la barre
                     pour dire ce qu'un « + » dit aussi bien, et l'infobulle
                     le nomme pour qui hésite. -->
                <div class="flex shrink-0 items-center gap-1">
                    <AppIconButton
                        color="accent"
                        :title="t('notes.markdown.library.new_folder')"
                        :aria-label="t('notes.markdown.library.new_folder')"
                        v-on:click="askForFolderName()"
                    >
                        <FolderPlus class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>

                    <AppButton
                        variant="primary"
                        size="md"
                        class="min-h-11 !px-2.5 sm:min-h-0"
                        :title="t('notes.markdown.library.new_note')"
                        :aria-label="t('notes.markdown.library.new_note')"
                        v-on:click="emit('create-note', currentFolderId)"
                    >
                        <Plus class="h-4 w-4" :stroke-width="2" />
                    </AppButton>
                </div>
            </div>

            <!-- Deuxième ligne : comment on regarde. Ce qui *crée* est
                 monté d'un cran, à droite du fil d'Ariane, parce que ces
                 gestes portent sur l'endroit où l'on est, pas sur la façon
                 de le lire. Une barre unique les mélangeait, et huit
                 contrôles collés se lisaient comme un mur. -->
            <!-- Tout à droite, en un seul groupe : la loupe seule à gauche
                 laissait un vide de la moitié de la barre pour un bouton de
                 trente pixels. Ouverte, la recherche prend sa place dans le
                 groupe et repousse le reste, au lieu de traverser l'écran. -->
            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                <AppIconButton
                    v-if="!searchOpen"
                    :title="t('notes.markdown.library.search_placeholder')"
                    :aria-label="t('notes.markdown.library.search_placeholder')"
                    v-on:click="openSearch()"
                >
                    <Search class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>

                <div
                    v-else
                    ref="searchBox"
                    class="w-full sm:w-64"
                    v-on:keyup.esc="closeSearch"
                    v-on:focusout="closeSearch"
                >
                    <AppSearchInput
                        v-model="query"
                        :placeholder="t('notes.markdown.library.search_placeholder')"
                    />
                </div>

                <div class="flex items-center gap-3">
                    <!-- Rangé ou tout à plat. Deux lectures du même carnet :
                         ce que l'endroit contient, ou toutes les notes d'ici
                         et de dessous d'un coup, pour retrouver ce dont on
                         ne sait plus où on l'a mis. -->
                    <AppIconButton
                        :class="flat ? 'text-accent-400' : ''"
                        :title="flat ? t('notes.markdown.library.scope.grouped') : t('notes.markdown.library.scope.flat')"
                        :aria-label="flat ? t('notes.markdown.library.scope.grouped') : t('notes.markdown.library.scope.flat')"
                        :aria-pressed="flat"
                        v-on:click="toggleFlat"
                    >
                        <Layers v-if="flat" class="h-4 w-4" :stroke-width="2" />
                        <FolderTree v-else class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>

                    <div class="inline-flex overflow-hidden rounded-md border border-line">
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
                            <component :is="opt.icon" class="h-4 w-4" :stroke-width="2" />
                        </AppTab>
                    </div>

                    <!-- Le sens est un bouton séparé du critère, comme chez
                         Craft : changer d'ordre ne doit pas demander de
                         rouvrir la liste des critères. -->
                    <div class="flex items-center gap-1">
                        <!-- Replié en icône, comme la recherche : le
                             critère se change par à-coups et n'a pas à
                             occuper sa largeur en permanence. -->
                        <AppIconButton
                            v-if="!sortOpen"
                            :title="`${t('notes.markdown.library.sort.label')} : ${sortLabel}`"
                            :aria-label="`${t('notes.markdown.library.sort.label')} : ${sortLabel}`"
                            v-on:click="openSort('.multiselect')"
                        >
                            <ArrowUpDown class="h-4 w-4" :stroke-width="2" />
                        </AppIconButton>

                        <!-- Le sélecteur de la maison plutôt que le
                             `<select>` natif : même allure que partout
                             ailleurs dans le back-office. Pas de recherche
                             dedans, quatre critères ne se cherchent pas. -->
                        <div v-else ref="sortBox" class="w-44" v-on:keyup.esc="foldSort">
                            <AppMultiselect
                                :model-value="sort"
                                :options="sortOptions"
                                :searchable="false"
                                v-on:update:model-value="chooseSort($event)"
                                v-on:close="foldSort"
                            />
                        </div>
                        <AppIconButton
                            :title="'asc' === direction ? t('notes.markdown.library.sort.asc') : t('notes.markdown.library.sort.desc')"
                            v-on:click="toggleDirection"
                        >
                            <ArrowUpNarrowWide v-if="'asc' === direction" class="h-4 w-4" :stroke-width="2" />
                            <ArrowDownWideNarrow v-else class="h-4 w-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </div>
            </div>
        </header>

        <!-- Ce que la sélection permet, quand il y en a une. Une barre
             plutôt qu'un menu : ce qui est choisi doit rester compté sous
             les yeux pendant qu'on décide. -->
        <div
            v-if="selectionCount"
            class="flex flex-wrap items-center gap-2 border-b border-line bg-surface-2 px-3 py-2 sm:px-4"
        >
            <span class="text-sm text-primary">
                {{ t('notes.markdown.library.selected', { count: selectionCount }) }}
            </span>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <AppButton variant="ghost" size="sm" v-on:click="askToMove('selection')">
                    <FolderInput class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.folders.move_to') }}
                </AppButton>
                <AppButton variant="danger" size="sm" v-on:click="askToDelete('selection', null)">
                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.delete') }}
                </AppButton>
                <AppIconButton
                    :title="t('notes.markdown.library.clear_selection')"
                    size="sm"
                    v-on:click="clearSelection"
                >
                    <X class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
            </div>
        </div>

        <div
            class="flex-1 min-h-0 overflow-auto p-3 sm:p-4"
            :class="rootDragOver ? 'bg-accent-500/5' : ''"
            v-on:dragover="onDragOverCrumb(null, $event)"
            v-on:drop="onDropOn(null, $event)"
        >
            <!-- Une étiquette sans note a son propre mot : « ce dossier est
                 vide » serait faux, le dossier n'y est pour rien. -->
            <AppNoData
                v-if="null !== activeTag && isEmpty"
                :title="t('notes.markdown.library.tag.none')"
                :description="t('notes.markdown.library.tag.none_description', { tag: activeTag })"
                :icon="Tag"
            />

            <AppNoData
                v-else-if="isEmpty"
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

            <!-- Tout le reste tient dans une seule branche : un `v-else`
                 doit suivre son `v-if` immédiatement, et la rangée des
                 récentes s'était glissée entre les deux. -->
            <template v-else>
                <section v-if="recent.length" class="mb-5">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                        {{ t('notes.markdown.library.recent') }}
                    </h3>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <a
                            v-for="note in recent"
                            :key="`recent-${note.id}`"
                            :href="noteUrlFor(note.id)"
                            class="flex min-w-0 items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-sm no-underline transition-colors hover:border-accent-500/50"
                            v-on:click.prevent="emit('open-note', note.id)"
                        >
                            <FileText class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                            <span class="truncate text-primary">{{ noteLabel(note) }}</span>
                        </a>
                    </div>
                </section>

                <!-- Mosaïque et cartes partagent la grille et ne diffèrent que par
                 la hauteur des tuiles : une seule colonne sur téléphone, c'est
                 la règle de la maison depuis le 14/09. -->
                <div v-if="'list' !== view">
                    <div
                        class="grid grid-cols-1 gap-3"
                        :class="'mosaic' === view ? 'sm:grid-cols-2 xl:grid-cols-3' : 'sm:grid-cols-3 xl:grid-cols-4'"
                    >
                        <article
                            v-for="folder in pagedFolders"
                            :key="`folder-${folder.id}`"
                            class="group flex cursor-pointer flex-col rounded-lg border bg-surface transition-colors"
                            :class="[
                                `folder:${folder.id}` === dragOverId ? 'border-accent-500 bg-accent-500/10' : 'border-line hover:border-accent-500/50',
                                isSelected('folder', folder) ? 'ring-2 ring-accent-500' : '',
                                isFocused('folder', folder) ? 'ring-2 ring-accent-500/60' : '',
                                'mosaic' === view ? 'p-4' : 'p-3',
                            ]"
                            draggable="true"
                            v-on:dragstart="onDragStart('folder', folder, $event)"
                            v-on:dragend="onDragEnd"
                            v-on:dragover="onDragOverFolder(folder, $event)"
                            v-on:dragleave="onDragLeaveFolder(folder, $event)"
                            v-on:drop="onDropOn(Number(folder.id), $event)"
                            v-on:click="onCardClick('folder', folder, $event)"
                        >
                            <div class="flex items-start gap-2">
                                <!-- La case précède le titre au lieu de le
                                     recouvrir : en surimpression, elle
                                     tombait sur le nom des dossiers courts. -->
                                <button
                                    type="button"
                                    class="shrink-0 pt-0.5"
                                    :title="t('notes.markdown.library.select')"
                                    v-on:click.stop="toggleSelection('folder', folder)"
                                >
                                    <AppSelectionCheck :active="isSelected('folder', folder)" size="xs" />
                                </button>

                                <!-- Un clic ouvre, un double-clic renomme :
                                     c'est le geste d'un explorateur de
                                     fichiers, et le menu garde l'entrée
                                     pour qui ne le connaît pas. -->
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                    v-on:click="openFolder(folder.id)"
                                    v-on:dblclick.stop="askForFolderName(folder)"
                                >
                                    <Folder
                                        class="w-5 h-5 shrink-0 text-accent-500"
                                        :style="folderTint(folder)"
                                        :stroke-width="2"
                                    />
                                    <span class="truncate font-medium text-primary">{{ folderLabel(folder) }}</span>
                                </button>

                                <AppRowActions :actions="folderActions(folder)" :label="folderLabel(folder)" />
                            </div>

                            <p class="mt-2 text-xs text-muted">
                                {{ t('notes.markdown.folders.contents', { folders: folder.folderCount ?? 0, notes: folder.noteCount ?? 0 }) }}
                            </p>
                        </article>

                        <article
                            v-for="note in pagedNotes"
                            :key="`note-${note.id}`"
                            class="group flex cursor-pointer flex-col rounded-lg border border-line bg-surface transition-colors hover:border-accent-500/50"
                            :class="[
                                isSelected('note', note) ? 'ring-2 ring-accent-500' : '',
                                isFocused('note', note) ? 'ring-2 ring-accent-500/60' : '',
                                'mosaic' === view ? 'p-4 min-h-[8rem]' : 'p-3',
                            ]"
                            draggable="true"
                            v-on:dragstart="onDragStart('note', note, $event)"
                            v-on:dragend="onDragEnd"
                            v-on:click="onCardClick('note', note, $event)"
                            v-on:mouseenter="hoverNote(note, $event)"
                            v-on:mouseleave="closePreview"
                        >
                            <div class="flex items-start gap-2">
                                <button
                                    type="button"
                                    class="shrink-0 pt-0.5"
                                    :title="t('notes.markdown.library.select')"
                                    v-on:click.stop="toggleSelection('note', note)"
                                >
                                    <AppSelectionCheck :active="isSelected('note', note)" size="xs" />
                                </button>

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

                            <!-- Les premières lignes, en mosaïque seulement : c'est
                         ce qui distingue cette vue des cartes, et ce qui
                         permet de reconnaître une note dont le titre ne dit
                         rien. -->
                            <p
                                v-if="'mosaic' === view && note.excerpt"
                                class="mt-2 line-clamp-3 text-sm text-muted"
                            >
                                {{ note.excerpt }}
                            </p>

                            <!-- À plat, le dossier d'où la note vient, et
                                 le chemin pour y aller. -->
                            <button
                                v-if="noteFolderLabel(note)"
                                type="button"
                                class="mt-2 flex w-fit items-center gap-1 text-xs text-muted transition-colors hover:text-accent-400"
                                :title="t('notes.markdown.library.scope.open_folder', { folder: noteFolderLabel(note) })"
                                v-on:click.stop="openFolder(note.folderId)"
                            >
                                <Folder class="h-3 w-3 shrink-0" :stroke-width="2" />
                                <span class="truncate">{{ noteFolderLabel(note) }}</span>
                            </button>

                            <!-- Une étiquette se clique : c'est le geste
                                 qu'on tente en la voyant, et il n'existait
                                 nulle part depuis la refonte. -->
                            <div v-if="note.tags?.length" class="mt-2 flex flex-wrap gap-1">
                                <button
                                    v-for="one in note.tags"
                                    :key="one"
                                    type="button"
                                    :title="t('notes.markdown.library.tag.filter', { tag: one })"
                                    v-on:click.stop="setTag(one)"
                                >
                                    <AppBadge color="gray" size="xs">{{ one }}</AppBadge>
                                </button>
                            </div>

                            <p class="mt-auto pt-2 text-xs text-muted">{{ updatedLabel(note) }}</p>
                        </article>
                    </div>

                    <AppLoadMore
                        v-if="hasMore"
                        class="mt-3"
                        :has-more="hasMore"
                        v-on:load="shown += PAGE"
                    />
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
                                v-for="folder in pagedFolders"
                                :key="`row-folder-${folder.id}`"
                                class="border-t border-line transition-colors"
                                :class="`folder:${folder.id}` === dragOverId ? 'bg-accent-500/10' : ''"
                                draggable="true"
                                v-on:dragstart="onDragStart('folder', folder, $event)"
                                v-on:dragend="onDragEnd"
                                v-on:dragover="onDragOverFolder(folder, $event)"
                                v-on:dragleave="onDragLeaveFolder(folder, $event)"
                                v-on:drop="onDropOn(Number(folder.id), $event)"
                                v-on:click="onCardClick('folder', folder, $event)"
                            >
                                <td class="px-2 py-2">
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 text-left"
                                        v-on:click="openFolder(folder.id)"
                                        v-on:dblclick.stop="askForFolderName(folder)"
                                    >
                                        <Folder
                                            class="w-4 h-4 shrink-0 text-accent-500"
                                            :style="folderTint(folder)"
                                            :stroke-width="2"
                                        />
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
                                v-for="note in pagedNotes"
                                :key="`row-note-${note.id}`"
                                class="border-t border-line"
                                draggable="true"
                                v-on:dragstart="onDragStart('note', note, $event)"
                                v-on:dragend="onDragEnd"
                                v-on:click="onCardClick('note', note, $event)"
                                v-on:mouseenter="hoverNote(note, $event)"
                                v-on:mouseleave="closePreview"
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

                                    <button
                                        v-if="noteFolderLabel(note)"
                                        type="button"
                                        class="mt-0.5 flex items-center gap-1 pl-6 text-xs text-muted transition-colors hover:text-accent-400"
                                        :title="t('notes.markdown.library.scope.open_folder', { folder: noteFolderLabel(note) })"
                                        v-on:click.stop="openFolder(note.folderId)"
                                    >
                                        <Folder class="h-3 w-3 shrink-0" :stroke-width="2" />
                                        <span class="truncate">{{ noteFolderLabel(note) }}</span>
                                    </button>
                                </td>
                                <td class="hidden px-2 py-2 sm:table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <button
                                            v-for="one in note.tags ?? []"
                                            :key="one"
                                            type="button"
                                            :title="t('notes.markdown.library.tag.filter', { tag: one })"
                                            v-on:click.stop="setTag(one)"
                                        >
                                            <AppBadge color="gray" size="xs">{{ one }}</AppBadge>
                                        </button>
                                    </div>
                                </td>
                                <td class="hidden px-2 py-2 text-muted sm:table-cell">{{ updatedLabel(note) }}</td>
                                <td class="px-2 py-2">
                                    <AppRowActions :actions="noteActions(note)" :label="noteLabel(note)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <AppLoadMore
                        v-if="hasMore"
                        class="mt-3"
                        :has-more="hasMore"
                        v-on:load="shown += PAGE"
                    />
                </div>
            </template>
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

            <!-- Une couleur pour reconnaître un dossier sans le lire. Le
                 sélecteur de la maison, présets et hexadécimal, le même
                 qu'aux étiquettes de document. -->
            <AppColorPicker
                v-model="nameColor"
                class="mt-4"
                :label="t('notes.markdown.folders.color')"
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
            <!-- Cherchable, celui-ci : un carnet rangé a des dizaines de
                 dossiers, et dérouler la liste entière pour en viser un
                 serait la même corvée que l'arbre qu'on vient de quitter. -->
            <AppMultiselect
                v-model="moveTarget"
                :options="moveTargets"
                :placeholder="t('notes.markdown.folders.move_to')"
                class="w-full"
            />

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
            :title="'folder' === pendingDelete?.kind ? t('notes.markdown.folders.delete') : t('notes.markdown.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p v-if="'selection' === pendingDelete?.kind" class="text-sm text-primary">
                {{ t('notes.markdown.library.confirm_delete_selection', { count: selectionCount }) }}
            </p>
            <p v-else-if="'folder' === pendingDelete?.kind" class="text-sm text-primary">
                {{ t('notes.markdown.folders.confirm_delete', { name: folderLabel(pendingDelete.item) }) }}
            </p>
            <p v-else-if="pendingDelete" class="text-sm text-primary">
                {{ t('notes.markdown.confirm_delete', { title: noteLabel(pendingDelete.item) }) }}
            </p>
            <p class="mt-2 text-sm text-secondary">
                {{ t('notes.markdown.delete_warning') }}
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

        <!-- L'aperçu vit dans le `body` : la grille défile et rogne, et une
             carte flottante ne doit pas se faire couper par ce qu'elle
             survole. Elle ne prend jamais le pointeur - `pointer-events` à
             none - sinon elle s'interposerait entre le curseur et la carte
             qui l'a ouverte, qui recevrait aussitôt un `mouseleave`. -->
        <Teleport to="body">
            <div
                v-if="null !== previewId"
                class="pointer-events-none fixed z-50 w-90 overflow-hidden rounded-lg border border-line bg-surface p-3 shadow-xl"
                :style="{
                    top: `${previewAt.top}px`,
                    left: `${previewAt.left}px`,
                    maxHeight: '17.5rem',
                }"
            >
                <p v-if="previewLoading" class="text-xs text-muted">
                    {{ t('shared.common.loading') }}
                </p>
                <p v-else-if="'' === previewContent" class="text-xs text-muted">
                    {{ t('notes.markdown.library.preview.empty') }}
                </p>
                <NotePreview v-else :content="previewContent" />
            </div>
        </Teleport>
    </div>
</template>
