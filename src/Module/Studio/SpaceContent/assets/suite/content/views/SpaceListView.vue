<script setup>
/**
 * The content as a list, grouped by step.
 *
 * **The view for somebody who does not think in columns.** A board answers
 * "what is where"; a list answers "what is there", which is the question when
 * the month is full and the board is four screens wide. It is also the only one
 * of the three that fits on a phone without scrolling sideways.
 *
 * Grouped rather than flat, because the step is what orders the work - a flat
 * list sorted by date puts an idea nobody has written next to a post going out
 * tomorrow.
 */
import { useI18n } from "vue-i18n";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { CalendarClock, Check, MessageSquare, Plus } from "lucide-vue-next";
import SpaceReviewDeadline from "../components/SpaceReviewDeadline.vue";

const props = defineProps({
    grouped: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    actionsFor: { type: Function, required: true },
    filesOf: { type: Function, required: true },
    isEmpty: { type: Boolean, default: false },
});

const emit = defineEmits(["add-item", "open-item"]);

const { t, d: formatDate } = useI18n();

/**
 * The first file on a card that has a picture to show, or nothing.
 *
 * A prop rather than a computed because the row is inside a `v-for`: the
 * lookup has to be per card, and `filesOf` is the same one the board reads, so
 * the two views cannot disagree about what is on a piece of content.
 */
function previewOf(card) {
    return props.filesOf(card).find((file) => file.preview)?.preview ?? null;
}

function when(item) {
    if (!item.scheduledAt) return t("suite.studio.space_content.unscheduled");

    return formatDate(new Date(item.scheduledAt), "short");
}
</script>

<template>
    <div class="aurora-stack">
        <AppNoData
            v-if="isEmpty"
            :message="t('suite.studio.space_content.empty_board')"
        />

        <section v-for="group in grouped" :key="group.column.id" class="space-y-2">
            <header class="flex items-center gap-2">
                <span
                    v-if="group.column.colourSlot"
                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                    :style="{
                        backgroundColor: `var(--chart-cat-${group.column.colourSlot})`,
                    }"
                />
                <h3 class="text-sm font-medium text-primary">{{ group.column.name }}</h3>
                <span class="text-xs tabular-nums text-muted">
                    {{ group.cards.length }}
                </span>
                <!-- **The plus alone on a phone.** "Ajouter un contenu" is a
                     hundred and forty pixels wide facing a column name that
                     can be as wide: both wrapped onto three lines for a
                     button whose sign already says everything, where people
                     expect it. The label comes back as soon as there is room,
                     and stays readable by a screen reader in the meantime. -->
                <button
                    v-if="editable"
                    type="button"
                    class="ml-auto flex shrink-0 items-center gap-1 rounded-md px-2 py-2 text-xs text-muted transition-colors hover:bg-surface-2 hover:text-primary sm:py-1"
                    :title="t('suite.studio.space_content.add_item')"
                    v-on:click="emit('add-item', group.column.id)"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                    <span class="sr-only sm:not-sr-only">
                        {{ t("suite.studio.space_content.add_item") }}
                    </span>
                </button>
            </header>

            <p v-if="!group.cards.length" class="text-xs text-muted">
                {{ t("suite.studio.space_content.empty_column") }}
            </p>

            <ul v-else class="aurora-card divide-y divide-line/40">
                <li
                    v-for="card in group.cards"
                    :key="card.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5"
                >
                    <!-- The same square the board draws, for the same reason:
                         somebody planning a month recognises a post by its
                         picture before they read its title. Inside the button
                         so the whole row stays one target on a phone, which is
                         the reader this view exists for. -->
                    <button
                        type="button"
                        class="order-1 flex min-w-0 flex-1 items-center gap-3 text-left"
                        v-on:click="emit('open-item', card)"
                    >
                        <img
                            v-if="previewOf(card)"
                            :src="previewOf(card)"
                            alt=""
                            class="h-8 w-8 shrink-0 rounded object-cover"
                            loading="lazy"
                        >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-primary">
                                {{ card.title }}
                            </span>
                            <span v-if="card.body" class="block truncate text-xs text-muted">
                                {{ card.body }}
                            </span>
                        </span>
                    </button>

                    <!-- **Under the title on a phone, beside it elsewhere.** The
                         date and the badge keep their width whatever happens, so
                         on a narrow screen the title paid for it: "Le
                         témoignage de Mme Lefèvre" became "Témoignag…". Moved
                         down to their own line, they give the title back the
                         card's full width.

                         At the left edge and not under the title: an offset
                         aligned on the thumbnail only works for cards that
                         have one, and those without saw their date drift off
                         alone towards the middle. -->
                    <div class="order-3 flex w-full items-center gap-3 sm:w-auto">
                        <span
                            v-if="card.approval !== 'pending'"
                            class="flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 text-xs"
                            :class="
                                card.approval === 'approved'
                                    ? 'bg-emerald-500/10 text-emerald-500'
                                    : 'bg-amber-500/10 text-amber-500'
                            "
                        >
                            <Check
                                v-if="card.approval === 'approved'"
                                class="h-3 w-3 shrink-0"
                                :stroke-width="2"
                            />
                            <MessageSquare v-else class="h-3 w-3 shrink-0" :stroke-width="2" />
                            {{ t(`suite.studio.space_content.approvals.${card.approval}`) }}
                        </span>

                        <span
                            class="flex shrink-0 items-center gap-1 text-xs tabular-nums"
                            :class="card.scheduledAt ? 'text-secondary' : 'text-muted'"
                        >
                            <CalendarClock class="h-3 w-3 shrink-0" :stroke-width="2" />
                            {{ when(card) }}
                        </span>
                        <SpaceReviewDeadline :item="card" />
                    </div>

                    <AppRowActions
                        class="order-2 sm:order-4"
                        :actions="actionsFor(card)"
                        :label="card.title ?? ''"
                    />
                </li>
            </ul>
        </section>
    </div>
</template>
