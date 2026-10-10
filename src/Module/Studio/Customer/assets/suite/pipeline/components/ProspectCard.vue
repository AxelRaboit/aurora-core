<script setup>
/**
 * One customer on the prospect board.
 *
 * What a card says is what decides the next gesture: who, what it is worth,
 * when to get back to them and whether that is late. Everything else is on
 * their page, one click away.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { BellRing, GripVertical, MessageSquareText } from "lucide-vue-next";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useMoneyFormat } from "@/shared/composables/format/useMoneyFormat.js";
import { followUpTone, dayOf } from "../followUp.js";

const props = defineProps({
    customer: { type: Object, required: true },
    href: { type: String, required: true },
    actions: { type: Array, default: () => [] },
    /** When the latest exchange with them happened, or null. */
    lastInteraction: { type: String, default: null },
    /** Whether the card can be dragged: a client stays in the won stage. */
    draggable: { type: Boolean, default: false },
});

const { t } = useI18n();
const { formatDateShort } = useDateFormat();
const { formatMoney } = useMoneyFormat();

const value = computed(() => formatMoney(props.customer.estimatedValueCents, props.customer.estimatedValueCurrency ?? "EUR"));

const followUp = computed(() => {
    const on = props.customer.nextFollowUpOn;
    if (!on) return null;

    return {
        label: formatDateShort(dayOf(on)),
        tone: followUpTone(props.customer.followUpState),
        state: props.customer.followUpState,
    };
});
</script>

<template>
    <article class="aurora-card group px-3 py-2.5 transition-colors hover:border-line-strong" :data-prospect-card="customer.id">
        <div class="flex items-start gap-2">
            <!-- The handle is its own target: a drag that starts on the card
                 body competes with the link to the page. -->
            <GripVertical
                v-if="draggable"
                class="card-drag-handle mt-0.5 h-3.5 w-3.5 shrink-0 cursor-grab text-muted opacity-0 transition-opacity group-hover:opacity-100 touch:opacity-100"
                :stroke-width="2"
                :aria-label="t('suite.studio.pipeline.move_card')"
            />
            <div class="min-w-0 flex-1">
                <a :href="href" class="block truncate text-sm font-medium text-primary hover:underline">{{ customer.legalName }}</a>
                <p v-if="value" class="m-0 mt-0.5 text-xs font-medium tabular-nums text-secondary">{{ value }}</p>
            </div>
            <AppRowActions :actions="actions" :label="customer.legalName" />
        </div>

        <div v-if="followUp || lastInteraction || customer.lostReason" class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
            <!-- The follow-up first: it is what the board is scanned for. -->
            <span
                v-if="followUp"
                class="flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs"
                :class="followUp.tone"
                :title="customer.followUpNote || undefined"
                data-follow-up
                :data-follow-up-state="followUp.state"
            >
                <BellRing class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t(`suite.studio.pipeline.follow_up.states.${followUp.state}`, { date: followUp.label }) }}
            </span>
            <span v-if="lastInteraction" class="flex items-center gap-1 text-xs text-muted" :title="t('suite.studio.pipeline.last_interaction')">
                <MessageSquareText class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ formatDateShort(lastInteraction) }}
            </span>
            <p v-if="customer.lostReason" class="m-0 w-full truncate text-xs italic text-muted" :title="customer.lostReason">{{ customer.lostReason }}</p>
        </div>
    </article>
</template>
