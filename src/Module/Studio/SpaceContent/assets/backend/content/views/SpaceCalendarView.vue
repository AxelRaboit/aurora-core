<script setup>
/**
 * The content as a month, read by its date.
 *
 * It draws the shared month grid, the same component the Calendrier module
 * uses, rather than a second one: that component decides nothing about dates -
 * which days show, which bars cross which weeks, how two overlapping runs avoid
 * each other are all in `monthGrid.js`, tested without a component mounted.
 *
 * The rail on the right is the half a calendar usually hides: the content with
 * no date. It is the work waiting to be scheduled, and a month showing only the
 * scheduled half would say a week is empty when there are six ideas to place.
 *
 * Which month is on screen is the only state that belongs to this view alone.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import CalendarMonth from "@/shared/components/calendar/CalendarMonth.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { CalendarPlus, ChevronLeft, ChevronRight } from "lucide-vue-next";

const props = defineProps({
    events: { type: Array, default: () => [] },
    unscheduled: { type: Array, default: () => [] },
    columnsById: { type: Map, default: () => new Map() },
    cellsFor: { type: Function, required: true },
});

const emit = defineEmits(["open-event", "move-event", "add-on", "open-item"]);

const { t, d } = useI18n();

const today = new Date();
const year = ref(today.getFullYear());
const month = ref(today.getMonth());

/**
 * Une grille de mois ne tient pas sur un téléphone, et c'est mesurable.
 *
 * Sept colonnes dans trois cent soixante-quinze pixels font des cases de
 * cinquante : la place d'un numéro de jour, pas celle d'un titre. Sous le seuil,
 * la grille devient un index à pastilles et le contenu passe dans une liste en
 * dessous - ce que font Google et Apple, pour la même raison arithmétique.
 *
 * Le conteneur et jamais la fenêtre : c'est la colonne qui tient le calendrier
 * qui décide, et elle est plus étroite que l'écran dès qu'un rail l'accompagne.
 */
const { container, isNarrow } = useNarrowContainer(560);

/** Le jour que la liste montre. Aujourd'hui tant que personne n'en a choisi un. */
const selectedDay = ref(new Date());

function sameDay(a, b) {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

/**
 * Les publications du jour choisi, dans l'ordre de la journée.
 *
 * Écrite ici plutôt qu'empruntée au module Calendrier : celui-ci a bien une
 * liste de jour, mais elle parle d'événements et de rappels, et surtout elle
 * appartient à un module qui s'installe séparément. Une trentaine de lignes
 * valent mieux qu'un couplage qui casserait la vue d'un espace le jour où le
 * calendrier n'est pas là.
 */
const dayItems = computed(() =>
    props.events
        .filter((event) => sameDay(new Date(event.startAt), selectedDay.value))
        .sort((a, b) => new Date(a.startAt) - new Date(b.startAt)),
);

const dayTitle = computed(() =>
    d(selectedDay.value, { weekday: "long", day: "numeric", month: "long" }),
);

const cells = computed(() => props.cellsFor(year.value, month.value));

const monthTitle = computed(() =>
    d(new Date(year.value, month.value, 1), { year: "numeric", month: "long" }),
);

function goToMonth(delta) {
    const moved = new Date(year.value, month.value + delta, 1);
    year.value = moved.getFullYear();
    month.value = moved.getMonth();
}

function goToToday() {
    const now = new Date();
    year.value = now.getFullYear();
    month.value = now.getMonth();
}
</script>

<template>
    <div class="space-y-4">
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
                <h3 class="ml-2 text-sm font-medium capitalize text-primary">
                    {{ monthTitle }}
                </h3>
            </div>
            <AppButton variant="ghost" size="sm" v-on:click="goToToday">
                {{ t("backend.studio.space_content.today") }}
            </AppButton>
        </div>

        <div ref="container" class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <div class="min-w-0 flex-1 space-y-3">
                <CalendarMonth
                    :cells="cells"
                    :events="events"
                    :compact="isNarrow"
                    :selected="isNarrow ? selectedDay : null"
                    v-on:open-event="emit('open-event', $event)"
                    v-on:move-event="emit('move-event', $event)"
                    v-on:add-on="emit('add-on', $event)"
                    v-on:select-day="selectedDay = $event"
                />

                <!-- La grille dit quels jours portent quelque chose ; celle-ci
                     dit quoi. L'une sans l'autre est illisible sur un
                     téléphone. -->
                <section v-if="isNarrow" class="rounded-xl border border-line/60 bg-surface">
                    <header class="flex items-baseline gap-2 border-b border-line/40 px-3 py-2">
                        <h3 class="text-sm font-medium capitalize text-primary">
                            {{ dayTitle }}
                        </h3>
                        <span class="text-xs tabular-nums text-muted">
                            {{ dayItems.length }}
                        </span>
                    </header>

                    <p v-if="!dayItems.length" class="px-3 py-3 text-xs text-muted">
                        {{ t("backend.studio.space_content.calendar_day_empty") }}
                    </p>

                    <ul v-else class="divide-y divide-line/40">
                        <li v-for="event in dayItems" :key="event.id">
                            <button
                                type="button"
                                class="flex w-full items-baseline gap-2 px-3 py-2 text-left transition-colors hover:bg-surface-2/60"
                                v-on:click="emit('open-event', event)"
                            >
                                <span class="shrink-0 text-xs tabular-nums text-muted">
                                    {{ d(new Date(event.startAt), { hour: "2-digit", minute: "2-digit" }) }}
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm text-primary">
                                    {{ event.title }}
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Beside the grid and not under it: the two are read together,
                 one card at a time being taken out of the list into a week. -->
            <aside class="w-full shrink-0 rounded-xl border border-line/60 bg-surface-2/40 lg:w-72">
                <header class="flex items-center gap-2 border-b border-line/40 px-3 py-2">
                    <h3 class="min-w-0 flex-1 truncate text-sm font-medium text-primary">
                        {{ t("backend.studio.space_content.unscheduled_rail") }}
                    </h3>
                    <span class="text-xs tabular-nums text-muted">
                        {{ unscheduled.length }}
                    </span>
                </header>

                <p v-if="!unscheduled.length" class="px-3 py-3 text-xs text-muted">
                    {{ t("backend.studio.space_content.unscheduled_rail_empty") }}
                </p>

                <ul v-else class="flex flex-col gap-2 p-2">
                    <li v-for="item in unscheduled" :key="item.id">
                        <button
                            type="button"
                            class="w-full rounded-lg border border-line/60 bg-surface px-3 py-2 text-left transition-colors hover:border-line"
                            v-on:click="emit('open-item', item)"
                        >
                            <p class="truncate text-sm font-medium text-primary">
                                {{ item.title }}
                            </p>
                            <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-muted">
                                <CalendarPlus class="h-3 w-3 shrink-0" :stroke-width="2" />
                                {{ columnsById.get(item.columnId)?.name }}
                            </p>
                        </button>
                    </li>
                </ul>
            </aside>
        </div>
    </div>
</template>
