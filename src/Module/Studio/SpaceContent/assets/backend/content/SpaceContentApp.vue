<script setup>
/**
 * A space's content, in whichever view the reader prefers.
 *
 * **The switcher lists subjects, not drawings.** Contenus, Calendrier,
 * Fichiers, Discussion, Notes: five different questions about one space. Which
 * of them somebody reads is a preference, so it lives with the person and not
 * in the address - remembered, free, and the same in every space they open.
 * What is on screen - which space - stays in the URL.
 *
 * **The kanban and the list are one entry, with two shapes.** They show the
 * same cards in the same order and differ only in the drawing, which is the
 * distinction the files view already draws between rows and cards. See
 * {@see useSpaceContentShape}.
 *
 * **The files view is the exception, and it is deliberate.** It is not a
 * fourth way of reading the cards, it is a different subject: everything the
 * space has exchanged, newest first, which none of the other three can answer
 * because each shows only the files of the card it is drawing. It sits in the
 * same switcher because the question it answers - what is in this space - is
 * the same question, and because a reader looking for a file looks here first.
 * It costs no request either: it reads the payload the others already hold.
 *
 * **The conversation is the second exception**, and a bigger one: it is not a
 * reading of the cards at all. What is about one post belongs on that post's
 * thread, where somebody reopening it finds the objection next to what was
 * objected to; the conversation is for everything that is about the work and
 * not about one card, which used to land on whichever card happened to be
 * open. It sits in the switcher for the reason the files do - a reader looking
 * for it looks here - and it is the one view that costs a request, because a
 * chat that showed what was true when the page loaded is not a chat.
 *
 * This component owns the state and the writes; the views own nothing and
 * hand everything back as events. That is what lets a card edited in one of
 * them be right in the others.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { useSpaceCardActions } from "./composables/useSpaceCardActions.js";
import { useSpaceContent } from "./composables/useSpaceContent.js";
import { useSpaceContentShape } from "./composables/useSpaceContentShape.js";
import { useOrphanedDocumentOffer } from "./composables/useOrphanedDocumentOffer.js";
import SpaceBoardView from "./views/SpaceBoardView.vue";
import SpaceListView from "./views/SpaceListView.vue";
import SpaceCalendarView from "./views/SpaceCalendarView.vue";
import SpaceFilesView from "./views/SpaceFilesView.vue";
import SpaceDriveView from "../../../../SpaceFile/GoogleDrive/assets/backend/drive/SpaceDriveView.vue";
import SpaceContentItemFields from "./components/SpaceContentItemFields.vue";
// Same module, another sub-domain: a relative path rather than an alias,
// the way the public page already reaches the shared thread.
import SpaceChatPanel from "../../../../SpaceChat/assets/shared/SpaceChatPanel.vue";
import SpaceNotesView from "../../../../SpaceNote/assets/backend/notes/SpaceNotesView.vue";
import SpaceNoteFormModal from "../../../../SpaceNote/assets/backend/notes/SpaceNoteFormModal.vue";
import SpaceNoteCraftModal from "../../../../SpaceNote/assets/backend/notes/SpaceNoteCraftModal.vue";
import { useSpaceNotes } from "../../../../SpaceNote/assets/backend/notes/composables/useSpaceNotes.js";
import { useSpaceOwnFiles } from "../../../../SpaceFile/assets/backend/files/composables/useSpaceOwnFiles.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
// Même module, autre sous-domaine : un chemin relatif plutôt qu'un alias,
// comme le fait déjà la page publique. La règle qui interdit de traverser les
// modules parle des modules, et le Drive d'un espace est le même Studio.
import SpaceSettingsView from "../../../../CustomerSpace/assets/backend/settings/SpaceSettingsView.vue";
import SpaceDrivePicker from "../../../../SpaceFile/GoogleDrive/assets/backend/drive/SpaceDrivePicker.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppColourSlotPicker from "@/shared/components/form/picker/AppColourSlotPicker.vue";
import {
    CalendarDays,
    MessagesSquare,
    StickyNote,
    Settings,
    Paperclip,
    Columns3,
    FileStack,
    FileText,
    List,
    FolderOpen,
    Pencil,
    RefreshCw,
    Save,
    Trash2,
    X,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

const { t } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    space: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    comments: { type: Object, default: () => ({}) },
    attachments: { type: Object, default: () => ({}) },
    itemCreatePath: { type: String, required: true },
    itemUpdatePath: { type: String, required: true },
    itemDeletePath: { type: String, required: true },
    itemReorderPath: { type: String, required: true },
    schedulePath: { type: String, required: true },
    commentPostPath: { type: String, required: true },
    commentDeletePath: { type: String, required: true },
    attachmentUploadPath: { type: String, required: true },
    attachmentAttachPath: { type: String, required: true },
    attachmentDetachPath: { type: String, required: true },
    columnCreatePath: { type: String, required: true },
    columnUpdatePath: { type: String, required: true },
    columnDeletePath: { type: String, required: true },
    columnReorderPath: { type: String, required: true },
    chatMessages: { type: Array, default: () => [] },
    /** Null when no hub is running, and then the panel never connects. */
    chatStreamUrl: { type: String, default: null },
    chatPostPath: { type: String, required: true },
    chatReloadPath: { type: String, required: true },
    chatDeletePath: { type: String, required: true },
    chatChannels: { type: Array, default: () => [] },
    chatChannelId: { type: [Number, null], default: null },
    chatTeam: { type: Array, default: () => [] },
    chatChannelCreatePath: { type: String, default: null },
    chatChannelRenamePath: { type: String, default: null },
    chatChannelAudiencePath: { type: String, default: null },
    chatChannelDeletePath: { type: String, default: null },
    chatChannelInvitePath: { type: String, default: null },
    chatChannelUninvitePath: { type: String, default: null },
    chatDirectPath: { type: String, default: null },
    chatOlderPath: { type: String, default: null },
    chatHidePath: { type: String, default: null },
    chatPeople: { type: Array, default: () => [] },
    notes: { type: Array, default: () => [] },
    noteCreatePath: { type: String, required: true },
    noteUpdatePath: { type: String, required: true },
    noteDeletePath: { type: String, required: true },
    notePinPath: { type: String, required: true },
    noteImagePath: { type: String, required: true },
    craftEnabled: { type: Boolean, default: false },
    craftDocumentsPath: { type: String, default: "" },
    craftImportPath: { type: String, default: "" },
    craftRefreshPath: { type: String, default: "" },
    spaceFiles: { type: Array, default: () => [] },
    spaceFileUploadPath: { type: String, required: true },
    spaceFileAttachPath: { type: String, required: true },
    spaceFileRemovePath: { type: String, required: true },
    driveEnabled: { type: Boolean, default: false },
    driveFolderId: { type: String, default: null },
    driveListPath: { type: String, default: "" },
    driveFolderPath: { type: String, default: "" },
    driveFilePath: { type: String, default: "" },
    driveArchivePath: { type: String, default: "" },
    driveImportPath: { type: String, default: "" },
    /** Vrai pour le référent de l'espace, l'administrateur et le développeur. */
    canConfigure: { type: Boolean, default: false },
    settingsPath: { type: String, default: "" },
    driveUnlockPath: { type: String, default: "" },
    driveLocked: { type: Boolean, default: false },
});

const VIEWS = [
    { key: "content", labelKey: "backend.studio.space_content.view_content", icon: FileStack },
    { key: "calendar", labelKey: "backend.studio.space_content.view_calendar", icon: CalendarDays },
    { key: "files", labelKey: "backend.studio.space_content.view_files", icon: Paperclip },
    // Absente tant que l'installation n'a pas de compte de service : une
    // entrée qui mène à un écran vide est une entrée qu'on ouvre une fois.
    { key: "drive", labelKey: "backend.studio.space_content.view_drive", icon: FolderOpen },
    { key: "chat", labelKey: "backend.studio.space_content.view_chat", icon: MessagesSquare },
    { key: "notes", labelKey: "backend.studio.space_content.view_notes", icon: StickyNote },
    // En dernier, et seulement pour qui peut configurer : une entrée de barre
    // qui répondrait 404 à la moitié de l'équipe se lit comme une panne.
    { key: "settings", labelKey: "backend.studio.space_content.view_settings", icon: Settings },
];

/**
 * One key for every space, deliberately.
 *
 * Somebody who opens a space to read its conversation does that for one client
 * and for the next. A per-space key would make them choose again on every
 * space they open, which is the thing this exists to stop.
 */
/**
 * Ce que la barre montre vraiment.
 *
 * Le Drive n'y est que si l'installation a une clé de compte de service : une
 * entrée qui mène à un écran vide est une entrée qu'on ouvre une fois et qu'on
 * n'ouvre plus.
 */
const views = computed(() =>
    VIEWS.filter((entry) => {
        if ("drive" === entry.key) return props.driveEnabled;
        if ("settings" === entry.key) return props.canConfigure;

        return true;
    }),
);

const { choice: view } = usePersistedChoice(
    "studio.space_content.view",
    "content",
    VIEWS.map((entry) => entry.key),
);

const {
    shape,
    storedShape,
    setShape,
    container: shapeContainer,
    overruled: shapeOverruled,
} = useSpaceContentShape();

const {
    // Named apart from the props of the same name: these are the refs the
    // writes update, the props are only the first payload. The files view
    // reading the props would go stale the moment somebody uploads.
    items: liveItems,
    attachments: liveAttachments,
    isEmpty,
    grouped,
    unscheduled,
    events,
    cellsFor,
    columnOptions,
    columnsById,
    reorderItems,
    moveEvent,
    openEvent,
    addOn,
    showItemForm,
    editingItem,
    itemForm,
    itemErrors,
    itemLoading,
    openItemCreate,
    openItemEdit,
    submitItem,
    pendingItemDelete,
    itemDeleteLoading,
    confirmItemDelete,
    deleteItem,
    threadOf,
    commentLoading,
    postComment,
    deleteComment,
    showColumnForm,
    editingColumn,
    columnForm,
    columnErrors,
    columnLoading,
    openColumnCreate,
    openColumnEdit,
    submitColumn,
    pendingColumnDelete,
    columnDeleteLoading,
    confirmColumnDelete,
    deleteColumn,
    filesOf,
    attachmentLoading,
    upload,
    pick,
    pickFromDrive,
    remove,
} = useSpaceContent(
    {
        columns: props.columns,
        items: props.items,
        comments: props.comments,
        attachments: props.attachments,
    },
    {
        itemCreatePath: props.itemCreatePath,
        itemUpdatePath: props.itemUpdatePath,
        itemDeletePath: props.itemDeletePath,
        itemReorderPath: props.itemReorderPath,
        schedulePath: props.schedulePath,
        commentPostPath: props.commentPostPath,
        commentDeletePath: props.commentDeletePath,
        attachmentUploadPath: props.attachmentUploadPath,
        attachmentAttachPath: props.attachmentAttachPath,
        attachmentDetachPath: props.attachmentDetachPath,
        driveImportPath: props.driveImportPath,
        columnCreatePath: props.columnCreatePath,
        columnUpdatePath: props.columnUpdatePath,
        columnDeletePath: props.columnDeletePath,
        columnReorderPath: props.columnReorderPath,
        colourSlot: props.space.colourSlot,
    },
);

const showDrivePicker = ref(false);

/** Ce que les réglages viennent de décider, sans attendre un rechargement. */
const driveLockedNow = ref(props.driveLocked);

/**
 * Le fichier choisi entre dans la médiathèque, puis sur la fiche.
 *
 * La fenêtre ne se referme que si les deux ont abouti : refermée d'office,
 * elle aurait fait croire à un ajout qui n'a pas eu lieu, sur un écran où la
 * liste des pièces jointes est juste derrière.
 */
async function attachFromDrive(file) {
    if (await pickFromDrive(editingItem.value, file)) {
        showDrivePicker.value = false;
    }
}

const editable = computed(() => can("studio.spaces.edit"));

/**
 * Les notes de l'espace, la seule surface que le client ne voit pas.
 *
 * Elles réutilisent l'offre de nettoyage des fiches : supprimer une note ne
 * supprime pas ses images, et ce qui n'est plus utilisé nulle part est proposé
 * plutôt que jeté.
 */
const {
    notes: spaceNotes,
    tab: notesTab,
    tabs: notesTabs,
    viewMode: notesViewMode,
    storedViewMode: notesStoredViewMode,
    setViewMode: setNotesViewMode,
    container: notesContainer,
    showForm: showNoteForm,
    editing: editingNote,
    form: noteForm,
    errors: noteErrors,
    loading: noteLoading,
    openCreate: openNoteCreate,
    openEdit: openNoteEdit,
    submit: submitNote,
    togglePin: toggleNotePin,
    pendingDelete: pendingNoteDelete,
    confirmDelete: confirmNoteDelete,
    doDelete: deleteNote,
    apply: applyNotes,
} = useSpaceNotes(
    props.notes,
    {
        createPath: props.noteCreatePath,
        updatePath: props.noteUpdatePath,
        deletePath: props.noteDeletePath,
        pinPath: props.notePinPath,
    },
    // La même offre que les fichiers d'une fiche : un seul contrat, un seul
    // composable pour le lire.
    useOrphanedDocumentOffer().offer,
);

/**
 * L'import d'un document Craft.
 *
 * L'état tient en un booléen : la modale se charge elle-même à l'ouverture et
 * rend le mur entier à l'arrivée, comme toute écriture de cet écran.
 */
const { request } = useRequest();
const { offer: offerOrphanedDocuments } = useOrphanedDocumentOffer();

const craftOpen = ref(false);

/**
 * La note qu'on s'apprête à remettre sur sa version Craft.
 *
 * Confirmée avant, parce que ce qui a été modifié ici disparaît : une note
 * importée est une copie, et la rafraîchir refait la copie.
 */
const pendingCraftRefresh = ref(null);
const craftRefreshing = ref(false);

async function refreshFromCraft() {
    const note = pendingCraftRefresh.value;

    if (!note || craftRefreshing.value) return;

    craftRefreshing.value = true;

    try {
        const data = await request(props.craftRefreshPath.replace("__id__", note.id));

        if (data) {
            applyNotes(data);
            // La même offre que partout : ce que plus personne n'utilise est
            // proposé, jamais jeté tout seul.
            offerOrphanedDocuments(data);
            toast.success(t("backend.studio.craft.import.refreshed"));
            pendingCraftRefresh.value = null;
        }
    } finally {
        craftRefreshing.value = false;
    }
}

/**
 * Les fichiers de l'espace, ceux qui ne sont sur aucune fiche.
 *
 * La même offre de nettoyage que partout ailleurs : retirer un fichier ne le
 * supprime pas, et ce que plus rien n'utilise est proposé plutôt que jeté.
 */
const {
    files: ownFiles,
    loading: ownFilesLoading,
    upload: uploadOwnFile,
    pick: pickOwnFile,
    remove: removeOwnFile,
} = useSpaceOwnFiles(
    props.spaceFiles,
    {
        uploadPath: props.spaceFileUploadPath,
        attachPath: props.spaceFileAttachPath,
        removePath: props.spaceFileRemovePath,
    },
    useOrphanedDocumentOffer().offer,
);

// Its own rather than the shared edit/delete pair, because a reader who may
// not edit has to be offered something: see `useSpaceCardActions`.
const actionsFor = useSpaceCardActions({
    can,
    open: openItemEdit,
    confirmDelete: confirmItemDelete,
});
</script>

<template>
    <!-- Une colonne, parce que la discussion veut la hauteur qui reste et que
         `space-y` ne la transmet pas. Les autres écrans gardent leur taille :
         un flex item ne descend pas sous son contenu. -->
    <div class="flex flex-1 flex-col gap-2 sm:gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <!-- Segmented rather than a select: five choices are worth showing
                 at once, and the one in use is the answer to "why does this
                 look different from yesterday".

                 **Sur téléphone, seul l'onglet ouvert porte son nom.** Cinq
                 libellés font 520 pixels de large : la barre poussait la page
                 à défiler de côté, et c'est toute la page qui partait, pas
                 seulement les onglets. Les icônes restent, le nom de celui
                 qu'on regarde aussi - c'est le seul qui réponde à « où
                 suis-je », les autres répondent « où puis-je aller » et une
                 icône suffit pour ça. Le libellé est gardé pour les lecteurs
                 d'écran, où il n'a jamais coûté de place. -->
            <!-- Une bande qui défile plutôt qu'une bande qui pousse : même
                 réduits à leurs icônes, cinq onglets ne tiennent plus sous 260
                 pixels, et ce qui dépassait emportait la page entière avec lui.
                 `max-w-full` borne le groupe à la largeur disponible ; les
                 onglets, eux, gardent leur taille et défilent. -->
            <div
                class="flex max-w-full items-center gap-0.5 overflow-x-auto rounded-lg border border-line/60 bg-surface-2/40 p-0.5"
                role="group"
                :aria-label="t('backend.studio.space_content.view_label')"
            >
                <button
                    v-for="entry in views"
                    :key="entry.key"
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-md px-2 py-2 text-sm transition-colors sm:px-2.5 sm:py-1"
                    :class="
                        view === entry.key
                            ? 'bg-surface font-medium text-primary shadow-sm'
                            : 'text-muted hover:text-primary'
                    "
                    :aria-pressed="view === entry.key"
                    :title="t(entry.labelKey)"
                    v-on:click="view = entry.key"
                >
                    <component :is="entry.icon" class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                    <span :class="view === entry.key ? '' : 'sr-only sm:not-sr-only'">
                        {{ t(entry.labelKey) }}
                    </span>
                </button>
            </div>

            <div class="flex items-center gap-2">
                <!-- The shape of one entry, so it sits with the actions rather
                     than inside the switcher: two segmented groups side by side
                     would read as one control with seven choices.

                     Absent quand le conteneur est étroit : là, le kanban est
                     refusé de toute façon et l'interrupteur ne changeait rien
                     à l'écran. Un bouton qui ne fait rien se lit comme un
                     bouton cassé ; celui-ci revient avec la place. -->
                <div
                    v-if="view === 'content' && !shapeOverruled"
                    class="flex rounded-lg border border-line/60 p-0.5"
                >
                    <AppIconButton
                        size="sm"
                        variant="ghost"
                        :title="t('backend.studio.space_content.shape_board')"
                        :class="storedShape === 'board' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setShape('board')"
                    >
                        <Columns3 class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        size="sm"
                        variant="ghost"
                        :title="t('backend.studio.space_content.shape_list')"
                        :class="storedShape === 'list' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setShape('list')"
                    >
                        <List class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <!-- Une étape est une colonne du kanban : elle n'a rien à faire
                     au-dessus des fichiers, où elle voisinait avec leurs
                     propres actions sans rien avoir à voir avec elles. -->
                <AppButton
                    v-if="editable && 'content' === view"
                    variant="ghost"
                    size="sm"
                    v-on:click="openColumnCreate"
                >
                    <Columns3 class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.space_content.add_column") }}
                </AppButton>
            </div>
        </div>

        <!-- The container and not the window decides the shape: bound here,
             around both drawings, so a narrow panel gets the list. -->
        <div v-if="view === 'content'" ref="shapeContainer">
            <SpaceBoardView
                v-if="shape === 'board'"
                :grouped="grouped"
                :editable="editable"
                :actions-for="actionsFor"
                :files-of="filesOf"
                :is-empty="isEmpty"
                v-on:open-item="openItemEdit"
                v-on:reorder="reorderItems"
                v-on:add-item="openItemCreate({ columnId: $event })"
                v-on:edit-column="openColumnEdit"
                v-on:delete-column="confirmColumnDelete"
            />

            <SpaceListView
                v-else
                :grouped="grouped"
                :editable="editable"
                :actions-for="actionsFor"
                :files-of="filesOf"
                :is-empty="isEmpty"
                v-on:add-item="openItemCreate({ columnId: $event })"
                v-on:open-item="openItemEdit"
            />
        </div>

        <SpaceFilesView
            v-else-if="view === 'files'"
            :attachments="liveAttachments"
            :items="liveItems"
            :space-files="ownFiles"
            :editable="editable"
            :loading="ownFilesLoading"
            v-on:open-item="openItemEdit"
            v-on:upload="uploadOwnFile"
            v-on:pick="pickOwnFile"
            v-on:remove="removeOwnFile"
        />

        <!-- Sa propre vue, à côté de Fichiers. La barre sépare déjà par
             origine - ce qui est sur les fiches, ce qui est à l'espace - et un
             dossier chez le client en est une troisième. En section sous les
             fichiers, il fallait faire défiler tout le reste pour l'atteindre. -->
        <SpaceDriveView
            v-else-if="view === 'drive' && driveEnabled"
            :folder-id="driveFolderId"
            :list-path="driveListPath"
            :folder-path="driveFolderPath"
            :file-path="driveFilePath"
            :archive-path="driveArchivePath"
            :import-path="driveImportPath"
            :unlock-path="driveUnlockPath"
        />

        <!-- En dernier dans la barre, et seulement pour qui peut configurer.
             La serrure remonte d'ici vers la vue Drive : c'est le même écran
             qui la pose et celui qui la subit, et ils doivent s'accorder sans
             rechargement. -->
        <SpaceSettingsView
            v-else-if="view === 'settings' && canConfigure"
            :settings-path="settingsPath"
            v-on:locked-changed="driveLockedNow = $event"
        />

        <!-- Mounted only while it is the view on screen, so a board nobody is
             chatting on holds no connection open. The cost is that a message
             arriving while somebody is looking at the calendar is not
             announced - that is a notification's job, not a panel's. -->
        <!-- Toute la place qui reste, mesurée et non calculée : la colonne
             part du corps de la page, donc l'en-tête peut prendre une ligne ou
             deux sans que rien ne dépasse. Une boîte de 32rem au milieu d'un
             écran vide donnait trois messages visibles sur une conversation qui
             en compte trente, et une soustraction en dur laissait le bas de la
             page sous le bord de l'écran.

             `data-fills-viewport` est ce qui le demande : la coquille y répond
             en donnant à la fenêtre une hauteur ferme, sans quoi la colonne
             n'aurait rien à distribuer (voir le commentaire du gabarit).

             Le plancher reste : sur un écran très bas, mieux vaut une page qui
             défile qu'un fil de deux lignes. -->
        <div
            v-else-if="view === 'chat'"
            data-fills-viewport
            class="flex min-h-[20rem] flex-1 flex-col"
        >
            <SpaceChatPanel
                fill
                :messages="chatMessages"
                :stream-url="chatStreamUrl"
                :post-path="editable ? chatPostPath : null"
                :reload-path="chatReloadPath"
                :delete-path="editable ? chatDeletePath : null"
                :channels="chatChannels"
                :channel-id="chatChannelId"
                :team="chatTeam"
                :channel-create-path="editable ? chatChannelCreatePath : null"
                :channel-rename-path="editable ? chatChannelRenamePath : null"
                :channel-audience-path="editable ? chatChannelAudiencePath : null"
                :channel-delete-path="editable ? chatChannelDeletePath : null"
                :channel-invite-path="editable ? chatChannelInvitePath : null"
                :channel-uninvite-path="editable ? chatChannelUninvitePath : null"
                :chat-direct-path="editable ? chatDirectPath : null"
                :older-path="chatOlderPath"
                :hide-path="editable ? chatHidePath : null"
                :people="chatPeople"
            />
        </div>

        <!-- Le mur de notes, monté sur son propre conteneur : c'est lui qui
             décide de la forme, pas la fenêtre. -->
        <div v-else-if="view === 'notes'" ref="notesContainer">
            <SpaceNotesView
                :notes="spaceNotes"
                :tab="notesTab"
                :tabs="notesTabs"
                :view-mode="notesViewMode"
                :stored-view-mode="notesStoredViewMode"
                :editable="editable"
                :craft-enabled="craftEnabled"
                v-on:create="openNoteCreate"
                v-on:open="openNoteEdit"
                v-on:pin="toggleNotePin"
                v-on:delete="confirmNoteDelete"
                v-on:set-view="setNotesViewMode"
                v-on:set-tab="notesTab = $event"
                v-on:import-craft="craftOpen = true"
                v-on:refresh-craft="pendingCraftRefresh = $event"
            />
        </div>

        <SpaceCalendarView
            v-else
            :events="events"
            :unscheduled="unscheduled"
            :columns-by-id="columnsById"
            :cells-for="cellsFor"
            v-on:open-event="openEvent"
            v-on:move-event="moveEvent"
            v-on:add-on="addOn"
            v-on:open-item="openItemEdit"
        />

        <AppModal
            :show="showItemForm"
            max-width="2xl"
            :title="
                editingItem
                    ? t(editable ? 'backend.studio.space_content.edit_item' : 'backend.studio.space_content.view_item', {
                        title: editingItem.title,
                    })
                    : t('backend.studio.space_content.create_item')
            "
            :icon="FileText"
            :closeable="false"
            v-on:close="showItemForm = false"
        >
            <form v-on:submit.prevent="submitItem">
                <SpaceContentItemFields
                    v-model="itemForm"
                    :readonly="!editable"
                    :errors="itemErrors"
                    :column-options="columnOptions"
                    :timezone="space.timezone"
                    :approval="editingItem?.approval ?? 'pending'"
                    :approval-by="editingItem?.approvalBy ?? ''"
                    :approval-at="editingItem?.approvalAt ?? null"
                    :comments="threadOf(editingItem)"
                    :comment-loading="commentLoading"
                    :can-discuss="!!editingItem"
                    :attachments="filesOf(editingItem)"
                    :attachment-loading="attachmentLoading"
                    :can-pick-drive="driveEnabled && !!driveImportPath"
                    v-on:post-comment="postComment(editingItem, $event)"
                    v-on:delete-comment="deleteComment"
                    v-on:upload-attachment="upload(editingItem, $event)"
                    v-on:pick-attachment="pick(editingItem)"
                    v-on:pick-drive-attachment="showDrivePicker = true"
                    v-on:remove-attachment="remove"
                />
            </form>

            <!-- Posé dans le formulaire de la fiche : c'est là qu'on décide
                 d'accrocher un fichier, et la fenêtre se referme sur la fiche
                 plutôt que sur le tableau. -->
            <SpaceDrivePicker
                :show="showDrivePicker"
                :list-path="driveListPath"
                :importing="attachmentLoading"
                v-on:close="showDrivePicker = false"
                v-on:choose="attachFromDrive"
            />

            <template #footer>
                <AppModalFooter>
                    <!-- One button and no Save for a reader who may not edit.
                         Offering one the server would refuse is how a screen
                         teaches somebody to distrust it. -->
                    <AppButton variant="ghost" size="md" v-on:click="showItemForm = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t(editable ? "shared.common.cancel" : "shared.common.close") }}
                    </AppButton>
                    <AppButton
                        v-if="editable"
                        variant="primary"
                        size="md"
                        :loading="itemLoading"
                        v-on:click="submitItem"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showColumnForm"
            max-width="sm"
            :title="
                editingColumn
                    ? t('backend.studio.space_content.edit_column')
                    : t('backend.studio.space_content.create_column')
            "
            :icon="editingColumn ? Pencil : Columns3"
            :closeable="false"
            v-on:close="showColumnForm = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitColumn">
                <AppInput
                    :model-value="columnForm.name"
                    :label="t('backend.studio.space_content.column_name')"
                    :placeholder="t('backend.studio.space_content.column_name_placeholder')"
                    :error="columnErrors.name"
                    required
                    v-on:update:model-value="columnForm = { ...columnForm, name: $event }"
                />
                <AppColourSlotPicker
                    :model-value="columnForm.colourSlot"
                    clearable
                    :label="t('backend.studio.space_content.column_colour')"
                    :hint="t('backend.studio.space_content.column_colour_hint')"
                    :error="columnErrors.colourSlot"
                    v-on:update:model-value="columnForm = { ...columnForm, colourSlot: $event }"
                />

                <!-- Sur l'étape et non sur la fiche : un tableau dit déjà
                     « ce qui est à ce stade », donc « ce stade ne regarde pas
                     le client » se pose dessus. Marquer carte par carte
                     obligerait à y repenser à chaque création, et la première
                     oubliée annulerait la protection. -->
                <AppCheckbox
                    :model-value="false !== columnForm.visibleToClient"
                    :label="t('backend.studio.space_content.column_visible')"
                    :hint="t('backend.studio.space_content.column_visible_hint')"
                    v-on:update:model-value="columnForm = { ...columnForm, visibleToClient: $event }"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showColumnForm = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="columnLoading"
                        v-on:click="submitColumn"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingItemDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingItemDelete = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.space_content.delete_item_confirm", {
                        title: pendingItemDelete?.title ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.space_content.delete_item_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingItemDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="itemDeleteLoading"
                        v-on:click="deleteItem"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingColumnDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingColumnDelete = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.space_content.delete_column_confirm", {
                        name: pendingColumnDelete?.name ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.space_content.delete_column_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingColumnDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="columnDeleteLoading"
                        v-on:click="deleteColumn"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <SpaceNoteCraftModal
            v-if="craftEnabled"
            :show="craftOpen"
            :documents-path="craftDocumentsPath"
            :import-path="craftImportPath"
            v-on:close="craftOpen = false"
            v-on:imported="applyNotes"
        />

        <SpaceNoteFormModal
            :show="showNoteForm"
            :model-value="noteForm"
            :errors="noteErrors"
            :loading="noteLoading"
            :editing="!!editingNote"
            :upload-url="noteImagePath"
            v-on:update:model-value="noteForm = $event"
            v-on:close="showNoteForm = false"
            v-on:submit="submitNote"
        />

        <AppModal
            :show="!!pendingCraftRefresh"
            max-width="sm"
            :closeable="false"
            :title="t('backend.studio.craft.import.refresh')"
            :icon="RefreshCw"
            v-on:close="pendingCraftRefresh = null"
        >
            <p class="text-sm text-primary">
                {{ t("backend.studio.craft.import.refresh_confirm", { title: pendingCraftRefresh?.title ?? "" }) }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.craft.import.refresh_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingCraftRefresh = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="craftRefreshing" v-on:click="refreshFromCraft">
                        <RefreshCw class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.craft.import.refresh_submit") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingNoteDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingNoteDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t("backend.studio.space_notes.delete_confirm", { title: pendingNoteDelete?.title ?? "" }) }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.space_notes.delete_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingNoteDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="noteLoading" v-on:click="deleteNote">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
