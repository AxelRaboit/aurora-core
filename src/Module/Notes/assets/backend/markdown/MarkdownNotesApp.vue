<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useMarkdownNotesPage } from '@notes/backend/markdown/composables/useMarkdownNotesPage.js';
import { useNoteFoldersApi } from '@notes/backend/markdown/composables/useNoteFoldersApi.js';
import NoteLibrary from '@notes/backend/markdown/components/NoteLibrary.vue';
import NotePreview from '@notes/backend/markdown/components/NotePreview.vue';
import NoteSidePanel from '@notes/backend/markdown/components/NoteSidePanel.vue';
import NoteTagManagerModal from '@notes/backend/markdown/components/NoteTagManagerModal.vue';
import NoteShareModal from '@notes/backend/markdown/components/NoteShareModal.vue';
import NoteCoverModal from '@notes/backend/markdown/components/NoteCoverModal.vue';
import NoteEditor from '@notes/backend/markdown/components/NoteEditor.vue';
import NoteGraph from '@notes/backend/markdown/components/NoteGraph.vue';
import AppButton from '@shared/components/action/AppButton.vue';
import AppIconButton from '@shared/components/action/AppIconButton.vue';
import AppSearchInput from '@shared/components/form/input/AppSearchInput.vue';
import AppTagsInput from '@shared/components/form/select/AppTagsInput.vue';
import AppModal from '@shared/components/overlay/AppModal.vue';
import AppModalFooter from '@shared/components/overlay/AppModalFooter.vue';
import AppTab from '@shared/components/nav/AppTab.vue';
import AppRowActions from '@shared/components/action/AppRowActions.vue';
import { computed, nextTick, onErrorCaptured, onMounted, onUnmounted, watch } from 'vue';
import { onPanelRequest, tellPanels } from '@/shared/nav/modulePanelBridge.js';
import { Trash2, BookOpen, FileDown, Image, PanelRightOpen, PanelRightClose, Tag, TriangleAlert, Users, X, Network, Share2 } from 'lucide-vue-next';
import AppNoData from '@shared/components/feedback/AppNoData.vue';
import "@notes/share/appearance.css";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useFoldable } from "@notes/backend/markdown/composables/useFoldable.js";
import { withoutLeadingTitle } from "@notes/backend/markdown/composables/noteBody.js";

const { formatDateTimeNumeric } = useDateFormat();

const props = defineProps({
    notes: { type: Array, default: () => [] },
    /** Every folder of the reader, flat, serialized with its counts. */
    folders: { type: Array, default: () => [] },
    /** The folder the address names, null on the root listing or on a note. */
    folderId: { type: Number, default: null },
    /** The chain the server resolved for that folder, root first. */
    breadcrumb: { type: Array, default: () => [] },
    /** Les routes des dossiers, en un objet plutôt qu'en sept props. */
    folderPaths: { type: Object, required: true },
    /** L'adresse de la bibliothèque, c'est-à-dire du carnet à sa racine. */
    libraryPath: { type: String, required: true },
    maxDepth: { type: Number, default: 8 },
    listPath: { type: String, required: true },
    showPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    movePath: { type: String, required: true },
    favoritePath: { type: String, default: '' },
    reorderPath: { type: String, required: true },
    backlinksPath: { type: String, required: true },
    unlinkedMentionsPath: { type: String, required: true },
    graphPath: { type: String, required: true },
    /** Le carnet entier en zip, une note seule en .md, et le chemin du retour. */
    exportPath: { type: String, required: true },
    exportOnePath: { type: String, required: true },
    importPath: { type: String, required: true },
    searchPath: { type: String, required: true },
    tagsListPath: { type: String, required: true },
    tagsRenamePath: { type: String, required: true },
    tagsMergePath: { type: String, required: true },
    tagsDeletePath: { type: String, required: true },
    sharesListPath: { type: String, required: true },
    sharesPreviewPath: { type: String, required: true },
    sharesCreatePath: { type: String, required: true },
    sharesRevokePath: { type: String, required: true },
    imageUploadPath: { type: String, required: true },
    /** L'adresse qui montre une note seule, sans le back-office. */
    readPath: { type: String, default: '' },
    /** Le relais vers Pexels pour le bandeau : aucune image n'entre en GED. */
    coversSearchPath: { type: String, default: '' },
    /** Ce que les autres ont ouvert à tout le back-office. */
    sharedPath: { type: String, default: '' },
    shareInternallyPath: { type: String, default: '' },
    imageMaxEdge: { type: Number, default: 2048 },
    imageQuality: { type: Number, default: 0.85 },
    /**
     * Client-extension hook - see `docs/aurora-core/dev/entity_extensibility_convention.md`.
     * Shape: `{ <fieldKey>: { default: <value> } }`. Each key is seeded into
     * the form, persisted on save (server-side the client's overridden DTO
     * factory hydrates the entity), and exposed back to the parent through
     * the `extra-form-fields` slot's scoped `form` binding.
     */
    extraFields: { type: Object, default: () => ({}) },
    /** The note this URL is. Decided by the server, not by the browser. */
    activeId: { type: Number, default: null },
});

const { t } = useI18n();

const {
    isMobile,
    api,
    tagsApi,
    notes,
    selectedId,
    selectedNote,
    form,
    deleting,
    lastSavedAt,
    pendingDelete,
    selectNote,
    createNote,
    requestDelete,
    cancelDelete,
    confirmDelete,
    onWikiLinkClick,
    onCheckboxToggle,
    onImageResize,
    onTagsChanged,
    sidePanelOpen,
    graphOpen,
    tagManagerOpen,
    viewMode,
    viewModeOptions,
    lastSavedRelative,
    saveStatusDisplay,
    editorPaneRef,
    editorWidth,
    startSplitResize,
    splitDragging,
    navigateFromGraph,
    refreshList,
} = useMarkdownNotesPage(props, t);

// Local to this component rather than folded into `useMarkdownNotesPage`:
// sharing is opened from the toolbar and closed by the modal, and nothing in
// the page composable reads it.
const shareModalOpen = ref(false);

/**
 * Le bandeau et l'apparence : une seule porte pour « de quoi cette note a
 * l'air ».
 *
 * Les deux s'écrivent dans le formulaire, donc l'enregistrement automatique
 * les emporte comme le reste : choisir une image n'a pas de bouton à
 * valider.
 */
const coverModalOpen = ref(false);

const cover = computed(() => ({
    url: form.value.coverUrl,
    creditName: form.value.coverCreditName,
    creditUrl: form.value.coverCreditUrl,
    position: form.value.coverPosition ?? 50,
}));

function chooseCover(photo) {
    form.value.coverUrl = photo.url;
    form.value.coverCreditName = photo.creditName;
    form.value.coverCreditUrl = photo.creditUrl;
}

function removeCover() {
    form.value.coverUrl = null;
    form.value.coverCreditName = null;
    form.value.coverCreditUrl = null;
    form.value.coverPosition = 50;
}

// `plain` ne pose aucune classe : une note sans habillage suit le thème
// clair ou sombre de la personne, et une classe qui la repeindrait en dur
// lui retirerait ce choix.
const lookClass = computed(() =>
    'plain' === form.value.appearance || !form.value.appearance
        ? ''
        : `note-look note-look-${form.value.appearance}`,
);

/**
 * Le corps tel que l'aperçu le montre : sans le titre répété en tête.
 *
 * Les index des cases à cocher suivent : retirer un titre ne change ni le
 * nombre ni l'ordre des cases, donc cocher dans l'aperçu écrit toujours
 * dans la bonne ligne du source.
 */
const previewBody = computed(() =>
    withoutLeadingTitle(form.value.content, form.value.title),
);

/**
 * Rendre la note ouverte visible par l'équipe, ou la refermer.
 *
 * La bascule n'existait que dans le menu d'une carte, donc depuis la
 * bibliothèque : depuis la note elle-même, la seule chose qui ressemblait
 * à un partage était le lien public, qui est une tout autre chose. Deux
 * gestes différents portaient le même mot, et il en manquait un là où on
 * l'attend.
 */
const readHref = computed(() =>
    selectedId.value && props.readPath
        ? props.readPath.replace('__id__', String(selectedId.value))
        : '',
);

/**
 * Les étiquettes se replient en icône, comme la recherche de la
 * bibliothèque.
 *
 * Elles occupaient une ligne entière de l'en-tête en permanence, c'est-à-dire
 * une ligne de moins pour le texte, pour une chose qu'on touche une fois
 * quand la note naît et plus jamais ensuite. Repliées, l'icône porte leur
 * nombre et les nomme en infobulle : on sait qu'il y en a et lesquelles sans
 * les avoir sous les yeux.
 *
 * Le repli à la perte du focus ne vaut que si le champ est vide, comme pour
 * la recherche : refermer sous un mot à moitié tapé le ferait disparaître.
 */
const {
    open: tagsOpen,
    box: tagsBox,
    reveal: openTags,
    fold: foldTags,
} = useFoldable();

function toggleTags() {
    if (tagsOpen.value) {
        foldTags();

        return;
    }

    void openTags();
}

function closeTagsIfIdle(event) {
    const field = event.currentTarget?.querySelector('input');

    if (field && '' !== field.value.trim()) return;

    foldTags();
}

const tagsLabel = computed(() => {
    // `form` est une `ref` : le modèle s'y lit par `.value`, là où le
    // gabarit le déballe tout seul. Sans cela l'infobulle restait au
    // texte du champ vide pendant que la pastille comptait juste.
    const tags = form.value.tags ?? [];

    return tags.length
        ? `${t('notes.markdown.tags.summary', { count: tags.length })} : ${tags.join(', ')}`
        : t('notes.markdown.tags.add_placeholder');
});

/**
 * Ce qui casse doit se voir.
 *
 * Une erreur dans un composant enfant vide sa zone sans un mot : Vue la
 * consigne dans la console et rend du vide. Axel a eu deux fois un grand
 * cadre blanc pour tout message, et la seule façon de savoir ce qui s'était
 * passé était d'ouvrir les outils de développement. Une page qui échoue doit
 * le dire à qui la regarde, et dire quoi.
 */
const crashed = ref(null);

onErrorCaptured((error) => {
    crashed.value = error;

    // Consigné quand même : le message à l'écran sert la personne, la trace
    // sert celui qui répare.
    console.error('[notes] la page a échoué', error);

    return false;
});

/**
 * Les dossiers, et le va-et-vient entre la bibliothèque et l'éditeur.
 *
 * Une seule page monte les deux : ouvrir une note depuis la bibliothèque
 * écrit son adresse et charge son texte, sans recharger le document. Le
 * retour arrière du navigateur rend la bibliothèque, parce que l'adresse
 * qu'on quitte est une vraie adresse et pas un état interne.
 */
const foldersApi = useNoteFoldersApi(props.folderPaths);
const folders = ref([...props.folders]);

/**
 * La bibliothèque, quand elle est là.
 *
 * Le panneau du menu parle à cette application, montée sur toutes les pages
 * du module ; la bibliothèque n'est montée que lorsque aucune note n'est
 * ouverte. Quand elle manque, une demande du panneau devient une navigation,
 * ce qui est la réponse honnête : on quitte l'éditeur pour aller voir.
 */
const libraryRef = ref(null);

/**
 * Le dossier sous les yeux, qui n'est pas toujours celui de l'adresse
 * initiale : la bibliothèque navigue sans recharger.
 */
const openFolderId = ref(props.folderId);

const sharedWithTeam = computed(() => Boolean(selectedNote.value?.sharedAt));

/**
 * Le dossier qui rend cette note visible sans qu'elle porte rien.
 *
 * Une note rangée dans un dossier ouvert à l'équipe **est** visible, mais
 * sa propre marque est vide : afficher « Rendre visible » sur une note que
 * tout le monde voit déjà serait un mensonge. On remonte donc la chaîne
 * des parents pour nommer le dossier responsable, et la bascule de la note
 * s'efface devant lui - c'est là-bas que ça se change.
 */
const sharingFolder = computed(() => {
    const parId = new Map(folders.value.map((one) => [Number(one.id), one]));

    let dossier = parId.get(Number(selectedNote.value?.folderId));
    const vus = new Set();

    while (dossier && !vus.has(Number(dossier.id))) {
        vus.add(Number(dossier.id));

        if (dossier.sharedAt) return dossier;

        dossier = parId.get(Number(dossier.parentId));
    }

    return null;
});

const visibleToTeam = computed(
    () => sharedWithTeam.value || null !== sharingFolder.value,
);

/**
 * Ce que le menu de la note porte : les gestes qu'on fait une fois.
 *
 * Exporter, envoyer un lien, choisir une image, ouvrir le graphe, rendre
 * la note visible : chacun se fait une fois par note, quand les modes
 * d'affichage, les étiquettes et les liens se touchent en écrivant. Les
 * douze sur une ligne ne laissaient plus de place au titre.
 */
const noteActions = computed(() => {
    const actions = [
        {
            key: "cover",
            title: t('notes.markdown.cover.title'),
            icon: Image,
            onSelect: () => {
                coverModalOpen.value = true;
            },
        },
        {
            key: "share-link",
            title: t('notes.markdown.share.button'),
            icon: Share2,
            onSelect: () => {
                shareModalOpen.value = true;
            },
        },
        {
            key: "team",
            title: sharingFolder.value
                ? t('notes.markdown.library.shared.via_folder', {
                    folder: sharingFolder.value.name || t('notes.markdown.folders.untitled'),
                })
                : sharedWithTeam.value
                    ? t('notes.markdown.library.shared.stop')
                    : t('notes.markdown.library.shared.start'),
            icon: Users,
            // Quand c'est le dossier qui décide, l'entrée mène à lui plutôt
            // que de proposer une bascule qui ne changerait rien.
            ...(sharingFolder.value
                ? { href: folderUrlFor(sharingFolder.value.id) }
                : { onSelect: () => toggleTeamVisibility() }),
        },
        {
            key: "graph",
            title: t('notes.markdown.graph.open'),
            icon: Network,
            onSelect: () => {
                graphOpen.value = true;
            },
        },
        {
            key: "export",
            title: t('notes.markdown.export.one'),
            icon: FileDown,
            onSelect: () => exportOne(selectedId.value),
        },
    ];

    return actions;
});


async function toggleTeamVisibility() {
    if (!selectedId.value) return;

    const etait = sharedWithTeam.value;
    const { ok, reported } = await api.shareInternally(selectedId.value);

    if (!ok) {
        if (!reported) toast.error(t('notes.markdown.library.shared.failed'));

        return;
    }

    toast.success(
        etait
            ? t('notes.markdown.library.shared.stopped')
            : t('notes.markdown.library.shared.started'),
    );

    await refreshList();
}

function folderUrlFor(id) {
    return props.folderPaths.show.replace('__id__', String(id));
}

/**
 * Revenir à la bibliothèque, et s'y poser où on le demande.
 *
 * Elle et l'éditeur vivent dans la même application : quitter l'un pour
 * l'autre est un changement d'affichage, pas une navigation. Le panneau
 * déclenchait un rechargement complet quand une note était ouverte, ce qui
 * jetait le défilement, la sélection, et l'état déplié de l'arbre pour
 * revenir au même endroit.
 *
 * @returns {Promise<void>} résolue une fois la bibliothèque montée
 */
async function showLibrary(folderId = null) {
    selectedId.value = null;

    // La bibliothèque n'existe qu'une fois l'éditeur retiré : sans ce tour
    // de boucle, la référence est encore nulle et l'ordre se perd.
    await nextTick();

    if (libraryRef.value) {
        libraryRef.value.openFolder(folderId ?? null);

        return;
    }

    // Repli : si elle ne s'est pas montée, l'adresse reste la vérité.
    window.location.assign(
        null === folderId || undefined === folderId
            ? props.libraryPath
            : folderUrlFor(folderId),
    );
}

async function refreshFolders() {
    const { ok, payload } = await foldersApi.list();
    if (ok) folders.value = payload.folders ?? [];
}

function noteUrlFor(id) {
    return props.showPath.replace('__id__', String(id));
}

function noteExportUrlFor(id) {
    return props.exportOnePath.replace('__id__', String(id));
}

async function openNote(id) {
    try {
        window.history.pushState({ noteId: id }, '', noteUrlFor(id));
    } catch {
        // Cadre bac à sable : la note s'ouvre quand même, seule l'adresse
        // ne suit pas.
    }

    await selectNote(id);
}

function onHistoryPop() {
    // La bibliothèque a son propre écouteur pour le dossier ; celui-ci ne
    // tranche qu'entre « une note » et « la liste ».
    const match = /\/markdown\/(\d+)(?:$|[?#])/.exec(window.location.pathname);

    if (match) {
        void selectNote(Number(match[1]));

        return;
    }

    selectedId.value = null;
}

async function onLibraryChanged() {
    await Promise.all([refreshFolders(), refreshList()]);

    // Une note absente de la liste rafraîchie est partie à la corbeille,
    // seule ou avec son dossier. La garder ouverte laisserait
    // l'enregistrement automatique écrire dans une note supprimée, et le
    // lecteur croirait travailler sur quelque chose qui n'existe plus.
    if (selectedId.value && !notes.value.some((note) => note.id === selectedId.value)) {
        backToLibrary();
    }
}

function backToLibrary() {
    const folderId = openFolderId.value;
    const url = folderId ? folderUrlFor(folderId) : props.libraryPath;

    try {
        window.history.pushState({ folderId }, '', url);
    } catch {
        // Idem : le retour se fait, l'adresse ne suit pas.
    }

    selectedId.value = null;
}

/**
 * The panel's half of the contract.
 *
 * Every row action the tree used to perform itself is now an ask from the side
 * menu, answered here - selecting, creating a child, deleting, and the whole
 * drag. The handlers are the ones the aside called; the panel forwards their
 * arguments untouched, DOM event included, because both applications run in one
 * JavaScript context and it is the same event object either way.
 *
 * And the traffic goes both ways: `notes:changed` is how a note created in this
 * editor reaches the tree. Without it the panel showed the list it fetched on
 * arrival until the reader reloaded the page.
 */
/**
 * Emporter le carnet, et le rendre.
 *
 * L'export est une navigation, pas une requête : le navigateur sait recevoir
 * un fichier et le ranger, et passer par `fetch` obligerait à garder un zip
 * entier en mémoire pour le redonner à un lien fabriqué.
 */
function exportAll() {
    window.location.assign(props.exportPath);
}

function exportOne(id) {
    window.location.assign(props.exportOnePath.replace("__id__", String(id)));
}

const importInput = ref(null);

function askForFiles() {
    importInput.value?.click();
}

async function onImportFiles(event) {
    const files = [...(event.target.files ?? [])];
    // Remis à zéro tout de suite : sans ça, réimporter le même fichier
    // n'émettrait rien, le champ n'ayant pas changé de valeur.
    event.target.value = "";

    if (!files.length) return;

    const form = new FormData();
    files.forEach((file) => form.append("files[]", file));

    // Dans le dossier ouvert quand il y en a un : on importe là où on regarde.
    if (openFolderId.value) form.append("folderId", String(openFolderId.value));

    const { ok, payload } = await api.import(form);

    if (!ok) {
        if (payload?.errors) toast.error(Object.values(payload.errors)[0]);

        return;
    }

    await refreshList();
    toast.success(t("notes.markdown.import.done", { count: payload.created ?? 0 }));
}

const PANEL_INTENTS = {
    select: (id) => openNote(id),
    export: () => exportAll(),
    import: () => askForFiles(),
    create: (folderId) => createNote(folderId ?? null),
    delete: (note) => requestDelete(note),
    'open-folder': async (id) => {
        if (libraryRef.value) {
            libraryRef.value.openFolder(id);

            return;
        }

        await showLibrary(id);
    },
    // Une étiquette traverse le rangement : elle se regarde dans la
    // bibliothèque, jamais dans l'éditeur, donc on l'y ramène d'abord.
    'filter-tag': async (tag) => {
        if (!libraryRef.value) await showLibrary(null);

        libraryRef.value?.filterByTag(tag);
    },
    'create-folder': async (parentId) => {
        if (!libraryRef.value) await showLibrary(parentId ?? null);

        libraryRef.value?.askForFolderName(null, parentId ?? null);
    },
    'delete-folder': async (folder) => {
        if (!libraryRef.value) await showLibrary(folder?.parentId ?? null);

        libraryRef.value?.askToDelete(folder);
    },
    // Le glisser du panneau : la cible est une ligne de dossier, et ce qui
    // est déplacé voyage dans le presse-papier de l'événement, donc la
    // bibliothèque sait quoi en faire sans qu'on le lui répète.
    drop: (folder, event) => libraryRef.value?.dropInto(Number(folder.id), event),
};

const stopListening = [];

function announce() {
    tellPanels('notes:changed', {
        notes: notes.value,
        folders: folders.value,
        selectedId: selectedId.value,
        folderId: openFolderId.value,
        noteId: selectedId.value,
    });
}

onMounted(() => {
    for (const [intent, run] of Object.entries(PANEL_INTENTS)) {
        stopListening.push(
            onPanelRequest(`notes:${intent}`, ({ args = [] }) => run(...args)),
        );
    }

    // The panel fetches on arrival too, but it may well have done so before
    // this list existed; saying it once on mount settles which of the two wins.
    announce();
    stopListening.push(watch(notes, announce, { deep: true }));
    stopListening.push(watch(folders, announce, { deep: true }));
    stopListening.push(watch(openFolderId, announce));
    stopListening.push(watch(selectedId, announce));

    window.addEventListener('popstate', onHistoryPop);
    stopListening.push(() => window.removeEventListener('popstate', onHistoryPop));
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});

</script>

<template>
    <!-- La hauteur est ce qui reste, dite avec les valeurs qui la font : la
         barre du haut porte déjà la sienne dans `--aurora-topbar`, et les
         quatre rem sont les marges hautes et basses de la zone de contenu. Le
         `8rem` écrit ici avant était une estimation, fausse de trois douzaines
         de pixels : la carte dépassait le bas de l'écran, donc la fin d'une
         note longue se lisait en faisant défiler la page entière. `dvh` plutôt
         que `vh` pour que la barre d'un navigateur mobile compte. -->
    <!-- Le champ qui reçoit les fichiers importés : invisible, déclenché par
         le bouton du panneau. Un `input[type=file]` ne se dessine pas. -->
    <input
        ref="importInput"
        type="file"
        class="hidden"
        multiple
        accept=".md,.markdown,.zip,text/markdown,application/zip"
        v-on:change="onImportFiles"
    >

    <!-- Une colonne, et la carte prend ce qui reste.
         Le chemin de retour vit **au-dessus** de la note, pas dedans : une
         note peut porter son propre fond - papier, ardoise, nuit - et un
         lien de navigation posé sur ce fond se lit mal, voire pas du tout
         quand son survol prend la couleur d'encre du back-office. Il est
         sorti de la carte plutôt que recoloré, parce qu'il n'appartient pas
         à la note : il dit comment en sortir. `flex-1 min-h-0` sur la carte
         évite d'écrire sa hauteur en soustrayant celle du lien, un nombre
         qui serait faux au premier changement de taille de police. -->
    <div class="flex h-[calc(100dvh-var(--aurora-topbar)-4rem)] flex-col gap-1.5">
        <button
            v-if="selectedNote && !crashed"
            type="button"
            class="self-start text-xs text-muted transition-colors hover:text-primary"
            v-on:click="backToLibrary"
        >
            ← {{ t('notes.markdown.library.title') }}
        </button>

        <div class="relative flex min-h-0 flex-1 bg-surface rounded-xl border border-line overflow-hidden">
            <!-- No tree column and no drawer of its own: the notes are in the
             side menu's panel now, on every page of the module rather than
             this one, and the menu already has a drawer on small screens.
             Two drawers was two gestures to learn for the same thing. -->

            <!-- Editor pane -->
            <!-- `min-h-0` sur toute la colonne, et pas seulement `overflow-auto` en
             bas : un enfant de flex vaut `min-height: auto`, donc il refuse de
             descendre sous la hauteur de son contenu. Une note longue poussait
             la colonne au-delà de la carte au lieu de faire défiler le volet,
             et la fin du texte sortait de l'écran. C'est le pendant vertical de
             ce que le commentaire des deux volets dit déjà pour la largeur. -->
            <section class="flex-1 flex flex-col min-w-0 min-h-0">
                <div v-if="crashed" class="flex flex-1 items-center justify-center p-6">
                    <AppNoData
                        :message="t('notes.markdown.errors.crashed')"
                        :hint="String(crashed?.message ?? crashed)"
                        :icon="TriangleAlert"
                    />
                </div>

                <div v-else-if="selectedNote" class="flex-1 flex flex-col min-h-0" :class="lookClass">
                    <!-- Le bandeau, quand la note en porte un. L'image vit chez
                     celui qui l'héberge : rien n'est entré en médiathèque, et
                     si elle disparaît de là-bas on en choisit une autre.
                     
                     Plus haut dans la vue de lecture que dans l'éditeur, et
                     c'est voulu : ici il partage la colonne avec le texte
                     qu'on est en train d'écrire, là-bas la page défile et
                     n'a que la note à montrer. -->
                    <figure v-if="form.coverUrl" class="relative m-0 shrink-0">
                        <img
                            :src="form.coverUrl"
                            alt=""
                            class="h-40 w-full object-cover sm:h-56"
                            :style="{ objectPosition: `50% ${form.coverPosition ?? 50}%` }"
                        >
                        <figcaption
                            v-if="form.coverCreditName"
                            class="absolute bottom-0 right-0 bg-black/40 px-2 py-0.5 text-2xs text-white"
                        >
                            {{ t('notes.markdown.cover.credit', { name: form.coverCreditName }) }}
                        </figcaption>
                    </figure>

                    <!-- Le titre et les commandes sur une seule ligne : seul,
                         le titre laissait la moitié de l'en-tête vide et
                         poussait la note d'un rang vers le bas. Le repli est
                         celui du panneau du menu - le titre réclame quinze
                         rem, les commandes descendent d'elles-mêmes quand la
                         place manque vraiment. -->
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line p-2 sm:p-4">
                        <!-- Le titre s'écrit comme un titre, pas comme un
                             champ de formulaire.
                             
                             Une boîte avec sa bordure et son fond disait « ici
                             une donnée à saisir » au-dessus d'un document qui
                             est, lui, du texte libre : deux registres pour la
                             même page. Craft et Notion écrivent le titre dans
                             la page, en grand, et c'est ce qu'on lit d'abord.
                             
                             Ce n'est pas un `AppInput` sans bordure mais un
                             champ nu : la boîte de la maison porte son fond,
                             son filet et son anneau de focus, et les enlever
                             un par un en classes aurait laissé un composant
                             qui promet une apparence qu'il n'a plus. -->
                        <!-- L'état, écrit, pas seulement une icône qui change
                             de teinte. Une infobulle se survole et un message
                             disparaît : ni l'un ni l'autre ne dit, en arrivant
                             sur la note, si elle est sortie de chez soi. -->
                        <!-- Quand c'est le dossier qui décide, la pastille
                             est un lien vers lui : dire « ça vient de
                             Clients » sans donner le moyen d'y aller
                             laisserait le lecteur devant une porte fermée,
                             et refermer le dossier depuis une seule de ses
                             notes retirerait la visibilité à toutes les
                             autres sans qu'il les voie. -->
                        <component
                            :is="sharingFolder ? 'a' : 'span'"
                            v-if="visibleToTeam"
                            :href="sharingFolder ? folderUrlFor(sharingFolder.id) : undefined"
                            :title="sharingFolder ? t('notes.markdown.library.shared.change_on_folder') : undefined"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-accent-600/15 px-2 py-1 text-xs font-medium text-accent-400 no-underline"
                            :class="sharingFolder ? 'transition-colors hover:bg-accent-600/25' : ''"
                        >
                            <Users class="h-3 w-3" :stroke-width="2" />
                            {{ sharingFolder
                                ? t('notes.markdown.library.shared.via_folder', { folder: sharingFolder.name || t('notes.markdown.folders.untitled') })
                                : t('notes.markdown.library.shared.badge') }}
                        </component>

                        <input
                            v-model="form.title"
                            type="text"
                            class="min-w-0 flex-1 basis-60 border-0 bg-transparent p-0 text-2xl font-semibold text-primary placeholder:font-normal placeholder:text-muted focus:outline-none focus:ring-0"
                            :placeholder="t('notes.markdown.title_placeholder')"
                            :aria-label="t('notes.markdown.title_placeholder')"
                        >

                        <div class="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2 md:gap-3">
                            <!-- Ce qu'on touche en écrivant reste sous la
                                 main ; le reste passe dans le menu.
                                 
                                 Douze commandes sur la ligne du titre, et
                                 le titre n'avait plus de place : exporter,
                                 partager, changer l'image ou ouvrir le
                                 graphe se font une fois par note, quand
                                 les modes d'affichage, les étiquettes et
                                 les liens se touchent en permanence. La
                                 règle de la maison le dit déjà pour les
                                 cartes - au-delà de cinq, on garde la
                                 feuille. -->
                            <AppIconButton
                                v-if="readHref"
                                :href="readHref"
                                :title="t('notes.markdown.read.open')"
                                :aria-label="t('notes.markdown.read.open')"
                                size="md"
                            >
                                <BookOpen class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>

                            <AppIconButton
                                class="relative"
                                :title="tagsLabel"
                                :aria-label="tagsLabel"
                                size="md"
                                :variant="tagsOpen ? 'primary' : 'ghost'"
                                v-on:click="toggleTags"
                            >
                                <Tag class="w-4 h-4" :stroke-width="2" />
                                <span
                                    v-if="form.tags?.length"
                                    class="absolute -right-0.5 -top-0.5 min-w-3.5 rounded-full bg-accent-600 px-1 text-[0.625rem] font-semibold leading-3.5 text-white"
                                >
                                    {{ form.tags.length }}
                                </span>
                            </AppIconButton>

                            <AppIconButton
                                :title="sidePanelOpen ? t('notes.markdown.links.close') : t('notes.markdown.links.open')"
                                size="md"
                                :variant="sidePanelOpen ? 'primary' : 'ghost'"
                                v-on:click="sidePanelOpen = !sidePanelOpen"
                            >
                                <PanelRightClose v-if="sidePanelOpen" class="w-4 h-4" :stroke-width="2" />
                                <PanelRightOpen v-else class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>

                            <AppRowActions
                                :actions="noteActions"
                                :label="form.title || t('notes.markdown.untitled')"
                            />

                            <!-- View mode toggle (edit / split / preview) - segmented AppTab control -->
                            <div class="inline-flex rounded-md border border-line overflow-hidden">
                                <AppTab
                                    v-for="opt in viewModeOptions"
                                    :key="opt.value"
                                    size="sm"
                                    align="center"
                                    shape-class="rounded-none"
                                    :active="viewMode === opt.value"
                                    :title="opt.label"
                                    v-on:click="viewMode = opt.value"
                                >
                                    <component :is="opt.icon" class="w-4 h-4" :stroke-width="2" />
                                </AppTab>
                            </div>

                            <div class="flex items-center gap-3 shrink-0">
                                <span
                                    v-if="saveStatusDisplay"
                                    class="inline-flex items-center gap-1.5 text-xs"
                                    :class="saveStatusDisplay.classes"
                                >
                                    <component
                                        :is="saveStatusDisplay.icon"
                                        class="w-3.5 h-3.5"
                                        :class="saveStatusDisplay.spin ? 'animate-spin' : ''"
                                        :stroke-width="2"
                                    />
                                    {{ saveStatusDisplay.label }}
                                </span>
                                <span
                                    v-if="lastSavedAt"
                                    class="text-xs text-muted"
                                    :title="formatDateTimeNumeric(lastSavedAt.toISOString())"
                                >
                                    {{ t('shared.common.autosave.last_saved', { time: lastSavedRelative }) }}
                                </span>
                            </div>
                        </div>

                        <!-- Repliées en icône : voir la note s'écrire vaut mieux
                         qu'une ligne d'étiquettes qu'on ne touche presque
                         jamais. Le nombre est sur l'icône, les noms dans son
                         infobulle. -->
                        <div
                            v-if="tagsOpen"
                            ref="tagsBox"
                            v-on:keyup.esc="foldTags"
                            v-on:focusout="closeTagsIfIdle"
                        >
                            <AppTagsInput
                                v-model="form.tags"
                                :placeholder="t('notes.markdown.tags.add_placeholder')"
                            />
                        </div>

                        <!-- Editor form extension point. Scoped slot exposes
                         `form` (mutable reactive ref) so clients can wire
                         their custom v-model bindings against entity
                         fields they've added via aurora-client. -->
                        <slot name="extra-form-fields" :form="form" />
                    </header>

                    <!-- Two separate fixes, because the first one alone was aimed
                     at the wrong measurement.
                     
                     `max-w-[70%]` is the one that matters: the editor pane takes
                     a remembered pixel width with `shrink-0`, and 540px does not
                     fit next to a preview once the side menu and the note tree
                     have taken ~520px of a 1000px window. The preview was pushed
                     off-screen while the viewport was still far from any "mobile"
                     breakpoint - the constraint is how much room this pane has,
                     not how wide the window is. The cap is a share of the
                     available space, so it holds at every size.
                     
                     Stacking below `md` stays for phones, where two ~180px
                     columns would be unusable even when they fit. `min-w-0` on
                     both panes lets them actually shrink: a flex item defaults
                     to `min-width: auto` and refuses to go below its content. -->
                    <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">
                        <div
                            v-if="viewMode !== 'preview'"
                            ref="editorPaneRef"
                            class="p-2 overflow-auto min-w-0 sm:p-4"
                            :class="viewMode === 'split' && !isMobile ? 'shrink-0 max-w-[70%]' : 'flex-1'"
                            :style="viewMode === 'split' && !isMobile ? { width: `${editorWidth}px` } : {}"
                        >
                            <NoteEditor
                                v-model="form.content"
                                :placeholder="t('notes.markdown.content_placeholder')"
                                :flat-notes="notes"
                                :upload-image="api.uploadImage"
                                :image-max-edge="imageMaxEdge"
                                :image-quality="imageQuality"
                            />
                        </div>

                        <!-- Resize handle: split mode on a wide screen only. Stacked
                         panes have nothing to redistribute horizontally. -->
                        <div
                            v-if="viewMode === 'split' && !isMobile"
                            class="w-1 shrink-0 cursor-col-resize bg-line hover:bg-accent-500/40 transition-colors"
                            :class="splitDragging ? 'bg-accent-500/60' : ''"
                            :title="t('notes.markdown.resize_handle')"
                            v-on:pointerdown="startSplitResize"
                        />

                        <div
                            v-if="viewMode !== 'edit'"
                            class="flex-1 min-w-0 p-2 overflow-auto sm:p-4"
                        >
                            <!-- Le titre vit dans le champ au-dessus : l'aperçu
                                 ne le redit pas. Le texte, lui, n'est pas
                                 touché - c'est le rendu qui s'abstient, et le
                                 `# ` reste dans la zone d'écriture comme à
                                 l'export. -->
                            <NotePreview
                                :content="previewBody"
                                :note-titles="notes"
                                v-on:wiki-link-click="onWikiLinkClick"
                                v-on:checkbox-toggle="onCheckboxToggle"
                                v-on:image-resize="onImageResize"
                            />
                        </div>
                    </div>
                </div>

                <!-- Pas de note ouverte : la bibliothèque. C'était un écran vide
                 qui disait « choisissez une note » sans montrer lesquelles. -->
                <NoteLibrary
                    v-else
                    ref="libraryRef"
                    :folders="folders"
                    :notes="notes"
                    :folders-api="foldersApi"
                    :notes-api="api"
                    :initial-folder-id="folderId"
                    :breadcrumb="breadcrumb"
                    :root-url="libraryPath"
                    :note-url-for="noteUrlFor"
                    :note-export-url-for="noteExportUrlFor"
                    :max-depth="maxDepth"
                    v-on:open-note="openNote"
                    v-on:create-note="createNote"
                    v-on:changed="onLibraryChanged"
                    v-on:folder-changed="openFolderId = $event"
                />
            </section>

            <NoteGraph
                :show="graphOpen"
                :fetch-graph="api.graph"
                v-on:close="graphOpen = false"
                v-on:navigate="navigateFromGraph"
            />

            <NoteCoverModal
                :show="coverModalOpen"
                :search-path="coversSearchPath"
                :cover="cover"
                :appearance="form.appearance"
                v-on:close="coverModalOpen = false"
                v-on:choose="chooseCover"
                v-on:remove="removeCover"
                v-on:position="form.coverPosition = $event"
                v-on:appearance="form.appearance = $event"
            />

            <NoteShareModal
                :show="shareModalOpen"
                :note-id="selectedId"
                :paths="props"
                v-on:close="shareModalOpen = false"
            />

            <NoteTagManagerModal
                :show="tagManagerOpen"
                :api="tagsApi"
                v-on:close="tagManagerOpen = false"
                v-on:changed="onTagsChanged"
            />

            <NoteSidePanel
                v-if="sidePanelOpen && selectedNote"
                :note-id="selectedId"
                :fetch-backlinks="api.backlinks"
                :fetch-unlinked-mentions="api.unlinkedMentions"
                v-on:close="sidePanelOpen = false"
                v-on:navigate="selectNote"
            />

            <AppModal
                :show="!!pendingDelete"
                max-width="sm"
                :closeable="!deleting"
                :title="t('notes.markdown.delete')"
                :icon="Trash2"
                v-on:close="cancelDelete"
            >
                <p class="text-sm text-primary">
                    {{ t('notes.markdown.confirm_delete', { title: pendingDelete?.title || t('notes.markdown.untitled') }) }}
                </p>
                <p class="text-sm text-secondary mt-2">
                    {{ t('notes.markdown.delete_warning') }}
                </p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="ghost" size="md" :disabled="deleting" v-on:click="cancelDelete">
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
    </div>
</template>
