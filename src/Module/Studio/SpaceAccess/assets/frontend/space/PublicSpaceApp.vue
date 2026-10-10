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
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarDays, IdCard, Link2, MessagesSquare, NotebookText, Paperclip, Upload } from "lucide-vue-next";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
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
    Presentation,
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
    /** The batch approval. Null for the same reasons as `answerPath`. */
    approveManyPath: { type: String, default: null },
    commentPath: { type: String, default: null },
    canUpload: { type: Boolean, default: false },
    /** True when the studio is looking at the page itself, not the client. */
    preview: { type: Boolean, default: false },
    attachments: { type: Object, default: () => ({}) },
    uploadPath: { type: String, default: null },
    /** The space's own files, the ones attached to no card. */
    spaceFiles: { type: Array, default: () => [] },
    /**
     * Where a file for the space itself is sent, from the Files tab. Null
     * without the link's right to send files, so there is no button at all.
     */
    spaceFileUploadPath: { type: String, default: null },
    /** The provider's Drive folder, when they connected one. */
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
     * The provider's record on this client, or `null`.
     *
     * `null` when it says nothing more than the name, which the client already
     * knows: the tab then does not exist, like the chat without a channel.
     */
    information: { type: Object, default: null },
    /** What the studio opened to the client, and nothing else. */
    resources: { type: Array, default: () => [] },
    /** The documents published for this client: audits, strategies. */
    documents: { type: Array, default: () => [] },
});

const { t, d: formatDate } = useI18n();
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

// What the client sees in their month, with the same rule as the studio: an
// unchecked card carries an internal deadline, not a publication.
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
            // The answer's state travels with the event. The grid ignores it;
            // the counter, the filter and the day list use it.
            approval: item.approval ?? "pending",
        })),
);

/**
 * What is still waiting for an answer from this client.
 *
 * `pending` means nobody has said anything, which is not a refusal: it is
 * exactly the set a client comes back looking for.
 */
const pendingEvents = computed(() =>
    events.value.filter((event) => "pending" === event.approval),
);

/**
 * The month reduced to what is waiting, when the client asks for it.
 *
 * A filter rather than a dot on the grid: the month is a component shared by
 * the whole application, and the event already carries a colour there, the
 * one of its step. A second colour code on the same dot cannot be read.
 * Removing what is not waiting on them says the same thing without repainting
 * anything.
 */
const reviewOnly = ref(false);

const visibleEvents = computed(() =>
    reviewOnly.value ? pendingEvents.value : events.value,
);

/** The button stays while it is on, otherwise it would vanish under the finger. */
const showsReviewFilter = computed(
    () => props.canApprove && (pendingEvents.value.length > 0 || reviewOnly.value),
);

/**
 * A month grid does not fit on a phone, and that can be measured.
 *
 * Seven columns in three hundred and seventy-five pixels make cells of fifty:
 * the three other Aurora calendars therefore switch to a dot index below the
 * threshold, with the day list underneath. This one was the only one not to,
 * and it showed the client event chips sixteen pixels high - the height of a
 * line of text, not of a target.
 */
const { container, isNarrow } = useNarrowContainer(560);

/**
 * The files of the Drive folder, if there is one.
 *
 * **Loaded after the page, never with it.** Reading a folder at Google takes
 * two tenths of a second and can fail; making the page wait for it would delay
 * what the client really comes to see. The section appears when the answer
 * arrives, and stays absent if it does not come.
 */
const driveFiles = ref([]);

/** The space's own files, replaced by what the server answers after a send. */
const spaceFileList = ref(props.spaceFiles ?? []);

onMounted(async () => {
    if (!props.drivePath) return;

    try {
        const response = await fetch(props.drivePath, { headers: { Accept: "application/json" } });

        if (!response.ok) return;

        const data = await response.json();
        driveFiles.value = Array.isArray(data?.files) ? data.files : [];
    } catch {
        // Silent: a folder that cannot be reached is a section that does not
        // show, not an error on a client's page.
    }
});

/**
 * The three things a client comes here to do, and only one at a time.
 *
 * **The page used to stack them, over nearly two thousand pixels.** The
 * calendar, the chat and the documents followed each other, so reading a
 * message meant scrolling past a whole month, and finding a file meant
 * scrolling past both. On a phone the page became a corridor.
 *
 * The same idiom as a space on the studio side, on purpose: it is the same
 * material, and a client who saw their provider at work should not discover a
 * second vocabulary.
 *
 * A tab with nothing to show does not exist: no chat without a readable
 * channel, no documents without a file. A page with a single tab draws none -
 * a selector with one choice is an ornament.
 */
const VIEWS = [
    { key: "calendar", labelKey: "studio.public.space.tab_calendar", icon: CalendarDays },
    { key: "chat", labelKey: "studio.public.space.tab_chat", icon: MessagesSquare },
    { key: "files", labelKey: "studio.public.space.tab_files", icon: Paperclip },
    // What the provider wrote for this client, an audit, a strategy: they are
    // read, so they sit near the files rather than with the record.
    { key: "documents", labelKey: "studio.public.space.tab_documents", icon: NotebookText },
    // What the provider pinned for this client, then the record they keep on
    // them. Last because they are looked up now and then: what people come to
    // see is the calendar.
    { key: "resources", labelKey: "studio.public.space.tab_resources", icon: Link2 },
    { key: "information", labelKey: "studio.public.space.tab_information", icon: IdCard },
];

const hasChat = computed(() => props.chatChannels.length > 0);
// The tab also exists to send the first file: a link that may send files has
// somewhere to do it even before anything was shared.
const hasFiles = computed(
    () => spaceFileList.value.length > 0 || driveFiles.value.length > 0 || !!props.spaceFileUploadPath,
);
const hasResources = computed(() => props.resources.length > 0);
const hasDocuments = computed(() => props.documents.length > 0);
const hasInformation = computed(() => null !== props.information);

const views = computed(() => VIEWS.filter((entry) => {
    if ("chat" === entry.key) return hasChat.value;
    if ("files" === entry.key) return hasFiles.value;
    if ("resources" === entry.key) return hasResources.value;
    if ("documents" === entry.key) return hasDocuments.value;
    if ("information" === entry.key) return hasInformation.value;

    return true;
}));

const view = ref("calendar");

/**
 * The how-to guide, reduced to what this link allows.
 *
 * Each right of the link (approve, comment, send a file, write in the chat)
 * is set when it is created: a step promising a button missing from the page
 * would send the client looking for something that does not exist. Hence the
 * same conditions as the ones that draw the controls.
 */
const guideSteps = computed(() => {
    const steps = ["calendar"];

    if (props.canApprove) steps.push("answer");

    if (props.canComment && props.canUpload) steps.push("comment_upload");
    else if (props.canComment) steps.push("comment");
    else if (props.canUpload) steps.push("upload");

    if (props.spaceFileUploadPath) steps.push("files_upload");

    if (hasChat.value) steps.push(props.chatPostPath ? "chat" : "chat_read");

    if (hasFiles.value || hasDocuments.value || hasResources.value || hasInformation.value) {
        steps.push("tabs");
    }

    return steps;
});

/**
 * The calendar measures itself again when the view comes back to it.
 *
 * `useNarrowContainer` observes an element; hidden then mounted again, it
 * starts from a zero width and the grid believes it is on a phone. One tick
 * is enough to give it its size back.
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
 * The same address, but to take the file away.
 *
 * The name is not put here: the server asks Google for it again, because a
 * name coming from the browser would end up in a response header.
 */
function driveDownload(file) {
    return driveAddress(file) + "?download=1";
}

/** The day the list shows. Today until someone picks one. */
const selectedDay = ref(new Date());

function sameDay(first, second) {
    return (
        first.getFullYear() === second.getFullYear() &&
        first.getMonth() === second.getMonth() &&
        first.getDate() === second.getDate()
    );
}

const dayItems = computed(() =>
    visibleEvents.value
        .filter((event) => sameDay(new Date(event.startAt), selectedDay.value))
        .sort((left, right) => new Date(left.startAt) - new Date(right.startAt)),
);

const dayTitle = computed(() =>
    formatDate(selectedDay.value, { weekday: "long", day: "numeric", month: "long" }),
);

const itemsById = computed(
    () => new Map(items.value.map((item) => [item.id, item])),
);

const openItem = ref(null);

const monthTitle = computed(() =>
    formatDate(new Date(year.value, month.value, 1), { year: "numeric", month: "long" }),
);

function goToMonth(delta) {
    const moved = new Date(year.value, month.value + delta, 1);
    year.value = moved.getFullYear();
    month.value = moved.getMonth();
}

const openWhen = computed(() => {
    if (!openItem.value?.scheduledAt) return "";

    return formatDate(new Date(openItem.value.scheduledAt), "long");
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

        // A refusal from the policy (too heavy, a type the space does not
        // take) is said, as on the Files tab: silence read as a success.
        if (data && !data.success) {
            toast.error(t(data.errors?.file ?? "studio.public.space.errors.upload_failed"));

            return;
        }

        if (!data) return;

        if (Array.isArray(data.items)) items.value = data.items;
        if (data.comments) comments.value = data.comments;
        if (data.attachments) attachments.value = data.attachments;
    } finally {
        uploading.value = false;
    }
}

/** The hidden file field behind « Send a file »: a button is drawn, an `input[type=file]` is not. */
const spaceFileInput = ref(null);
const sendingSpaceFile = ref(false);

/**
 * Sends one file to the space itself, from the Files tab.
 *
 * The same rules as a file on a card: multipart, one request per file, and
 * what may be sent is decided by the server from the bytes. Never from a
 * preview, whose button is disabled and whose route refuses anyway.
 */
async function sendSpaceFile(event) {
    const file = event.target.files?.[0];

    // Reset, or choosing the same file twice in a row would emit nothing.
    event.target.value = "";

    if (!file || !props.spaceFileUploadPath || props.preview || sendingSpaceFile.value) return;

    const form = new FormData();
    form.append("file", file);

    sendingSpaceFile.value = true;
    try {
        const data = await request(props.spaceFileUploadPath, null, { rawBody: form });

        // A refusal from the policy is a sentence the client acts on (a
        // lighter file, another type), so it is said rather than swallowed.
        if (data && !data.success) {
            toast.error(t(data.errors?.file ?? "studio.public.space.errors.upload_failed"));

            return;
        }

        if (!data) return;

        if (Array.isArray(data.spaceFiles)) spaceFileList.value = data.spaceFiles;
        toast.success(t("studio.public.space.files_uploaded"));
    } finally {
        sendingSpaceFile.value = false;
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
 * Approve several cards in one gesture.
 *
 * **In batch for approval, never for changes.** Approving ten items at once
 * says one thing, ten times. Asking for a change without saying which one
 * teaches the studio nothing and forces it to call back to understand: a
 * change request stays attached to one card and its comment.
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

/** A card's review deadline, as it reads on screen. */
function reviewByLabel(event) {
    const item = itemsById.value.get(event.id);

    return item?.reviewBy ? formatDate(new Date(item.reviewBy), "long") : "";
}

function isLate(event) {
    return true === itemsById.value.get(event.id)?.lateForReview;
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col gap-5 p-4 sm:p-8">
        <!-- First and impossible to miss: without this banner, nothing tells
             this page apart from the client's, and one would end up thinking
             they had answered on the client's behalf. -->
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


        <!-- The screen's how-to guide, next to what it explains; collapsed
             or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('studio.public.space.guide.title')" storage-key="public-space">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in guideSteps" :key="step">{{ t(`studio.public.space.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Drawn from the second tab on: a selector with one choice helps
             nobody choose. The strip scrolls rather than pushing the page,
             and outside the active tab the label is left to the screen reader
             on a phone - the icon is enough to recognise a room one has
             already visited. -->
        <div
            v-if="views.length > 1"
            class="flex max-w-full items-center gap-0.5 overflow-x-auto aurora-segmented"
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
                    <!-- The first thing to read on arrival: what is waiting
                         for an answer. It is also a filter, because a client
                         coming back wants their task list, not their month. -->
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
                    class="aurora-card px-3 py-3 text-xs text-muted"
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

                <!-- The review list: what the filter promises, namely a task
                     list and not a month. It replaces the day list while the
                     filter is on, on a phone as on a wide screen, because what
                     one comes here to do is answer, not move between days. -->
                <section
                    v-if="reviewOnly && pendingEvents.length"
                    class="aurora-card"
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
                                    <span>{{ formatDate(new Date(event.startAt), "long") }}</span>
                                    <!-- The review deadline, when there is
                                         one. Lateness is shown, but blocks
                                         nothing: it is information. -->
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

                <!-- The grid says which days carry something; this one says
                 what. One without the other is unreadable on a phone. -->
                <section v-if="isNarrow && !reviewOnly" class="aurora-card">
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
                                    {{ formatDate(new Date(event.startAt), { hour: "2-digit", minute: "2-digit" }) }}
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm text-primary">
                                    {{ event.title }}
                                </span>
                                <!-- What this client already said on this card.
                                     Nothing for "no answer": it is the usual
                                     case, and a badge on every line would no
                                     longer set anything apart. -->
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

        <!-- In its own tab rather than under the month, and never as a
             floating bubble: this page is read as much on a phone as on a
             desktop, and a widget pinned over a calendar covers what the
             client came for. -->
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

        <!-- The space's files, if there are any. Below the chat because
             nobody comes here for them: they are documents one looks up, not
             news one reads. Nothing is shown when the space has none - an
             empty section on a client's page looks like an unfinished
             screen. -->
        <!-- The provider's Drive folder. Below the chat and above the
             space's files: they are documents one looks up, not news one
             reads, and they come from elsewhere.

             Every address goes through here and not through Google: the
             folder is shared only with the service account, so a Drive
             address would give this reader an authentication wall. -->
        <template v-if="'files' === view">
            <!-- The tab's action, above what it adds to: the client's way to
                 hand a file over without attaching it to a content item. The
                 notice says what is accepted and who reads it. -->
            <div
                v-if="spaceFileUploadPath"
                class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-xs text-muted">{{ t("studio.public.space.upload_notice") }}</p>

                <input
                    ref="spaceFileInput"
                    type="file"
                    class="hidden"
                    data-test="space-file-input"
                    v-on:change="sendSpaceFile"
                >
                <AppButton
                    class="w-full shrink-0 sm:w-auto"
                    variant="primary"
                    size="sm"
                    data-test="space-file-upload"
                    :loading="sendingSpaceFile"
                    :disabled="preview"
                    v-on:click="spaceFileInput?.click()"
                >
                    <Upload class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("studio.public.space.files_upload") }}
                </AppButton>
            </div>

            <section v-if="driveFiles.length" class="space-y-3">
                <!-- "I'll take everything" is what a client wonders when
                 thirty visuals are shared with them. The title and the batch
                 on the same line, because it is the action of the whole
                 section and not of one of its lines. -->
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-sm font-medium text-primary">
                        {{ t("studio.public.space.drive_title") }}
                    </h2>

                    <a
                        v-if="driveArchivePath"
                        :href="driveArchivePath"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-line px-3 py-2 text-xs text-primary transition-colors hover:bg-surface-2 sm:w-auto"
                    >
                        <Package class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                        {{ t("studio.public.space.drive_archive") }}
                    </a>
                </div>

                <!-- Opening and downloading are two gestures, so two controls.
                 A single link forced opening the file in a tab and then saving
                 it again from the browser's viewer, which for a video or a
                 large PDF means loading it twice. -->
                <ul class="divide-y divide-line/40 rounded-lg border border-line">
                    <!-- The name alone on its line when space runs short: a
                         folder path followed by a file name goes past three
                         hundred and seventy-five pixels well before saying
                         anything useful. -->
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
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-line text-primary transition-colors hover:bg-surface-2"
                                :title="t('studio.public.space.drive_download')"
                                :aria-label="t('studio.public.space.drive_download')"
                            >
                                <Download class="h-3.5 w-3.5" :stroke-width="2" />
                            </a>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="spaceFileList.length || spaceFileUploadPath" class="space-y-3">
                <h2 class="text-sm font-medium text-primary">
                    {{ t("studio.public.space.files_title") }}
                </h2>

                <p v-if="!spaceFileList.length" class="text-sm text-muted">
                    {{ t("studio.public.space.files_empty") }}
                </p>

                <ul v-else class="divide-y divide-line/40 rounded-lg border border-line">
                    <!-- A column on a phone, a row beyond. Three things on a
                         line of three hundred and seventy-five pixels always
                         truncate the same one: the file name, which is the
                         only one people read. -->
                    <li
                        v-for="file in spaceFileList"
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
                                <p class="text-xs text-muted">
                                    {{ formatDate(new Date(file.createdAt), "short") }}
                                    <!-- Who sent it, when it came from the client side: a
                                         space can have several links out. -->
                                    <template v-if="file.fromClient">
                                        · {{ t("studio.public.space.files_sent_by", { author: file.author }) }}
                                    </template>
                                </p>
                            </div>
                        </div>

                        <a
                            :href="file.url"
                            target="_blank"
                            rel="noopener"
                            class="block w-full shrink-0 rounded-md border border-line px-2.5 py-2 text-center text-xs text-primary transition-colors hover:bg-surface-2 sm:w-auto sm:py-1.5"
                        >
                            {{ t("studio.public.space.files_open") }}
                        </a>
                    </li>
                </ul>
            </section>
        </template>

        <!-- The documents written for this client. Each opens in its own
             page, without the rest of the space around it, through the
             space's link: no extra password. -->
        <section v-if="'documents' === view" class="space-y-3">
            <ul class="space-y-2">
                <li v-for="document in documents" :key="document.id">
                    <!-- The deliverable's image on the left, as on the studio
                         side; without an image, a neutral tile keeps the
                         titles aligned. -->
                    <a
                        :href="document.url"
                        class="aurora-card flex items-center gap-3 p-3 no-underline transition-colors hover:bg-surface-2/60"
                    >
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-surface-2 sm:h-14 sm:w-14">
                            <img
                                v-if="document.thumbnailUrl"
                                :src="document.thumbnailUrl"
                                alt=""
                                loading="lazy"
                                class="h-full w-full object-cover"
                                :style="{ objectPosition: document.thumbnailPosition || '50% 50%' }"
                            >
                            <Presentation v-else-if="'slides' === document.format" class="h-5 w-5 text-muted" :stroke-width="1.75" />
                            <FileText v-else class="h-5 w-5 text-muted" :stroke-width="1.75" />
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="text-sm font-medium text-primary">
                                {{ document.title || t("studio.public.space.document_untitled") }}
                            </span>
                            <span v-if="document.description" class="text-xs text-secondary">{{ document.description }}</span>
                            <span class="text-xs text-muted">
                                <!-- A presentation says so before it opens: it is watched
                                     slide by slide, not read like a page. -->
                                <template v-if="'slides' === document.format">{{ t("studio.public.space.document_presentation") }} · </template>{{ t("studio.public.space.document_updated_on", { date: formatDate(new Date(document.updatedAt), "long") }) }}
                            </span>
                        </span>
                    </a>
                </li>
            </ul>
        </section>

        <!-- What the provider pinned for this client: a mockup, an access,
             the person to write to. What was not opened is not here - it is
             not hidden on display, it never left the server. -->
        <section v-if="'resources' === view" class="space-y-3">
            <ul class="space-y-2">
                <li
                    v-for="resource in resources"
                    :key="resource.id"
                    class="aurora-card p-3"
                >
                    <SpaceResourceItem :resource="resource" />
                </li>
            </ul>
        </section>

        <!-- The record the provider keeps on you. Shown to the client
             because it is about them: a mistyped SIRET is spotted by the one
             who knows it, and by no one else. -->
        <section v-if="'information' === view" class="aurora-card p-4">
            <CustomerInformationCard :information="information" />
        </section>

        <!-- One sentence, not two stacked lines. The expiry and the "do not
             forward" were separate paragraphs saying one thing between them:
             this address is yours, it does not last for ever, keep it. The
             date-less variant is what a link with no expiry gets. -->
        <footer class="mt-auto border-t border-line pt-3 text-xs text-muted">
            <p>
                {{
                    expiresAt
                        ? t("studio.public.space.footer_until", {
                            date: formatDate(new Date(expiresAt), "long"),
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
            <div v-if="openItem" class="mt-4 border-t border-line pt-4">
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
            <div v-if="openItem" class="mt-4 border-t border-line pt-4">
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
            <section v-if="canApprove" class="mt-4 space-y-2 border-t border-line pt-4">
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
