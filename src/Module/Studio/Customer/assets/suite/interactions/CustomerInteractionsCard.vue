<script setup>
/**
 * The history of exchanges with a customer, newest first.
 *
 * Read before calling them back: what was said last, by whom, and when. Kept
 * as plain text the way it was typed - a call summary is not a document.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarDays, Mail, MessageCircle, Pencil, Phone, Plus, StickyNote, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const props = defineProps({
    interactions: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
});

const emit = defineEmits(["add", "edit", "delete"]);

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const ICONS = {
    call: Phone,
    email: Mail,
    meeting: CalendarDays,
    message: MessageCircle,
    note: StickyNote,
};

const kindLabels = computed(() => new Map(props.kinds.map((kind) => [kind.value, t(kind.labelKey)])));

function actionsFor(interaction) {
    if (!props.editable) return [];

    return [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("shared.common.edit"),
            onSelect: () => emit("edit", interaction),
        },
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            onSelect: () => emit("delete", interaction),
        },
    ];
}
</script>

<template>
    <article class="aurora-card space-y-4 p-4 sm:p-5" data-customer-interactions>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 space-y-0.5">
                <h3 class="m-0 text-[0.9375rem] font-semibold text-primary">{{ t("suite.studio.customer_interactions.title") }}</h3>
                <p class="m-0 text-[0.8125rem] text-secondary">{{ t("suite.studio.customer_interactions.hint") }}</p>
            </div>
            <AppButton
                v-if="editable"
                variant="ghost"
                size="sm"
                :label="t('suite.studio.customer_interactions.add')"
                data-interaction-add
                v-on:click="emit('add')"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppButton>
        </div>

        <AppNoData v-if="!interactions.length" :message="t('suite.studio.customer_interactions.empty')" />

        <ol v-else class="m-0 flex list-none flex-col gap-0 p-0">
            <li
                v-for="interaction in interactions"
                :key="interaction.id"
                class="relative flex gap-3 border-l border-line pb-4 pl-4 last:pb-0"
                :data-interaction="interaction.kind"
            >
                <span class="absolute -left-3 top-0 flex h-6 w-6 items-center justify-center rounded-full border border-line bg-surface text-secondary">
                    <component :is="ICONS[interaction.kind] ?? StickyNote" class="h-3 w-3" :stroke-width="2" />
                </span>
                <div class="min-w-0 flex-1 pl-1">
                    <p class="m-0 flex flex-wrap items-baseline gap-x-2 text-xs text-muted">
                        <span class="font-medium text-secondary">{{ kindLabels.get(interaction.kind) }}</span>
                        <span>{{ formatDateTime(interaction.occurredAt) }}</span>
                        <span v-if="interaction.authorLabel">· {{ interaction.authorLabel }}</span>
                    </p>
                    <p class="m-0 mt-1 whitespace-pre-line break-words text-sm text-primary">{{ interaction.summary }}</p>
                </div>
                <AppRowActions
                    v-if="editable"
                    class="shrink-0"
                    :actions="actionsFor(interaction)"
                    :label="kindLabels.get(interaction.kind) ?? ''"
                />
            </li>
        </ol>
    </article>
</template>
