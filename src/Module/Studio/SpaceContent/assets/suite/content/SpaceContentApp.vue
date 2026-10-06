<script setup>
/**
 * A space's content, in whichever view the reader prefers.
 *
 * **The switcher lists subjects, not drawings.** Contenus, Calendrier,
 * Fichiers, Drive, Discussion, Notes, Informations, Ressources, Réglages: nine
 * questions about one space, the Drive and the settings only where they apply.
 * Which of them somebody reads by default is a preference, remembered for the
 * person and the same in every space they open; the one on screen, and the
 * card open in it, are in the address, so a link opens what it names.
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
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { useQueryState } from "@/shared/composables/useQueryState.js";
import { viewFromAddress } from "./spaceAddress.js";
import { useSpaceCardActions } from "./composables/useSpaceCardActions.js";
import { useSpaceContent } from "./composables/useSpaceContent.js";
import { useSpaceContentShape } from "./composables/useSpaceContentShape.js";
import { useOrphanedDocumentOffer } from "./composables/useOrphanedDocumentOffer.js";
import { useSpaceReviewInvite } from "./composables/useSpaceReviewInvite.js";
import SpaceBoardView from "./views/SpaceBoardView.vue";
import SpaceListView from "./views/SpaceListView.vue";
import SpaceCalendarView from "./views/SpaceCalendarView.vue";
import SpaceFilesView from "./views/SpaceFilesView.vue";
import SpaceDriveTabs from "../../../../SpaceFile/GoogleDrive/assets/suite/drive/SpaceDriveTabs.vue";
import SpaceContentItemFields from "./components/SpaceContentItemFields.vue";
import SpaceSectionNav from "./components/SpaceSectionNav.vue";
// Same module, another sub-domain: a relative path rather than an alias,
// the way the public page already reaches the shared thread.
import SpaceChatPanel from "../../../../SpaceChat/assets/shared/SpaceChatPanel.vue";
import SpaceNoteSpaceView from "../../../../SpaceNote/assets/suite/notes/SpaceNoteSpaceView.vue";
import { useSpaceOwnFiles } from "../../../../SpaceFile/assets/suite/files/composables/useSpaceOwnFiles.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
// Même module, autre sous-domaine : un chemin relatif plutôt qu'un alias,
// comme le fait déjà la page publique. La règle qui interdit de traverser les
// modules parle des modules, et le Drive d'un espace est le même Studio.
import SpaceSettingsView from "../../../../CustomerSpace/assets/suite/settings/SpaceSettingsView.vue";
import SpaceInformationView from "../../../../Customer/assets/suite/information/SpaceInformationView.vue";
import SpaceResourcesView from "../../../../SpaceResource/assets/suite/resources/SpaceResourcesView.vue";
import SpaceDeliverablesView from "../../../../Deliverable/assets/suite/deliverables/SpaceDeliverablesView.vue";
import SpaceDrivePicker from "../../../../SpaceFile/GoogleDrive/assets/suite/drive/SpaceDrivePicker.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import { COLUMN_ROLES } from "../../shared/columnRoles.js";
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
    IdCard,
    Link2,
    NotebookText,
    Pencil,
    Save,
    Send,
    Trash2,
    X,
} from "lucide-vue-next";
import { toast } from "vue-sonner";

const { t } = useI18n();
const { can } = usePrivileges();

/** Aucun rôle d'abord : c'est la réponse d'une étape nommée librement. */
const columnRoleOptions = computed(() => [
    { value: "", label: t("suite.studio.space_content.column_role_none") },
    ...COLUMN_ROLES.map((role) => ({ value: role.value, label: t(role.labelKey) })),
]);

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
    /** Où demander au client d'aller relire ce qui attend son avis. */
    reviewPath: { type: String, required: true },
    /** Combien de cartes datées et visibles du client attendent sa réponse. */
    awaitingApproval: { type: Number, default: 0 },
    /** Combien d'entre elles ont dépassé leur échéance de relecture. */
    lateForReview: { type: Number, default: 0 },
    /** Les contrats, présentations et autres espaces du même client. */
    related: { type: Object, default: () => ({}) },
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
    /**
     * L'onglet Notes : l'espace de notes de cet espace client, dans le module
     * Notes. `enabled` faux pour qui n'a pas les notes, et l'onglet disparaît.
     */
    spaceNotes: { type: Object, default: () => ({ enabled: false }) },
    spaceFiles: { type: Array, default: () => [] },
    spaceFileUploadPath: { type: String, required: true },
    spaceFileAttachPath: { type: String, required: true },
    spaceFileRemovePath: { type: String, required: true },
    spaceFileVisibilityPath: { type: String, default: "" },
    driveEnabled: { type: Boolean, default: false },
    driveFolderId: { type: String, default: null },
    driveListPath: { type: String, default: "" },
    driveFilePath: { type: String, default: "" },
    driveArchivePath: { type: String, default: "" },
    driveImportPath: { type: String, default: "" },
    /** Le dossier de l'agence, le même pour tous les espaces. Studio seulement. */
    driveAgencyFolderId: { type: String, default: null },
    driveAgencyListPath: { type: String, default: "" },
    driveAgencyFilePath: { type: String, default: "" },
    driveAgencyArchivePath: { type: String, default: "" },
    driveAgencyImportPath: { type: String, default: "" },
    /** Vrai pour le référent de l'espace, l'administrateur et le développeur. */
    canConfigure: { type: Boolean, default: false },
    settingsPath: { type: String, default: "" },
    driveAgencyFolderPath: { type: String, default: null },
    driveServiceAccountEmail: { type: String, default: null },
    driveUnlockPath: { type: String, default: "" },
    driveLocked: { type: Boolean, default: false },
    /** La fiche du client, portée par la société et non par ce projet ; en lecture ici. */
    information: { type: Object, default: () => ({}) },
    /** La page du client, où la fiche se modifie ; null sans le droit de la modifier. */
    customerPath: { type: String, default: null },
    resources: { type: Array, default: () => [] },
    resourceCreatePath: { type: String, default: "" },
    resourceUpdatePath: { type: String, default: "" },
    resourceVisibilityPath: { type: String, default: "" },
    resourceDeletePath: { type: String, default: "" },
    resourceReorderPath: { type: String, default: "" },
    /** Les documents écrits pour ce client : audits, stratégies, bilans. */
    deliverables: { type: Array, default: () => [] },
    canEditDeliverables: { type: Boolean, default: false },
    /** Donner une adresse de lecture : le droit de partager l'espace, pas celui de le modifier. */
    canShareDeliverables: { type: Boolean, default: false },
    /** Faux dans une archive : elle ne reçoit plus de livrable, ni créé ni dupliqué. */
    canAddDeliverables: { type: Boolean, default: false },
    deliverableListPath: { type: String, default: "" },
    deliverableCreatePath: { type: String, default: "" },
    deliverableVisibilityPathTemplate: { type: String, default: "" },
    deliverableDuplicatePathTemplate: { type: String, default: "" },
    deliverableDeletePathTemplate: { type: String, default: "" },
    deliverableLinksPathTemplate: { type: String, default: "" },
    deliverableCopyToStudioPathTemplate: { type: String, default: "" },
});

const VIEWS = [
    { key: "content", labelKey: "suite.studio.space_content.view_content", icon: FileStack },
    { key: "calendar", labelKey: "suite.studio.space_content.view_calendar", icon: CalendarDays },
    { key: "files", labelKey: "suite.studio.space_content.view_files", icon: Paperclip },
    // Absente tant que l'installation n'a pas de compte de service : une
    // entrée qui mène à un écran vide est une entrée qu'on ouvre une fois.
    { key: "drive", labelKey: "suite.studio.space_content.view_drive", icon: FolderOpen },
    { key: "chat", labelKey: "suite.studio.space_content.view_chat", icon: MessagesSquare },
    { key: "notes", labelKey: "suite.studio.space_content.view_notes", icon: StickyNote },
    // Après les notes, qu'on garde pour soi : ce sont les documents qu'on livre
    // au client, audits, stratégies et bilans, composés comme une page.
    { key: "deliverables", labelKey: "suite.studio.space_content.view_deliverables", icon: NotebookText },
    // La fiche du client, puis ce qu'on épingle autour d'elle : deux sujets
    // qui ne sont pas des lectures du tableau, à gauche des réglages parce
    // qu'on les consulte et qu'on ne les règle pas.
    { key: "information", labelKey: "suite.studio.space_content.view_information", icon: IdCard },
    { key: "resources", labelKey: "suite.studio.space_content.view_resources", icon: Link2 },
    // En dernier, et seulement pour qui peut configurer : une entrée de barre
    // qui répondrait 404 à la moitié de l'équipe se lit comme une panne.
    { key: "settings", labelKey: "suite.studio.space_content.view_settings", icon: Settings },
];

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
        // Les notes vivent dans le module Notes : sans lui, ou sans le droit
        // de s'en servir, l'onglet mènerait à des écrans fermés.
        if ("notes" === entry.key) return true === props.spaceNotes?.enabled;

        return true;
    }),
);

/** Le bandeau « à relire », mis de côté jusqu'au prochain chargement. */
const reviewBannerHidden = ref(false);

/** Ce qui attend un geste, par section : les publications à faire relire. */
const navBadges = computed(() => ({ content: can("studio.spaces.share") ? props.awaitingApproval : 0 }));
const navUrgent = computed(() => (props.lateForReview > 0 ? ["content"] : []));

/**
 * One key for every space, deliberately.
 *
 * Somebody who opens a space to read its conversation does that for one client
 * and for the next. A per-space key would make them choose again on every
 * space they open, which is the thing this exists to stop.
 */
const { choice: view } = usePersistedChoice(
    "studio.space_content.view",
    "content",
    VIEWS.map((entry) => entry.key),
);

/**
 * La vue et la fiche ouvertes, dans l'adresse.
 *
 * **L'adresse d'abord, la préférence ensuite.** La vue mémorisée est un goût
 * de lecteur ; une notification, le tableau de bord ou le calendrier
 * éditorial désignent un endroit précis, et l'ouvrir sur la dernière vue
 * utilisée envoyait chercher la carte à la main. Un lien envoyé à un collègue
 * ouvre maintenant ce qu'il montre.
 */
const viewInUrl = useQueryState("view", { defaultValue: "content", valid: VIEWS.map((entry) => entry.key) });
const itemInUrl = useQueryState("item");

// Read from the address as it is: see `viewFromAddress`.
const viewAtLoad = viewFromAddress(window.location.search, VIEWS.map((entry) => entry.key));
if (viewAtLoad) view.value = viewAtLoad;

/**
 * Une vue mémorisée que cet espace n'offre pas - le Drive sans compte de
 * service, les réglages pour qui ne configure pas - retombait sur un écran
 * vide sans rien dire. Elle revient au contenu.
 */
watch(
    views,
    (available) => {
        if (!available.some((entry) => entry.key === view.value)) view.value = "content";
    },
    { immediate: true },
);

watch(view, (next) => viewInUrl.set(next), { immediate: true });

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
    stateFilter,
    reorderColumns,
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

/** Le dossier tel que les réglages viennent de le poser. */
const driveFolderNow = ref(props.driveFolderId ?? "");
// Same for the agency folder, which the settings view can now set in place.
const driveAgencyFolderNow = ref(props.driveAgencyFolderId ?? null);

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
// Montrer ou cacher au client, une étape, un canal ou un fichier : le droit de
// partager l'espace, en plus de celui de le modifier.
const canShowToClient = computed(() => editable.value && can("studio.spaces.share"));

const {
    confirming: confirmingReview,
    sending: sendingReview,
    send: sendReview,
} = useSpaceReviewInvite(props.reviewPath);

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
    toggleVisibility: toggleOwnFileVisibility,
} = useSpaceOwnFiles(
    props.spaceFiles,
    {
        uploadPath: props.spaceFileUploadPath,
        attachPath: props.spaceFileAttachPath,
        removePath: props.spaceFileRemovePath,
        visibilityPath: props.spaceFileVisibilityPath,
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

/** La fiche désignée par l'adresse, ouverte une fois la page montée. */
onMounted(() => {
    const id = Number(itemInUrl.value.value);
    const item = id ? liveItems.value.find((entry) => entry.id === id) : null;

    if (item) openItemEdit(item);
});

watch(editingItem, (item) => itemInUrl.set(item?.id ? String(item.id) : ""));
watch(showItemForm, (open) => {
    if (!open) itemInUrl.set("");
});

/** Les états qu'une fiche peut porter, dans l'ordre d'urgence du tableau de bord. */
const stateOptions = computed(() =>
    ["missed", "late_review", "changes_requested", "with_client", "upcoming", "published"].map((value) => ({
        value,
        label: t(`suite.studio.calendar.states.${value}`),
    })),
);

const stateInUrl = useQueryState("state", {
    valid: ["missed", "late_review", "changes_requested", "with_client", "upcoming", "published"],
});

stateFilter.value = stateInUrl.value.value;
watch(stateFilter, (next) => stateInUrl.set(next ?? ""));
</script>

<template>
    <!-- Une colonne, parce que la discussion veut la hauteur qui reste et que
         `space-y` ne la transmet pas. Les autres écrans gardent leur taille :
         un flex item ne descend pas sous son contenu.

         Sur ordinateur, le rail des sections à gauche et l'écran à droite ;
         la colonne de droite garde la hauteur pour la discussion. -->
    <div class="flex flex-1 flex-col aurora-gap lg:flex-row lg:items-start">
        <SpaceSectionNav v-model="view" :views="views" :badges="navBadges" :urgent="navUrgent" />

        <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-2 self-stretch sm:gap-4">
            <!-- Les outils de la vue ouverte : filtre, forme et étapes, pour le
             tableau et le calendrier seulement. -->
            <div v-if="'content' === view || 'calendar' === view" class="flex flex-wrap items-center gap-3 sm:justify-end">
                <div class="flex items-center gap-2">
                    <!-- Les mêmes états que le tableau de bord et le calendrier
                     éditorial, dans l'adresse : un lien « en retard » ouvre
                     l'espace déjà filtré. -->
                    <AppSelect
                        v-if="'content' === view || 'calendar' === view"
                        v-model="stateFilter"
                        class="w-44"
                        :options="stateOptions"
                        :placeholder="t('suite.studio.calendar.all_states')"
                    />

                    <!-- The shape of one entry, so it sits with the actions rather
                     than inside the switcher: two segmented groups side by side
                     would read as one control with seven choices.

                     Absent quand le conteneur est étroit : là, le kanban est
                     refusé de toute façon et l'interrupteur ne changeait rien
                     à l'écran. Un bouton qui ne fait rien se lit comme un
                     bouton cassé ; celui-ci revient avec la place. -->
                    <div
                        v-if="view === 'content' && !shapeOverruled"
                        class="flex rounded-lg border border-line p-0.5"
                    >
                        <AppIconButton
                            :title="t('suite.studio.space_content.shape_board')"
                            :class="storedShape === 'board' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                            v-on:click="setShape('board')"
                        >
                            <Columns3 class="h-4 w-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            :title="t('suite.studio.space_content.shape_list')"
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
                        {{ t("suite.studio.space_content.add_column") }}
                    </AppButton>
                </div>
            </div>

            <!-- **Un bandeau, et non un bouton dans la barre.** Rangé parmi les
             filtres, « Envoyer à relire » ne disait ni à qui ni pourquoi, et
             il se lisait comme un réglage de l'onglet ouvert. Ici il dit ce
             qui attend, ce que reçoit le client, et il reste le même sur tous
             les onglets : le lot à relire ne dépend pas de la vue.

             Absent quand rien n'attend, parce qu'une invitation à relire zéro
             publication est ce qui apprend à ignorer les suivantes. -->
            <!-- Fermable, pour la visite seulement : sur un écran étroit il prend
             la place du travail, et le compteur du rail continue de dire ce
             qui attend. Il revient au rechargement, parce qu'un lot à relire
             oublié pour de bon est la raison d'être du bandeau. -->
            <AppMessage
                v-if="awaitingApproval > 0 && can('studio.spaces.share') && !reviewBannerHidden"
                variant="info"
                dismissible
                :dismiss-label="t('suite.studio.space_content.review.banner_hide')"
                v-on:dismiss="reviewBannerHidden = true"
            >
                <!-- Le bouton dans le texte plutôt que dans l'emplacement d'action :
                 sur téléphone il passe dessous, en pleine largeur, au lieu de
                 réduire le texte à une colonne de trois mots. -->
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="m-0 flex flex-wrap items-center gap-2 font-medium">
                            {{ t("suite.studio.space_content.review.banner_title", { count: awaitingApproval }) }}
                            <!-- Le retard à côté du nombre, parce que « trois en
                             attente » et « trois en attente dont deux en
                             retard » ne décrivent pas la même journée. -->
                            <span
                                v-if="lateForReview > 0"
                                class="rounded-full bg-warning-soft px-1.5 py-0.5 text-2xs font-medium text-warning"
                                :title="t('suite.studio.space_content.review.late_hint')"
                            >
                                {{ t("suite.studio.space_content.review.late", { count: lateForReview }) }}
                            </span>
                        </p>
                        <p class="m-0 mt-0.5">{{ t("suite.studio.space_content.review.banner_body") }}</p>
                    </div>
                    <AppButton class="w-full shrink-0 justify-center whitespace-nowrap sm:w-auto" variant="primary" size="sm" v-on:click="confirmingReview = true">
                        <Send class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("suite.studio.space_content.review.banner_action") }}
                    </AppButton>
                </div>
            </AppMessage>

            <!-- The container and not the window decides the shape: bound here,
             around both drawings, so a narrow panel gets the list. -->
            <div v-if="view === 'content'" ref="shapeContainer">
                <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
                 replié ou déplié, le choix vaut pour tous les encarts. -->
                <AppGuide :title="t('suite.studio.space_content.guide.title')" storage-key="space-content" class="mb-[var(--aurora-page-margin)]">
                    <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                        <li v-for="step in 6" :key="step">{{ t(`suite.studio.space_content.guide.step_${step}`) }}</li>
                    </ol>
                </AppGuide>
                <!-- Filtrées, les colonnes n'ont plus toutes leurs cartes : un
                 glisser-déposer y réécrirait l'ordre d'une partie seulement.
                 Le tri se refait sans filtre. -->
                <SpaceBoardView
                    v-if="shape === 'board'"
                    :grouped="grouped"
                    :editable="editable && !stateFilter"
                    :actions-for="actionsFor"
                    :files-of="filesOf"
                    :is-empty="isEmpty"
                    v-on:open-item="openItemEdit"
                    v-on:reorder="reorderItems"
                    v-on:add-item="openItemCreate({ columnId: $event })"
                    v-on:edit-column="openColumnEdit"
                    v-on:delete-column="confirmColumnDelete"
                    v-on:reorder-columns="reorderColumns"
                />

                <SpaceListView
                    v-else
                    :grouped="grouped"
                    :editable="editable && !stateFilter"
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
                :can-show-to-client="canShowToClient"
                :can-pick="can('ged.documents.view')"
                :loading="ownFilesLoading"
                v-on:open-item="openItemEdit"
                v-on:upload="uploadOwnFile"
                v-on:pick="pickOwnFile"
                v-on:remove="removeOwnFile"
                v-on:toggle-visibility="toggleOwnFileVisibility"
            />

            <!-- Sa propre vue, à côté de Fichiers. La barre sépare déjà par
             origine - ce qui est sur les fiches, ce qui est à l'espace - et un
             dossier chez le client en est une troisième. En section sous les
             fichiers, il fallait faire défiler tout le reste pour l'atteindre. -->
            <SpaceDriveTabs
                v-else-if="view === 'drive' && driveEnabled"
                :folder-id="driveFolderNow"
                :list-path="driveListPath"
                :file-path="driveFilePath"
                :archive-path="driveArchivePath"
                :import-path="driveImportPath"
                :unlock-path="driveUnlockPath"
                :can-configure="canConfigure"
                :agency-folder-id="driveAgencyFolderNow"
                :agency-list-path="driveAgencyListPath"
                :agency-file-path="driveAgencyFilePath"
                :agency-archive-path="driveAgencyArchivePath"
                :agency-import-path="driveAgencyImportPath"
            />

            <SpaceInformationView
                v-else-if="view === 'information'"
                :information="information"
                :related="related"
                :customer-path="customerPath"
            />

            <SpaceDeliverablesView
                v-else-if="view === 'deliverables'"
                :deliverables="deliverables"
                :can-edit="canEditDeliverables"
                :can-share="canShareDeliverables"
                :can-add="canAddDeliverables"
                :list-path="deliverableListPath"
                :create-path="deliverableCreatePath"
                :visibility-path-template="deliverableVisibilityPathTemplate"
                :duplicate-path-template="deliverableDuplicatePathTemplate"
                :delete-path-template="deliverableDeletePathTemplate"
                :links-path-template="deliverableLinksPathTemplate"
                :copy-to-studio-path-template="deliverableCopyToStudioPathTemplate"
            />

            <SpaceResourcesView
                v-else-if="view === 'resources'"
                :resources="resources"
                :resource-create-path="resourceCreatePath"
                :resource-update-path="resourceUpdatePath"
                :resource-visibility-path="resourceVisibilityPath"
                :resource-delete-path="resourceDeletePath"
                :resource-reorder-path="resourceReorderPath"
            />

            <!-- En dernier dans la barre, et seulement pour qui peut configurer.
             La serrure remonte d'ici vers la vue Drive : c'est le même écran
             qui la pose et celui qui la subit, et ils doivent s'accorder sans
             rechargement. -->
            <SpaceSettingsView
                v-else-if="view === 'settings' && canConfigure"
                :settings-path="settingsPath"
                :agency-folder-id="driveAgencyFolderNow"
                :agency-folder-path="driveAgencyFolderPath"
                :service-account-email="driveServiceAccountEmail"
                v-on:agency-folder-changed="driveAgencyFolderNow = $event"
                v-on:locked-changed="driveLockedNow = $event"
                v-on:folder-changed="driveFolderNow = $event"
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
                <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
     replié ou déplié, le choix vaut pour tous les encarts. -->
                <AppGuide :title="t('suite.studio.space_chat.guide.title')" storage-key="space-chat" class="mb-[var(--aurora-page-margin)] shrink-0">
                    <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                        <li v-for="step in 4" :key="step">{{ t(`suite.studio.space_chat.guide.step_${step}`) }}</li>
                    </ol>
                </AppGuide>
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
                    :channel-audience-path="canShowToClient ? chatChannelAudiencePath : null"
                    :channel-delete-path="editable ? chatChannelDeletePath : null"
                    :channel-invite-path="editable ? chatChannelInvitePath : null"
                    :channel-uninvite-path="editable ? chatChannelUninvitePath : null"
                    :chat-direct-path="editable ? chatDirectPath : null"
                    :older-path="chatOlderPath"
                    :hide-path="editable ? chatHidePath : null"
                    :people="chatPeople"
                />
            </div>

            <!-- La porte de l'espace de notes : les notes s'écrivent dans
                 le module Notes. -->
            <SpaceNoteSpaceView v-else-if="view === 'notes'" :state="spaceNotes" />

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

            <!-- Une confirmation parce que l'envoi ne fait pas que poster un
             courriel : il remplace l'adresse de chaque destinataire et révoque
             la précédente. Le texte le dit, sans quoi le studio découvrirait la
             conséquence par un client qui n'arrive plus à ouvrir son favori. -->
            <AppModal
                :show="confirmingReview"
                max-width="sm"
                :title="t('suite.studio.space_content.review.confirm_title')"
                :icon="Send"
                v-on:close="confirmingReview = false"
            >
                <p class="text-sm text-secondary">
                    {{ t("suite.studio.space_content.review.confirm_body", { count: awaitingApproval }) }}
                </p>

                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="ghost" size="md" v-on:click="confirmingReview = false">
                            <X class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("shared.common.cancel") }}
                        </AppButton>
                        <AppButton variant="primary" size="md" :loading="sendingReview" v-on:click="sendReview">
                            <Send class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("suite.studio.space_content.review.confirm_send") }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>

            <AppModal
                :show="showItemForm"
                max-width="2xl"
                :title="
                    editingItem
                        ? t(editable ? 'suite.studio.space_content.edit_item' : 'suite.studio.space_content.view_item', {
                            title: editingItem.title,
                        })
                        : t('suite.studio.space_content.create_item')
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
                        :can-pick-documents="can('ged.documents.view')"
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
                        ? t('suite.studio.space_content.edit_column')
                        : t('suite.studio.space_content.create_column')
                "
                :icon="editingColumn ? Pencil : Columns3"
                :closeable="false"
                v-on:close="showColumnForm = false"
            >
                <form class="space-y-4" v-on:submit.prevent="submitColumn">
                    <AppInput
                        :model-value="columnForm.name"
                        :label="t('suite.studio.space_content.column_name')"
                        :placeholder="t('suite.studio.space_content.column_name_placeholder')"
                        :error="columnErrors.name"
                        required
                        v-on:update:model-value="columnForm = { ...columnForm, name: $event }"
                    />
                    <AppColourSlotPicker
                        :model-value="columnForm.colourSlot"
                        clearable
                        :label="t('suite.studio.space_content.column_colour')"
                        :hint="t('suite.studio.space_content.column_colour_hint')"
                        :error="columnErrors.colourSlot"
                        v-on:update:model-value="columnForm = { ...columnForm, colourSlot: $event }"
                    />
                    <!-- Facultatif : l'étape garde son nom, le rôle dit seulement
                     laquelle des étapes communes elle représente, pour que
                     les compteurs de tous les espaces se calculent. -->
                    <AppSelect
                        :model-value="columnForm.role ?? ''"
                        :options="columnRoleOptions"
                        :label="t('suite.studio.space_content.column_role')"
                        :hint="t('suite.studio.space_content.column_role_hint')"
                        v-on:update:model-value="columnForm = { ...columnForm, role: $event }"
                    />

                    <!-- Sur l'étape et non sur la fiche : un tableau dit déjà
                     « ce qui est à ce stade », donc « ce stade ne regarde pas
                     le client » se pose dessus. Marquer carte par carte
                     obligerait à y repenser à chaque création, et la première
                     oubliée annulerait la protection. -->
                    <AppCheckbox
                        v-if="canShowToClient"
                        :model-value="true === columnForm.visibleToClient"
                        :label="t('suite.studio.space_content.column_visible')"
                        :hint="t('suite.studio.space_content.column_visible_hint')"
                        v-on:update:model-value="columnForm = { ...columnForm, visibleToClient: $event }"
                    />
                    <!-- Sans le droit de partager, l'état se lit et ne se
                         change pas : montrer une étape au client est le même
                         geste que lui donner un lien d'accès. -->
                    <p v-else class="m-0 text-xs text-muted">
                        {{ t(true === columnForm.visibleToClient ? "suite.studio.space_content.column_state_visible" : "suite.studio.space_content.column_state_hidden") }}
                        {{ t("suite.studio.client_visibility.share_needed") }}
                    </p>
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
                :title="t('suite.studio.space_content.trash_action')"
                :icon="Trash2"
                v-on:close="pendingItemDelete = null"
            >
                <p class="text-sm text-primary">
                    {{
                        t("suite.studio.space_content.delete_item_confirm", {
                            title: pendingItemDelete?.title ?? "",
                        })
                    }}
                </p>
                <p class="text-sm text-secondary">
                    {{ t("suite.studio.space_content.delete_item_warning") }}
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
                            {{ t("suite.studio.space_content.trash_action") }}
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
                        t("suite.studio.space_content.delete_column_confirm", {
                            name: pendingColumnDelete?.name ?? "",
                        })
                    }}
                </p>
                <p class="text-sm text-secondary">
                    {{ t("suite.studio.space_content.delete_column_warning") }}
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
        </div>
    </div>
</template>
