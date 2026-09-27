<script setup>
/**
 * Le calendrier éditorial : ce qui sort, chez qui, et quand.
 *
 * Tous les espaces que le lecteur voit sur un seul mois, une couleur par
 * espace. Le tableau de bord dit ce qui attend ; cette page planifie.
 *
 * **En lecture seule.** Une carte se déplace dans son espace, là où sont son
 * étape et son fil : ici, un clic y mène. La grille est celle du module
 * Calendrier et de la vue d'un espace, pour qu'un mois se lise partout pareil.
 *
 * Le client et l'état se filtrent sur place ; la portée « mes espaces / tous »
 * recharge la page, parce que c'est le serveur qui sait quels espaces on voit.
 */
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, ExternalLink } from "lucide-vue-next";
import CalendarMonth from "@/shared/components/calendar/CalendarMonth.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { gridWindow, monthGrid, sameDay } from "@/shared/composables/calendar/monthGrid.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

const props = defineProps({
    scope: { type: String, default: "mine" },
    hasScopeChoice: { type: Boolean, default: false },
    spaces: { type: Array, default: () => [] },
    itemsPath: { type: String, required: true },
});

const { t, d } = useI18n();
const { request } = useRequest();
const { container, isNarrow } = useNarrowContainer(560);

/** Les états qu'on filtre, dans l'ordre d'urgence du tableau de bord. */
const STATES = [
    { value: "missed", labelKey: "backend.studio.calendar.states.missed" },
    { value: "late_review", labelKey: "backend.studio.calendar.states.late_review" },
    { value: "changes_requested", labelKey: "backend.studio.calendar.states.changes_requested" },
    { value: "with_client", labelKey: "backend.studio.calendar.states.with_client" },
    { value: "upcoming", labelKey: "backend.studio.calendar.states.upcoming" },
    { value: "published", labelKey: "backend.studio.calendar.states.published" },
];

const params = new URLSearchParams(window.location.search);
const today = new Date();
const year = ref(today.getFullYear());
const month = ref(today.getMonth());
const view = ref("list" === params.get("view") ? "list" : "month");
const customer = ref(params.get("customer") ?? "");
const state = ref(STATES.some((entry) => entry.value === params.get("state")) ? params.get("state") : "");
const selectedDay = ref(new Date());

const items = ref([]);
const loading = ref(false);
const failed = ref(false);

const spacesById = computed(() => new Map(props.spaces.map((space) => [space.id, space])));

const customerOptions = computed(() => [
    { value: "", label: t("backend.studio.calendar.all_customers") },
    ...[...new Set(props.spaces.map((space) => space.customerName))].sort().map((name) => ({ value: name, label: name })),
]);

const stateOptions = computed(() => [
    { value: "", label: t("backend.studio.calendar.all_states") },
    ...STATES.map((entry) => ({ value: entry.value, label: t(entry.labelKey) })),
]);

const visible = computed(() =>
    items.value.filter((item) => {
        const space = spacesById.value.get(item.spaceId);

        if (customer.value && space?.customerName !== customer.value) return false;

        return !state.value || item.states.includes(state.value);
    }),
);

const cells = computed(() => monthGrid(year.value, month.value));
const monthTitle = computed(() => d(new Date(year.value, month.value, 1), { year: "numeric", month: "long" }));

/** La liste : les cartes du mois affiché, jour par jour. */
const byDay = computed(() => {
    const days = new Map();

    for (const item of visible.value) {
        const at = new Date(item.startAt);
        if (at.getMonth() !== month.value || at.getFullYear() !== year.value) continue;

        const key = at.toDateString();
        if (!days.has(key)) days.set(key, { date: at, items: [] });
        days.get(key).items.push(item);
    }

    return [...days.values()];
});

const dayItems = computed(() => visible.value.filter((item) => sameDay(new Date(item.startAt), selectedDay.value)));

async function load() {
    const { from, to } = gridWindow(year.value, month.value);
    const query = new URLSearchParams({ scope: props.scope, from: from.toISOString(), to: to.toISOString() });

    loading.value = true;
    failed.value = false;

    try {
        const data = await request(`${props.itemsPath}?${query}`, null, { method: HttpMethod.Get, noGuard: true });
        items.value = data?.items ?? [];
    } catch {
        failed.value = true;
        items.value = [];
    } finally {
        loading.value = false;
    }
}

function goToMonth(delta) {
    const moved = new Date(year.value, month.value + delta, 1);
    year.value = moved.getFullYear();
    month.value = moved.getMonth();
}

function goToToday() {
    year.value = today.getFullYear();
    month.value = today.getMonth();
}

/** Garde les filtres dans l'adresse, pour qu'un lien du tableau de bord ou un rechargement les retrouve. */
watch([view, customer, state], () => {
    const next = new URLSearchParams(window.location.search);
    for (const [key, value] of [["view", "month" === view.value ? "" : view.value], ["customer", customer.value], ["state", state.value]]) {
        if (value) next.set(key, value);
        else next.delete(key);
    }
    window.history.replaceState(null, "", `?${next.toString()}`);
});

watch([year, month], load);
onMounted(load);

function open(item) {
    if (item?.path) window.location.href = item.path;
}

function scopeHref(scope) {
    const next = new URLSearchParams(window.location.search);
    next.set("scope", scope);

    return `?${next.toString()}`;
}

function spaceName(item) {
    return spacesById.value.get(item.spaceId)?.name ?? "";
}
</script>

<template>
    <div ref="container" class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="flex flex-wrap items-end gap-3">
                <div v-if="hasScopeChoice" class="flex items-center gap-1 text-sm" role="group" :aria-label="t('backend.studio.calendar.scope_label')">
                    <a
                        v-for="option in ['mine', 'all']"
                        :key="option"
                        :href="scopeHref(option)"
                        class="rounded-md px-2.5 py-1 transition-colors"
                        :class="option === scope ? 'bg-surface-2 text-primary font-medium' : 'text-secondary hover:text-primary'"
                        :aria-current="option === scope ? 'true' : undefined"
                    >
                        {{ t(`backend.studio.calendar.scopes.${option}`) }}
                    </a>
                </div>
                <AppSelect v-model="customer" class="w-full sm:w-56" :label="t('backend.studio.calendar.customer')" :options="customerOptions" />
                <AppSelect v-model="state" class="w-full sm:w-56" :label="t('backend.studio.calendar.state')" :options="stateOptions" />
            </div>
            <div class="flex items-center gap-1 text-sm" role="group" :aria-label="t('backend.studio.calendar.view_label')">
                <button
                    v-for="option in ['month', 'list']"
                    :key="option"
                    type="button"
                    class="rounded-md px-2.5 py-1 transition-colors"
                    :class="option === view ? 'bg-surface-2 text-primary font-medium' : 'text-secondary hover:text-primary'"
                    :aria-pressed="option === view"
                    v-on:click="view = option"
                >
                    {{ t(`backend.studio.calendar.views.${option}`) }}
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <button type="button" class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-primary" :aria-label="t('shared.common.previous')" v-on:click="goToMonth(-1)">
                    <ChevronLeft class="h-4 w-4" :stroke-width="2" />
                </button>
                <button type="button" class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-primary" :aria-label="t('shared.common.next')" v-on:click="goToMonth(1)">
                    <ChevronRight class="h-4 w-4" :stroke-width="2" />
                </button>
                <h2 class="ml-2 text-sm font-medium capitalize text-primary">{{ monthTitle }}</h2>
            </div>
            <AppButton variant="ghost" size="sm" v-on:click="goToToday">{{ t("backend.studio.calendar.today") }}</AppButton>
        </div>

        <p v-if="failed" class="text-sm text-red-500">{{ t("backend.studio.calendar.errors.load") }}</p>

        <AppNoData v-if="!spaces.length" :message="t('backend.studio.calendar.no_spaces')" />

        <template v-else-if="'month' === view">
            <CalendarMonth
                :cells="cells"
                :events="visible"
                :compact="isNarrow"
                :selected="isNarrow ? selectedDay : null"
                v-on:open-event="open"
                v-on:select-day="selectedDay = $event"
            />
            <ul v-if="isNarrow" class="divide-y divide-line/60">
                <li v-for="item in dayItems" :key="item.id">
                    <button type="button" class="flex w-full items-center gap-2 py-2 text-left" v-on:click="open(item)">
                        <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ item.title }}</span>
                        <span class="shrink-0 truncate text-xs text-muted">{{ spaceName(item) }}</span>
                    </button>
                </li>
            </ul>
        </template>

        <div v-else class="space-y-4">
            <p v-if="!loading && !byDay.length" class="text-sm text-muted">{{ t("backend.studio.calendar.empty_month") }}</p>
            <section v-for="day in byDay" :key="day.date.toDateString()" class="space-y-1">
                <h3 class="text-xs font-medium uppercase tracking-wide text-secondary">
                    {{ d(day.date, { weekday: "long", day: "numeric", month: "long" }) }}
                </h3>
                <ul class="aurora-card divide-y divide-line/60">
                    <li v-for="item in day.items" :key="item.id">
                        <a :href="item.path" class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:gap-3 hover:bg-surface-2/40">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-primary">{{ item.title }}</span>
                                <span class="block truncate text-xs text-muted">{{ spaceName(item) }} · {{ item.stepName }} · {{ d(new Date(item.startAt), { hour: "2-digit", minute: "2-digit" }) }}</span>
                            </span>
                            <span class="flex flex-wrap items-center gap-1.5">
                                <span
                                    v-for="itemState in item.states"
                                    :key="itemState"
                                    class="rounded-full border border-line px-2 py-0.5 text-xs text-secondary"
                                >
                                    {{ t(`backend.studio.calendar.states.${itemState}`) }}
                                </span>
                                <ExternalLink class="h-3.5 w-3.5 text-muted" :stroke-width="2" />
                            </span>
                        </a>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
