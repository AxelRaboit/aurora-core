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
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import CalendarMonth from "@/shared/components/calendar/CalendarMonth.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppThemeToggle from "@/shared/components/action/AppThemeToggle.vue";
import { monthGrid } from "@/shared/composables/calendar/monthGrid.js";
import AppButton from "@/shared/components/action/AppButton.vue";
// Same module, another sub-domain: a relative path rather than an alias.
// The rule that forbids reaching across modules is about modules, and this
// component is the one thing the two surfaces genuinely share.
import SpaceContentThread from "../../../../SpaceContent/assets/shared/SpaceContentThread.vue";
import SpaceContentAttachments from "../../../../SpaceContent/assets/shared/SpaceContentAttachments.vue";
import SpaceChatPanel from "../../../../SpaceChat/assets/shared/SpaceChatPanel.vue";
import {
    Check,
    ChevronLeft,
    ChevronRight,
    FileText,
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
    commentPath: { type: String, default: null },
    canUpload: { type: Boolean, default: false },
    attachments: { type: Object, default: () => ({}) },
    uploadPath: { type: String, default: null },
    /** Les fichiers de l'espace, ceux qui ne sont sur aucune fiche. */
    spaceFiles: { type: Array, default: () => [] },
    chatMessages: { type: Array, default: () => [] },
    /** Null when no hub is running, and then the panel never connects. */
    chatStreamUrl: { type: String, default: null },
    /** Null when this link may only read, so there is no box to type in. */
    chatPostPath: { type: String, default: null },
    chatReloadPath: { type: String, required: true },
    chatChannels: { type: Array, default: () => [] },
    chatDirectPath: { type: String, default: null },
    chatPeople: { type: Array, default: () => [] },
    chatChannelId: { type: [Number, null], default: null },
});

const { t, d } = useI18n();
const { request } = useRequest();

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

const events = computed(() =>
    items.value
        .filter((item) => item.scheduledAt)
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
        })),
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
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col gap-5 p-4 sm:p-8">
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
            <p class="text-xs text-muted">
                {{ t("studio.public.space.timezone_notice", { timezone: space.timezone }) }}
            </p>
        </div>

        <CalendarMonth :cells="cells" :events="events" v-on:open-event="open" />

        <!-- Under the month rather than beside it, and never a floating
             bubble: this page is read on a phone as often as on a desk, and a
             widget pinned over a calendar covers the thing the client came
             for. The conversation is the second reason they open the page, so
             it sits second. -->
        <SpaceChatPanel
            :messages="chatMessages"
            :stream-url="chatStreamUrl"
            :post-path="chatPostPath"
            :reload-path="chatReloadPath"
            :channels="chatChannels"
            :channel-id="chatChannelId"
            :chat-direct-path="chatDirectPath"
            :people="chatPeople"
            own-side="client"
            :notice="chatPostPath ? t('studio.public.space.chat_notice') : ''"
        />

        <!-- Les fichiers de l'espace, s'il y en a. Sous la discussion parce
             qu'on ne vient pas ici pour eux : ce sont des documents qu'on
             retrouve, pas des nouvelles qu'on lit. Rien n'est affiché quand
             l'espace n'en porte aucun - une section vide sur la page d'un
             client donne l'impression d'un écran inachevé. -->
        <section v-if="spaceFiles.length" class="space-y-3">
            <h2 class="text-sm font-medium text-primary">
                {{ t("studio.public.space.files_title") }}
            </h2>

            <ul class="divide-y divide-line/60 rounded-lg border border-line/60">
                <li
                    v-for="file in spaceFiles"
                    :key="file.id"
                    class="flex items-center gap-3 px-3 py-2.5"
                >
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
                        <p class="truncate text-sm text-primary">{{ file.title }}</p>
                        <p class="text-xs text-muted">{{ d(new Date(file.createdAt), "short") }}</p>
                    </div>

                    <a
                        :href="file.url"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0 rounded-md border border-line/60 px-2.5 py-1 text-xs text-primary transition-colors hover:bg-surface-2"
                    >
                        {{ t("studio.public.space.files_open") }}
                    </a>
                </li>
            </ul>
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
