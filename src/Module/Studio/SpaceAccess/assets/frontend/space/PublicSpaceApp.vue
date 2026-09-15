<script setup>
/**
 * A client's own content plan, read from a secret address.
 *
 * **The same month grid the studio sees**, not a second rendering of it: the
 * component is shared, so what a client is shown is what their agency is
 * looking at, and the two cannot drift into disagreeing about which Tuesday a
 * post lands on.
 *
 * Read-only, with nothing on the page that could write. It carries no
 * endpoint addresses at all - a screen with no writes has no business holding
 * the URLs of six of them - so there is nothing here for a template mistake to
 * call.
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
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
// Same module, another sub-domain: a relative path rather than an alias.
// The rule that forbids reaching across modules is about modules, and this
// component is the one thing the two surfaces genuinely share.
import SpaceContentThread from "../../../../SpaceContent/assets/shared/SpaceContentThread.vue";
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
});

const { t, d } = useI18n();
const { request } = useRequest();

// The rows are replaced by what the server sends back after an answer, so the
// page never has to work out what its own write did.
const items = ref(props.items ?? []);
const comments = ref(props.comments ?? {});

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

const note = ref("");
const answering = ref("");
const posting = ref(false);

const thread = computed(() =>
    openItem.value ? (comments.value[openItem.value.id] ?? []) : [],
);

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
 * The note travels with the verdict rather than after it: "à revoir" is only
 * actionable with a reason, and asking for it in a second step is asking
 * somebody who has already clicked to come back.
 */
async function answer(approval) {
    if (!props.answerPath || !openItem.value) return;

    answering.value = approval;
    try {
        const data = await request(
            buildPath(props.answerPath, { id: openItem.value.id }),
            { approval, note: note.value },
        );

        if (!data?.success) return;

        if (Array.isArray(data.items)) items.value = data.items;
        if (data.comments) comments.value = data.comments;
        toast.success(t("studio.public.space.answer_recorded"));
        openItem.value = null;
        note.value = "";
    } finally {
        answering.value = "";
    }
}

function open(event) {
    openItem.value = itemsById.value.get(event.id) ?? null;
    note.value = "";
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

        <footer class="mt-auto border-t border-line/50 pt-3 text-xs text-muted">
            <p v-if="expiresAt">
                {{ t("studio.public.space.valid_until", { date: d(new Date(expiresAt), "long") }) }}
            </p>
            <p>{{ t("studio.public.space.footer") }}</p>
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

            <!-- What was already answered, shown before anything is asked: a
                 reader coming back should see what they said rather than be
                 asked again. -->
            <p
                v-if="openItem && openItem.approval !== 'pending'"
                class="mt-4 rounded-lg bg-surface-2 px-3 py-2 text-xs text-secondary"
            >
                {{
                    t(
                        openItem.approval === "approved"
                            ? "studio.public.space.already_approved"
                            : "studio.public.space.already_changes_requested",
                        { date: d(new Date(openItem.approvalAt), "short") },
                    )
                }}
            </p>

            <!-- The same thread the studio reads, in the same component:
                 one conversation, not two renderings of it. -->
            <div v-if="openItem" class="mt-4 border-t border-line/50 pt-4">
                <SpaceContentThread
                    :comments="thread"
                    :can-post="canComment"
                    :loading="posting"
                    :notice="t('studio.public.space.thread_notice')"
                    v-on:post="postComment"
                />
            </div>

            <section v-if="canApprove" class="mt-4 space-y-3 border-t border-line/50 pt-4">
                <AppTextarea
                    :model-value="note"
                    :label="t('studio.public.space.note')"
                    :placeholder="t('studio.public.space.note_placeholder')"
                    :hint="t('studio.public.space.note_hint')"
                    :rows="3"
                    v-on:update:model-value="note = $event"
                />
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
