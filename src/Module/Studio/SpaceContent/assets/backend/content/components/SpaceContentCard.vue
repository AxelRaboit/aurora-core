<script setup>
/**
 * One piece of content, as it sits on the board.
 *
 * Shows its title, the first line of its text and its date, and nothing else:
 * a card is scanned in a column of eight, so every extra line costs the reader
 * one card of visible board.
 *
 * A card with no date says so rather than staying silent. "Sans date" is a
 * state somebody acts on - it is the work that has not been scheduled yet - and
 * an empty space reads as a card that failed to load.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarClock, Check, GripVertical, MessageSquare } from "lucide-vue-next";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";

const props = defineProps({
    item: { type: Object, required: true },
    actions: { type: Array, default: () => [] },
});

const { t, d } = useI18n();

const excerpt = computed(() => {
    const body = props.item.body ?? "";
    const firstLine = body.split("\n").find((line) => line.trim() !== "");

    return firstLine ?? "";
});

const when = computed(() => {
    if (!props.item.scheduledAt) return t("backend.studio.space_content.unscheduled");

    return d(new Date(props.item.scheduledAt), "short");
});

/**
 * What the client said, when they said anything.
 *
 * Silence is drawn as nothing at all rather than as a grey "en attente" badge:
 * most cards on a board have never been sent to anybody, and a badge on every
 * one of them would be a column of noise hiding the two that matter.
 */
const answered = computed(() => "pending" !== props.item.approval);
</script>

<template>
    <article
        class="group rounded-lg border border-line/60 bg-surface px-3 py-2.5 shadow-sm transition-colors hover:border-line"
    >
        <div class="flex items-start gap-2">
            <!-- The handle is its own target: a drag that starts on the card
                 body competes with the click that opens it, and one of the two
                 loses at random. -->
            <GripVertical
                class="card-drag-handle mt-0.5 h-3.5 w-3.5 shrink-0 cursor-grab text-muted opacity-0 transition-opacity group-hover:opacity-100"
                :stroke-width="2"
            />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-primary">{{ item.title }}</p>
                <p v-if="excerpt" class="mt-0.5 truncate text-xs text-muted">
                    {{ excerpt }}
                </p>
            </div>
            <AppRowActions :actions="actions" :label="item.title ?? ''" />
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
            <p
                class="flex items-center gap-1 text-xs"
                :class="item.scheduledAt ? 'text-secondary' : 'text-muted'"
            >
                <CalendarClock class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ when }}
            </p>
            <span
                v-if="answered"
                class="flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs"
                :class="
                    item.approval === 'approved'
                        ? 'bg-emerald-500/10 text-emerald-500'
                        : 'bg-amber-500/10 text-amber-500'
                "
                :title="item.approvalNote || undefined"
            >
                <Check
                    v-if="item.approval === 'approved'"
                    class="h-3 w-3 shrink-0"
                    :stroke-width="2"
                />
                <MessageSquare v-else class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t(`backend.studio.space_content.approvals.${item.approval}`) }}
            </span>
        </div>
    </article>
</template>
