<script setup>
/**
 * A client's own content plan, read from a secret address.
 *
 * **The same month grid the studio sees**, not a second rendering of it: the
 * component is shared, so what a client is shown is what their agency is
 * looking at, and the two cannot drift into disagreeing about which Tuesday a
 * post lands on.
 *
 * **It holds exactly the addresses this link may post to, and nulls for the
 * rest.** It was read-only and carried none at all; four writes have been
 * opened since - a verdict, a message on a card, a file, and now the space's
 * conversation - and the rule that replaced "no addresses" is the one that
 * still keeps a template mistake from calling something: a right the link does
 * not have arrives as `null`, so the box is not drawn and there is nothing to
 * call. Which of the four a link has is decided when it is created, and every
 * one of them is checked again by the server.
 *
 * What is deliberately not shown: the steps as columns. A client does not need
 * to see that a post moved from "en rédaction" to "à valider", they need to see
 * what is coming and when. The step travels as a word on the card, which is the
 * part that answers "where is this".
 */
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarDays, IdCard, Link2, MessagesSquare, Paperclip } from "lucide-vue-next";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import CalendarMonth from "@/shared/components/calendar/CalendarMonth.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppThemeToggle from "@/shared/components/action/AppThemeToggle.vue";
import { monthGrid } from "@/shared/composables/calendar/monthGrid.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import AppButton from "@/shared/components/action/AppButton.vue";
// Same module, another sub-domain: a relative path rather than an alias.
// The rule that forbids reaching across modules is about modules, and this
// component is the one thing the two surfaces genuinely share.
import SpaceContentThread from "../../../../SpaceContent/assets/shared/SpaceContentThread.vue";
import SpaceContentAttachments from "../../../../SpaceContent/assets/shared/SpaceContentAttachments.vue";
import SpaceChatPanel from "../../../../SpaceChat/assets/shared/SpaceChatPanel.vue";
import CustomerInformationCard from "../../../../Customer/assets/shared/CustomerInformationCard.vue";
import SpaceResourceItem from "../../../../SpaceResource/assets/shared/SpaceResourceItem.vue";
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    FileText,
    Package,
    MessageSquare,
} from "lucide-vue-next";

const props = defineProps({
    space: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    expiresAt: { type: String, default: null },
    canApprove: { type: Boolean, default: false },
    canComment: { type: Boolean, default: false },
    comments: { type: Object, default: () => ({}) },
    /** Null when this link may only read, so there is nothing to post to. */
    answerPath: { type: String, default: null },
    /** L'accord en lot. Null pour les mêmes raisons que `answerPath`. */
    approveManyPath: { type: String, default: null },
    commentPath: { type: String, default: null },
    canUpload: { type: Boolean, default: false },
    /** Vrai quand le studio se regarde lui-même, et non le client. */
    preview: { type: Boolean, default: false },
    attachments: { type: Object, default: () => ({}) },
    uploadPath: { type: String, default: null },
    /** Les fichiers de l'espace, ceux qui ne sont sur aucune fiche. */
    spaceFiles: { type: Array, default: () => [] },
    /** Le dossier Drive du prestataire, quand il en a branché un. */
    drivePath: { type: String, default: null },
    driveFilePath: { type: String, default: null },
    driveArchivePath: { type: String, default: null },
    chatMessages: { type: Array, default: () => [] },
    /** Null when no hub is running, and then the panel never connects. */
    chatStreamUrl: { type: String, default: null },
    /** Null when this link may only read, so there is no box to type in. */
    chatPostPath: { type: String, default: null },
    chatReloadPath: { type: String, required: true },
    chatChannels: { type: Array, default: () => [] },
    chatDirectPath: { type: String, default: null },
    chatOlderPath: { type: String, default: null },
    chatHidePath: { type: String, default: null },
    chatPeople: { type: Array, default: () => [] },
    chatChannelId: { type: [Number, null], default: null },
    /**
     * La fiche du prestataire sur ce client, ou `null`.
     *
     * `null` quand elle ne dit rien de plus que le nom, que le client connaît
     * déjà : l'onglet n'existe alors pas, comme la discussion sans canal.
     */
    information: { type: Object, default: null },
    /** Ce que le studio a ouvert au client, et rien d'autre. */
    resources: { type: Array, default: () => [] },
});

const { t, d } = useI18n();
const { request } = useRequest();
const { formatSize } = useFileSize();

// The rows are replaced by what the server sends back after an answer, so the
// page never has to work out what its own write did.
const items = ref(props.items ?? []);
const comments = ref(props.comments ?? {});
const attachments = ref(props.attachments ?? {});

const today = new Date();
const year = ref(today.getFullYear());
const month = ref(today.getMonth());

const cells = computed(() => monthGrid(year.value, month.value));

const columnNames = computed(
    () => new Map(props.columns.map((column) => [column.id, column.name])),
);

const columnColours = computed(
    () => new Map(props.columns.map((column) => [column.id, column.colourSlot])),
);

// Ce que le client voit dans son mois, et la même règle que le studio : une
// carte décochée porte une échéance interne, pas une parution.
const events = computed(() =>
    items.value
        .filter((item) => item.scheduledAt && false !== item.showOnCalendar)
        .map((item) => ({
            id: item.id,
            title: item.title,
            startAt: item.scheduledAt,
            endAt: item.scheduledAt,
            allDay: false,
            // The step's colour, as the agency sees it. A client watching
            // their own month has one client on it, so the space's colour says
            // nothing; what they are looking for is what is waiting on them.
            colourSlot:
                columnColours.value.get(item.columnId) ?? props.space.colourSlot,
            // The grid already honours this: a read-only event cannot be
            // dragged and draws no handles. Saying it here rather than trusting
            // the absence of a listener is what makes the page read-only by
            // construction instead of by omission.
            readOnly: true,
            // L'état de la réponse voyage avec l'événement. La grille l'ignore,
            // le compteur, le filtre et la liste du jour s'en servent.
            approval: item.approval ?? "pending",
        })),
);

/**
 * Ce qui attend encore une réponse de ce client.
 *
 * `pending` veut dire que personne n'a rien dit, ce qui n'est pas un refus :
 * c'est exactement la population qu'un client vient chercher en revenant.
 */
const pendingEvents = computed(() =>
    events.value.filter((event) => "pending" === event.approval),
);

/**
 * Le mois réduit à ce qui attend, quand le client le demande.
 *
 * Un filtre plutôt qu'une pastille sur la grille : le mois est un composant
 * partagé par toute l'application, et l'événement y porte déjà une couleur,
 * celle de son étape. Un second code couleur sur la même pastille ne se lit
 * pas. Retirer ce qui ne l'attend pas dit la même chose sans rien repeindre.
 */
const reviewOnly = ref(false);

const visibleEvents = computed(() =>
    reviewOnly.value ? pendingEvents.value : events.value,
);

/** Le bouton reste tant qu'il est enclenché, sinon il disparaîtrait sous le doigt. */
const showsReviewFilter = computed(
    () => props.canApprove && (pendingEvents.value.length > 0 || reviewOnly.value),
);

/**
 * Une grille de mois ne tient pas sur un téléphone, et c'est mesurable.
 *
 * Sept colonnes dans trois cent soixante-quinze pixels font des cases de
 * cinquante : les trois autres calendriers d'Aurora passent donc en index à
 * pastilles sous le seuil, avec la liste du jour en dessous. Celui-ci était le
 * seul à ne pas le faire, et il montrait au client des pastilles d'événement de
 * seize pixels de haut - la hauteur d'une ligne de texte, pas celle d'une
 * cible.
 */
const { container, isNarrow } = useNarrowContainer(560);

/**
 * Les fichiers du dossier Drive, s'il y en a un.
 *
 * **Chargés après la page, jamais avec.** Lire un dossier chez Google prend
 * deux dixièmes de seconde et peut échouer ; faire attendre la page pour ça
 * retarderait ce que le client vient vraiment voir. La section apparaît quand
 * la réponse arrive, et reste absente si elle ne vient pas.
 */
const driveFiles = ref([]);

onMounted(async () => {
    if (!props.drivePath) return;

    try {
        const response = await fetch(props.drivePath, { headers: { Accept: "application/json" } });

        if (!response.ok) return;

        const data = await response.json();
        driveFiles.value = Array.isArray(data?.files) ? data.files : [];
    } catch {
        // Silencieux : un dossier qu'on ne joint pas est une section qui ne
        // s'affiche pas, pas une erreur sur la page d'un client.
    }
});

/**
 * Les trois choses qu'un client vient faire ici, et une seule à la fois.
 *
 * **La page les empilait, sur près de deux mille pixels.** Le calendrier, la
 * discussion et les documents se suivaient, donc lire un message demandait de
 * dépasser un mois entier, et retrouver un fichier de dépasser les deux. Sur
 * téléphone la page devenait un couloir.
 *
 * Le même idiome qu'un espace côté studio, délibérément : c'est la même
 * matière, et un client qui verrait son prestataire travailler ne devrait pas
 * découvrir un second vocabulaire.
 *
 * Un onglet qui n'a rien à montrer n'existe pas : pas de discussion sans canal
 * lisible, pas de documents sans fichier. Une page à un seul onglet n'en
 * dessine aucun - un sélecteur à un choix est un ornement.
 */
const VIEWS = [
    { key: "calendar", labelKey: "studio.public.space.tab_calendar", icon: CalendarDays },
    { key: "chat", labelKey: "studio.public.space.tab_chat", icon: MessagesSquare },
    { key: "files", labelKey: "studio.public.space.tab_files", icon: Paperclip },
    // Ce que le prestataire a épinglé pour ce client, puis la fiche qu'il
    // tient sur lui. En dernier parce qu'on les consulte de temps en temps :
    // ce qu'on vient voir est le calendrier.
    { key: "resources", labelKey: "studio.public.space.tab_resources", icon: Link2 },
    { key: "information", labelKey: "studio.public.space.tab_information", icon: IdCard },
];

const hasChat = computed(() => props.chatChannels.length > 0);
const hasFiles = computed(() => props.spaceFiles.length > 0 || driveFiles.value.length > 0);
const hasResources = computed(() => props.resources.length > 0);
const hasInformation = computed(() => null !== props.information);

const views = computed(() => VIEWS.filter((entry) => {
    if ("chat" === entry.key) return hasChat.value;
    if ("files" === entry.key) return hasFiles.value;
    if ("resources" === entry.key) return hasResources.value;
    if ("information" === entry.key) return hasInformation.value;

    return true;
}));

const view = ref("calendar");

/**
 * Le calendrier se remesure en revenant dessus.
 *
 * `useNarrowContainer` observe un élément ; caché puis remonté, il repart
 * d'une largeur nulle et la grille se croit sur téléphone. Un battement de
 * cycle suffit à lui redonner sa taille.
 */
watch(view, async (now) => {
    if ("calendar" !== now) return;

    await nextTick();
    window.dispatchEvent(new Event("resize"));
});

function driveAddress(file) {
    return (props.driveFilePath ?? "").replace("__id__", file.id);
}

/**
 * La même adresse, mais pour emporter le fichier.
 *
 * Le nom n'est pas mis ici : le serveur le redemande à Google, parce qu'un
 * nom venu du navigateur finirait dans un en-tête de réponse.
 */
function driveDownload(file) {
    return driveAddress(file) + "?download=1";
}

/** Le jour que la liste montre. Aujourd'hui tant que personne n'en a choisi un. */
const selectedDay = ref(new Date());

function sameDay(a, b) {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

const dayItems = computed(() =>
    visibleEvents.value
        .filter((event) => sameDay(new Date(event.startAt), selectedDay.value))
        .sort((a, b) => new Date(a.startAt) - new Date(b.startAt)),
);

const dayTitle = computed(() =>
    d(selectedDay.value, { weekday: "long", day: "numeric", month: "long" }),
);

const itemsById = computed(
    () => new Map(items.value.map((item) => [item.id, item])),
);

const openItem = ref(null);

const monthTitle = computed(() =>
    d(new Date(year.value, month.value, 1), { year: "numeric", month: "long" }),
);

function goToMonth(delta) {
    const moved = new Date(year.value, month.value + delta, 1);
    year.value = moved.getFullYear();
    month.value = moved.getMonth();
}

const openWhen = computed(() => {
    if (!openItem.value?.scheduledAt) return "";

    return d(new Date(openItem.value.scheduledAt), "long");
});

const answering = ref("");
const posting = ref(false);

const thread = computed(() =>
    openItem.value ? (comments.value[openItem.value.id] ?? []) : [],
);

const files = computed(() =>
    openItem.value ? (attachments.value[openItem.value.id] ?? []) : [],
);

const uploading = ref(false);

/**
 * Sends one file onto the open card.
 *
 * Multipart rather than JSON, and one request per file: a browser that gave up
 * halfway through a batch would leave the reader unable to tell which of their
 * photos arrived.
 *
 * Nothing is checked here beyond there being a path. What may be sent is
 * decided on the server, by the sniffed type of the bytes, because anything
 * this page enforced would be a suggestion.
 */
async function upload(file) {
    if (!props.uploadPath || !openItem.value || uploading.value) return;

    const form = new FormData();
    form.append("file", file);

    uploading.value = true;
    try {
        const data = await request(
            buildPath(props.uploadPath, { id: openItem.value.id }),
            null,
            { rawBody: form },
        );

        if (!data?.success) return;

        if (Array.isArray(data.items)) items.value = data.items;
        if (data.comments) comments.value = data.comments;
        if (data.attachments) attachments.value = data.attachments;
    } finally {
        uploading.value = false;
    }
}

/** A message with no verdict attached: the reader is answering a rewrite. */
async function postComment(body) {
    if (!props.commentPath || !openItem.value || posting.value) return;

    posting.value = true;
    try {
        const data = await request(
            buildPath(props.commentPath, { id: openItem.value.id }),
            { body },
        );

        if (!data?.success) return;

        if (Array.isArray(data.items)) items.value = data.items;
        if (data.comments) comments.value = data.comments;
    } finally {
        posting.value = false;
    }
}

/**
 * Says what the reader thinks of one piece of content.
 *
 * The verdict alone: the reason belongs in the thread, where both sides can see
 * it and where it survives the studio rewriting the text. It used to travel in
 * a field of its own beside these buttons, which put two boxes on one screen
 * and left the reader guessing which one their agency would read.
 */
async function answer(approval) {
    if (!props.answerPath || !openItem.value) return;

    answering.value = approval;
    try {
        const data = await request(
            buildPath(props.answerPath, { id: openItem.value.id }),
            { approval },
        );

        if (!data?.success) return;

        if (Array.isArray(data.items)) items.value = data.items;
        if (data.comments) comments.value = data.comments;
        toast.success(t("studio.public.space.answer_recorded"));
        openItem.value = null;
    } finally {
        answering.value = "";
    }
}

function open(event) {
    openItem.value = itemsById.value.get(event.id) ?? null;
}

/**
 * Valider plusieurs cartes d'un geste.
 *
 * **En lot pour l'accord, jamais pour la reprise.** Approuver dix contenus d'un
 * coup dit une seule chose, dix fois. Demander une modification sans dire
 * laquelle n'apprend rien au studio et l'oblige à rappeler pour comprendre :
 * elle reste attachée à une carte et à son commentaire.
 */
const selectedIds = ref([]);

function toggleSelection(id) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((entry) => entry !== id)
        : [...selectedIds.value, id];
}

const allSelected = computed(
    () =>
        pendingEvents.value.length > 0 &&
        selectedIds.value.length === pendingEvents.value.length,
);

function toggleAll() {
    selectedIds.value = allSelected.value
        ? []
        : pendingEvents.value.map((event) => event.id);
}

const approvingMany = ref(false);

async function approveSelected() {
    if (!props.approveManyPath || approvingMany.value) return;
    if (0 === selectedIds.value.length) return;

    approvingMany.value = true;
    try {
        const data = await request(props.approveManyPath, {
            ids: selectedIds.value,
        });

        if (!data?.success) return;

        if (Array.isArray(data.items)) items.value = data.items;
        if (data.comments) comments.value = data.comments;
        toast.success(
            t("studio.public.space.approved_many", { count: data.approved }),
        );
        selectedIds.value = [];
    } finally {
        approvingMany.value = false;
    }
}

/** L'échéance de relecture d'une carte, telle qu'elle se lit. */
function reviewByLabel(event) {
    const item = itemsById.value.get(event.id);

    return item?.reviewBy ? d(new Date(item.reviewBy), "long") : "";
}

function isLate(event) {
    return true === itemsById.value.get(event.id)?.lateForReview;
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col gap-5 p-4 sm:p-8">
        <!-- En premier et impossible à manquer : sans ce bandeau, rien ne
             distingue cette page de celle du client, et on finirait par
             croire avoir répondu à sa place. -->
        <p
            v-if="preview"
            class="flex items-center gap-2 rounded-lg border border-accent/40 bg-accent/10 px-3 py-2 text-xs text-primary"
        >
            <Eye class="h-4 w-4 shrink-0" :stroke-width="2" />
            {{ t("studio.public.space.preview_notice") }}
        </p>

        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: `var(--chart-cat-${space.colourSlot})` }"
                />
                <div class="min-w-0">
                    <h1 class="truncate text-base font-semibold text-primary">
                        {{ space.name }}
                    </h1>
                    <p class="truncate text-sm text-muted">{{ space.customerName }}</p>
                </div>
            </div>
            <AppThemeToggle />
        </header>

        <p v-if="space.description" class="max-w-2xl text-sm text-secondary">
            {{ space.description }}
        </p>

        <!-- Dessinée à partir du second onglet : un sélecteur à un choix
             n'aide personne à choisir. La bande défile plutôt que de pousser
             la page, et hors onglet actif le libellé reste au lecteur d'écran
             sur téléphone - l'icône suffit à reconnaître une pièce où l'on est
             déjà allé. -->
        <div
            v-if="views.length > 1"
            class="flex max-w-full items-center gap-0.5 overflow-x-auto rounded-lg border border-line/60 bg-surface-2/40 p-0.5"
            role="group"
            :aria-label="t('studio.public.space.tabs_label')"
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

        <template v-if="'calendar' === view">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                        :aria-label="t('shared.common.previous')"
                        v-on:click="goToMonth(-1)"
                    >
                        <ChevronLeft class="h-4 w-4" :stroke-width="2" />
                    </button>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                        :aria-label="t('shared.common.next')"
                        v-on:click="goToMonth(1)"
                    >
                        <ChevronRight class="h-4 w-4" :stroke-width="2" />
                    </button>
                    <h2 class="ml-2 text-sm font-medium capitalize text-primary">
                        {{ monthTitle }}
                    </h2>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <!-- La première chose à lire en arrivant : ce qui attend une
                         réponse. Il fait aussi filtre, parce qu'un client qui
                         revient veut sa liste de tâches et pas son mois. -->
                    <button
                        v-if="showsReviewFilter"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                        :class="reviewOnly
                            ? 'border-accent-500 bg-accent-500 text-white'
                            : 'border-line text-secondary hover:border-accent hover:text-primary'"
                        :aria-pressed="reviewOnly"
                        v-on:click="reviewOnly = !reviewOnly"
                    >
                        <MessageSquare class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("studio.public.space.awaiting_you", { count: pendingEvents.length }) }}
                    </button>
                    <p class="text-xs text-muted">
                        {{ t("studio.public.space.timezone_notice", { timezone: space.timezone }) }}
                    </p>
                </div>
            </div>

            <div ref="container" class="space-y-3">
                <p
                    v-if="reviewOnly && !pendingEvents.length"
                    class="rounded-xl border border-line/60 bg-surface px-3 py-3 text-xs text-muted"
                >
                    {{ t("studio.public.space.nothing_awaiting") }}
                </p>

                <CalendarMonth
                    :cells="cells"
                    :events="visibleEvents"
                    :compact="isNarrow"
                    :selected="isNarrow ? selectedDay : null"
                    v-on:open-event="open"
                    v-on:select-day="selectedDay = $event"
                />

                <!-- La liste de relecture : ce que le filtre promet, à savoir
                     une liste de tâches et pas un mois. Elle remplace la liste
                     du jour tant qu'il est enclenché, sur téléphone comme sur
                     un écran large, parce que ce qu'on vient faire ici est
                     répondre et non naviguer entre les jours. -->
                <section
                    v-if="reviewOnly && pendingEvents.length"
                    class="rounded-xl border border-line/60 bg-surface"
                >
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line/40 px-3 py-2">
                        <label class="flex items-center gap-2 text-sm text-primary">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-line accent-accent-500"
                                :checked="allSelected"
                                v-on:change="toggleAll"
                            >
                            {{ t("studio.public.space.select_all") }}
                        </label>

                        <button
                            v-if="approveManyPath"
                            type="button"
                            class="rounded-lg bg-accent-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-accent-600 disabled:opacity-50"
                            :disabled="!selectedIds.length || approvingMany"
                            v-on:click="approveSelected"
                        >
                            <Check class="mr-1 inline h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("studio.public.space.approve_selected", { count: selectedIds.length }) }}
                        </button>
                    </header>

                    <ul class="divide-y divide-line/40">
                        <li
                            v-for="event in pendingEvents"
                            :key="event.id"
                            class="flex items-start gap-2 px-3 py-2.5"
                        >
                            <input
                                type="checkbox"
                                class="mt-1 h-4 w-4 shrink-0 rounded border-line accent-accent-500"
                                :checked="selectedIds.includes(event.id)"
                                :aria-label="event.title"
                                v-on:change="toggleSelection(event.id)"
                            >
                            <button
                                type="button"
                                class="min-w-0 flex-1 text-left"
                                v-on:click="open(event)"
                            >
                                <span class="block truncate text-sm text-primary">{{ event.title }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-2 text-2xs text-muted">
                                    <span>{{ d(new Date(event.startAt), "long") }}</span>
                                    <!-- L'échéance de relecture, quand il y en
                                         a une. En retard se dit, mais ne
                                         bloque rien : c'est une information. -->
                                    <span
                                        v-if="reviewByLabel(event)"
                                        :class="isLate(event) ? 'rounded-full bg-warning-soft px-1.5 py-0.5 font-medium text-warning' : ''"
                                    >
                                        {{ t("studio.public.space.review_by", { date: reviewByLabel(event) }) }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>

                <!-- La grille dit quels jours portent quelque chose ; celle-ci dit
                 quoi. L'une sans l'autre est illisible sur un téléphone. -->
                <section v-if="isNarrow && !reviewOnly" class="rounded-xl border border-line/60 bg-surface">
                    <header class="flex items-baseline gap-2 border-b border-line/40 px-3 py-2">
                        <h3 class="text-sm font-medium capitalize text-primary">
                            {{ dayTitle }}
                        </h3>
                        <span class="text-xs tabular-nums text-muted">{{ dayItems.length }}</span>
                    </header>

                    <p v-if="!dayItems.length" class="px-3 py-3 text-xs text-muted">
                        {{ t("studio.public.space.calendar_day_empty") }}
                    </p>

                    <ul v-else class="divide-y divide-line/40">
                        <li v-for="event in dayItems" :key="event.id">
                            <button
                                type="button"
                                class="flex w-full items-baseline gap-2 px-3 py-2.5 text-left transition-colors hover:bg-surface-2/60"
                                v-on:click="open(event)"
                            >
                                <span class="shrink-0 text-xs tabular-nums text-muted">
                                    {{ d(new Date(event.startAt), { hour: "2-digit", minute: "2-digit" }) }}
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm text-primary">
                                    {{ event.title }}
                                </span>
                                <!-- Ce que ce client a déjà dit sur cette carte.
                                     Rien pour « sans réponse » : c'est le cas
                                     ordinaire, et une pastille sur chaque ligne
                                     ne distinguerait plus rien. -->
                                <span
                                    v-if="canApprove && 'pending' !== event.approval"
                                    class="shrink-0 rounded-full px-1.5 py-0.5 text-2xs font-medium"
                                    :class="'approved' === event.approval
                                        ? 'bg-success-soft text-success'
                                        : 'bg-warning-soft text-warning'"
                                >
                                    {{ t(`studio.public.space.approval.${event.approval}`) }}
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <!-- Dans son onglet plutôt que sous le mois, et jamais en bulle
             flottante : cette page se lit autant sur un téléphone que sur un
             bureau, et un widget épinglé par-dessus un calendrier couvre ce
             pour quoi le client est venu. -->
        <SpaceChatPanel
            v-if="'chat' === view"
            :messages="chatMessages"
            :stream-url="chatStreamUrl"
            :post-path="chatPostPath"
            :reload-path="chatReloadPath"
            :channels="chatChannels"
            :channel-id="chatChannelId"
            :chat-direct-path="chatDirectPath"
            :older-path="chatOlderPath"
            :hide-path="chatHidePath"
            :people="chatPeople"
            own-side="client"
            :notice="chatPostPath ? t('studio.public.space.chat_notice') : ''"
        />

        <!-- Les fichiers de l'espace, s'il y en a. Sous la discussion parce
             qu'on ne vient pas ici pour eux : ce sont des documents qu'on
             retrouve, pas des nouvelles qu'on lit. Rien n'est affiché quand
             l'espace n'en porte aucun - une section vide sur la page d'un
             client donne l'impression d'un écran inachevé. -->
        <!-- Le dossier Drive du prestataire. Sous la discussion et au-dessus
             des fichiers de l'espace : ce sont des documents qu'on retrouve,
             pas des nouvelles qu'on lit, et ils viennent d'ailleurs.

             Chaque adresse passe par ici et non par Google : le dossier n'est
             partagé qu'avec le compte de service, donc une adresse Drive
             donnerait à ce lecteur un mur d'authentification. -->
        <template v-if="'files' === view">
            <section v-if="driveFiles.length" class="space-y-3">
                <!-- « Je prends tout » est la question que se pose un client à qui
                 on partage trente visuels. Le titre et le lot sur la même
                 ligne, parce que c'est l'action de la section entière et non
                 d'une de ses lignes. -->
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-sm font-medium text-primary">
                        {{ t("studio.public.space.drive_title") }}
                    </h2>

                    <a
                        v-if="driveArchivePath"
                        :href="driveArchivePath"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-line/60 px-3 py-2 text-xs text-primary transition-colors hover:bg-surface-2 sm:w-auto"
                    >
                        <Package class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                        {{ t("studio.public.space.drive_archive") }}
                    </a>
                </div>

                <!-- Ouvrir et télécharger sont deux gestes, donc deux commandes.
                 Un seul lien obligeait à ouvrir le fichier dans un onglet puis
                 à le réenregistrer depuis la visionneuse du navigateur, ce qui
                 pour une vidéo ou un gros PDF veut dire le charger deux fois. -->
                <ul class="divide-y divide-line/60 rounded-lg border border-line/60">
                    <!-- Le nom seul sur sa ligne quand la place manque : un
                         chemin de dossier suivi d'un nom de fichier dépasse
                         trois cent soixante-quinze pixels bien avant d'avoir
                         dit quoi que ce soit d'utile. -->
                    <li
                        v-for="file in driveFiles"
                        :key="file.id"
                        class="flex flex-col gap-1.5 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-2"
                    >
                        <a
                            :href="driveAddress(file)"
                            target="_blank"
                            rel="noopener"
                            class="min-w-0 flex-1 break-words py-1 text-sm text-primary transition-colors hover:text-accent sm:truncate"
                        >
                            <span v-if="file.path" class="text-muted">{{ file.path }}/</span>{{ file.name }}
                        </a>

                        <div class="flex items-center justify-between gap-2 sm:contents">
                            <span v-if="file.size" class="shrink-0 text-xs tabular-nums text-muted">{{ formatSize(file.size) }}</span>
                            <a
                                :href="driveDownload(file)"
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-line/60 text-primary transition-colors hover:bg-surface-2"
                                :title="t('studio.public.space.drive_download')"
                                :aria-label="t('studio.public.space.drive_download')"
                            >
                                <Download class="h-3.5 w-3.5" :stroke-width="2" />
                            </a>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="spaceFiles.length" class="space-y-3">
                <h2 class="text-sm font-medium text-primary">
                    {{ t("studio.public.space.files_title") }}
                </h2>

                <ul class="divide-y divide-line/60 rounded-lg border border-line/60">
                    <!-- En colonne sur téléphone, en ligne au-delà. Trois
                         choses sur une ligne de trois cent soixante-quinze
                         pixels tronquent toujours la même : le nom du fichier,
                         qui est la seule qu'on lit. -->
                    <li
                        v-for="file in spaceFiles"
                        :key="file.id"
                        class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-3"
                    >
                        <div class="flex min-w-0 items-center gap-3 sm:contents">
                            <img
                                v-if="file.preview"
                                :src="file.preview"
                                :alt="file.title"
                                class="h-10 w-10 shrink-0 rounded object-cover"
                                loading="lazy"
                            >
                            <span
                                v-else
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-surface-2 text-muted"
                            >
                                <FileText class="h-4 w-4" :stroke-width="2" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-primary sm:truncate">{{ file.title }}</p>
                                <p class="text-xs text-muted">{{ d(new Date(file.createdAt), "short") }}</p>
                            </div>
                        </div>

                        <a
                            :href="file.url"
                            target="_blank"
                            rel="noopener"
                            class="block w-full shrink-0 rounded-md border border-line/60 px-2.5 py-2 text-center text-xs text-primary transition-colors hover:bg-surface-2 sm:w-auto sm:py-1.5"
                        >
                            {{ t("studio.public.space.files_open") }}
                        </a>
                    </li>
                </ul>
            </section>
        </template>

        <!-- Ce que le prestataire a épinglé pour ce client : une maquette, un
             accès, la personne à qui écrire. Ce qui n'a pas été ouvert n'est
             pas ici - ce n'est pas caché à l'affichage, ce n'est jamais sorti
             du serveur. -->
        <section v-if="'resources' === view" class="space-y-3">
            <ul class="space-y-2">
                <li
                    v-for="resource in resources"
                    :key="resource.id"
                    class="rounded-xl border border-line/60 bg-surface p-3"
                >
                    <SpaceResourceItem :resource="resource" />
                </li>
            </ul>
        </section>

        <!-- La fiche que le prestataire tient sur vous. Montrée au client
             parce que c'est de lui qu'elle parle : un SIRET mal recopié se
             voit par celui qui le connaît, et pas autrement. -->
        <section v-if="'information' === view" class="rounded-xl border border-line/60 bg-surface p-4">
            <CustomerInformationCard :information="information" />
        </section>

        <!-- One sentence, not two stacked lines. The expiry and the "do not
             forward" were separate paragraphs saying one thing between them:
             this address is yours, it does not last for ever, keep it. The
             date-less variant is what a link with no expiry gets. -->
        <footer class="mt-auto border-t border-line/50 pt-3 text-xs text-muted">
            <p>
                {{
                    expiresAt
                        ? t("studio.public.space.footer_until", {
                            date: d(new Date(expiresAt), "long"),
                        })
                        : t("studio.public.space.footer")
                }}
            </p>
        </footer>

        <AppModal
            :show="!!openItem"
            max-width="lg"
            :title="openItem?.title ?? ''"
            :icon="FileText"
            v-on:close="openItem = null"
        >
            <p class="text-xs text-muted">
                {{ columnNames.get(openItem?.columnId) }}
                <span v-if="openWhen"> · {{ openWhen }}</span>
            </p>
            <p
                v-if="openItem?.body"
                class="mt-3 whitespace-pre-line text-sm text-primary"
            >
                {{ openItem.body }}
            </p>
            <p v-else class="mt-3 text-sm text-muted">
                {{ t("studio.public.space.no_body") }}
            </p>

            <!-- The files, above the thread and shown whatever the link may
                 do: seeing the visual is the point of being asked to approve,
                 and it has nothing to do with being allowed to add one.
                 Removing is never offered here - taking a file off a card is
                 the studio's call. -->
            <div v-if="openItem" class="mt-4 border-t border-line/50 pt-4">
                <SpaceContentAttachments
                    :attachments="files"
                    :can-add="canUpload"
                    :loading="uploading"
                    :notice="canUpload ? t('studio.public.space.upload_notice') : ''"
                    v-on:upload="upload"
                />
            </div>

            <!-- The same thread the studio reads, in the same component:
                 one conversation, not two renderings of it. -->
            <div v-if="openItem" class="mt-4 border-t border-line/50 pt-4">
                <SpaceContentThread
                    :comments="thread"
                    :verdict="openItem?.approval ?? 'pending'"
                    :verdict-by="openItem?.approvalBy ?? ''"
                    :verdict-at="openItem?.approvalAt ?? null"
                    :can-post="canComment"
                    :loading="posting"
                    :notice="t('studio.public.space.thread_notice')"
                    v-on:post="postComment"
                />
            </div>

            <!-- Two buttons and no box of its own. The words go in the thread
                 above, which is the only place on this page somebody types: a
                 second field beside the verdict was a second door to the same
                 message, and the reader had to guess which one counted. -->
            <section v-if="canApprove" class="mt-4 space-y-2 border-t border-line/50 pt-4">
                <p class="text-xs text-muted">
                    {{ t("studio.public.space.answer_hint") }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="answering === 'approved'"
                        v-on:click="answer('approved')"
                    >
                        <Check class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("studio.public.space.approve") }}
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="md"
                        :loading="answering === 'changes_requested'"
                        v-on:click="answer('changes_requested')"
                    >
                        <MessageSquare class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("studio.public.space.request_changes") }}
                    </AppButton>
                </div>
            </section>
        </AppModal>
    </div>
</template>
