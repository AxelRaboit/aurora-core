<script setup>
/**
 * The editorial calendar: what goes out, for whom, and when.
 *
 * Every space the reader can see on a single month, one color per space. The
 * dashboard says what is waiting; this page plans.
 *
 * **Read-only.** A card is moved in its space, where its step and its thread
 * are: here, a click leads there. The grid is the one of the Calendar module
 * and of a space's view, so that a month reads the same everywhere.
 *
 * Customer and state are filtered in place; the "my spaces / all" scope
 * reloads the page, because the server is what knows which spaces one sees.
 */
import StudioSectionTabs from "../../../../assets/suite/components/StudioSectionTabs.vue";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, ExternalLink } from "lucide-vue-next";
import CalendarMonth from "@/shared/components/calendar/CalendarMonth.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { gridWindow, monthGrid, sameDay } from "@/shared/composables/calendar/monthGrid.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";

const props = defineProps({
    /** The two tabs of the "Espaces clients" entry: the list and the calendar. */
    spacesPath: { type: String, default: "" },
    calendarPath: { type: String, default: "" },
    scope: { type: String, default: "mine" },
    hasScopeChoice: { type: Boolean, default: false },
    spaces: { type: Array, default: () => [] },
    itemsPath: { type: String, required: true },
});

const { t, d: formatDate } = useI18n();
const { request } = useRequest();
const { container, isNarrow } = useNarrowContainer(560);

/** The states one filters on, in the dashboard's order of urgency. */
const STATES = [
    { value: "missed", labelKey: "suite.studio.calendar.states.missed" },
    { value: "late_review", labelKey: "suite.studio.calendar.states.late_review" },
    { value: "changes_requested", labelKey: "suite.studio.calendar.states.changes_requested" },
    { value: "with_client", labelKey: "suite.studio.calendar.states.with_client" },
    { value: "upcoming", labelKey: "suite.studio.calendar.states.upcoming" },
    { value: "published", labelKey: "suite.studio.calendar.states.published" },
];

const searchParameters = new URLSearchParams(window.location.search);
const today = new Date();
const year = ref(today.getFullYear());
const month = ref(today.getMonth());
const view = ref("list" === searchParameters.get("view") ? "list" : "month");
const customer = ref(searchParameters.get("customer") ?? "");
const state = ref(STATES.some((entry) => entry.value === searchParameters.get("state")) ? searchParameters.get("state") : "");
const selectedDay = ref(new Date());

const items = ref([]);
const loading = ref(false);
const failed = ref(false);

const spacesById = computed(() => new Map(props.spaces.map((space) => [space.id, space])));

/** No "all" option: the placeholder carries it, as on the other lists. */
const customerOptions = computed(() =>
    [...new Set(props.spaces.map((space) => space.customerName))].sort().map((name) => ({ value: name, label: name })),
);

const stateOptions = computed(() => STATES.map((entry) => ({ value: entry.value, label: t(entry.labelKey) })));

const VIEWS = ["month", "list"];

const search = ref("");

const visible = computed(() =>
    items.value.filter((item) => {
        const space = spacesById.value.get(item.spaceId);

        if (customer.value && space?.customerName !== customer.value) return false;
        if (state.value && !item.states.includes(state.value)) return false;

        const needle = search.value.trim().toLowerCase();

        return !needle || item.title.toLowerCase().includes(needle) || (space?.name ?? "").toLowerCase().includes(needle);
    }),
);

const cells = computed(() => monthGrid(year.value, month.value));
const monthTitle = computed(() => formatDate(new Date(year.value, month.value, 1), { year: "numeric", month: "long" }));

/**
 * A state requested as a list: all its cards, across all months, dated or
 * not. That is what a dashboard tile opens - "3 parutions manquées" names
 * cards from past months, which the displayed month hid.
 */
const acrossMonths = computed(() => "list" === view.value && "" !== state.value);

/** The list: the displayed month's cards day by day, or every month's for a state. */
const byDay = computed(() => {
    const days = new Map();
    const undated = [];

    for (const item of visible.value) {
        if (!item.startAt) {
            undated.push(item);
            continue;
        }

        const at = new Date(item.startAt);
        if (!acrossMonths.value && (at.getMonth() !== month.value || at.getFullYear() !== year.value)) continue;

        const key = at.toDateString();
        if (!days.has(key)) days.set(key, { key, date: at, items: [] });
        days.get(key).items.push(item);
    }

    const groups = [...days.values()].sort((left, right) => left.date - right.date);

    return undated.length ? [...groups, { key: "undated", date: null, items: undated }] : groups;
});

const dayItems = computed(() => visible.value.filter((item) => sameDay(new Date(item.startAt), selectedDay.value)));

async function load() {
    const { from, to } = gridWindow(year.value, month.value);
    const query = acrossMonths.value
        ? new URLSearchParams({ scope: props.scope, state: state.value })
        : new URLSearchParams({ scope: props.scope, from: from.toISOString(), to: to.toISOString() });

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

/** Keeps the filters in the address, so a dashboard link or a reload finds them again. */
watch([view, customer, state], () => {
    const next = new URLSearchParams(window.location.search);
    for (const [key, value] of [["view", "month" === view.value ? "" : view.value], ["customer", customer.value], ["state", state.value]]) {
        if (value) next.set(key, value);
        else next.delete(key);
    }
    window.history.replaceState(null, "", `?${next.toString()}`);
});

watch([year, month, acrossMonths], load);
watch(state, () => {
    if (acrossMonths.value) load();
});
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
    <div ref="container" class="relative aurora-stack">
        <AppLoader :active="loading" />
        <AppListToolbar :title="t('suite.nav.studio_spaces')" :subtitle="t('suite.nav.studio_spaces_description')">
            <template #above>
                <StudioSectionTabs
                    current="calendar"
                    :tabs="[
                        { key: 'spaces', label: t('suite.studio.spaces.tab_list'), path: spacesPath },
                        { key: 'calendar', label: t('suite.studio.spaces.tab_calendar'), path: calendarPath },
                    ]"
                    :label="t('suite.studio.spaces.tabs_label')"
                />

                <!-- The scope tabs, like those of the space list: the scope changes
                     the spaces counted, so the page reloads. -->
                <div
                    v-if="hasScopeChoice"
                    class="flex w-fit items-center gap-0.5 aurora-segmented"
                    role="group"
                    :aria-label="t('suite.studio.calendar.scope_label')"
                >
                    <a
                        v-for="option in ['mine', 'all']"
                        :key="option"
                        :href="scopeHref(option)"
                        class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm transition-colors"
                        :class="option === scope ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
                        :aria-current="option === scope ? 'true' : undefined"
                    >
                        {{ t(`suite.studio.calendar.scopes.${option}`) }}
                    </a>
                </div>

                <!-- The list bar: the search, and the filters next to it rather than
                     as labelled fields on a separate line. -->
            </template>
            <AppSearchInput v-model="search" :placeholder="t('suite.studio.calendar.search_placeholder')" />
            <template #inline>
                <AppSelect v-model="customer" :options="customerOptions" :placeholder="t('suite.studio.calendar.all_customers')" />
                <AppSelect v-model="state" :options="stateOptions" :placeholder="t('suite.studio.calendar.all_states')" />
            </template>
        </AppListToolbar>
        <!-- The screen's how-to guide, next to what it explains; collapsed
             or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.calendar.guide.title')" storage-key="studio-calendar">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.calendar.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- The Calendar module bar, identical: a month is browsed the same
             way everywhere. -->
        <div class="flex flex-wrap items-center gap-2">
            <template v-if="!acrossMonths">
                <AppIconButton :title="t('shared.common.previous')" v-on:click="goToMonth(-1)">
                    <ChevronLeft class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton :title="t('shared.common.next')" v-on:click="goToMonth(1)">
                    <ChevronRight class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <h2 class="min-w-0 truncate text-sm font-semibold text-primary first-letter:uppercase sm:text-base">
                    {{ monthTitle }}
                </h2>
            </template>
            <h2 v-else class="min-w-0 truncate text-sm font-semibold text-primary sm:text-base">
                {{ t("suite.studio.calendar.all_months") }}
            </h2>

            <div class="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                <div class="flex flex-1 items-center gap-0.5 aurora-segmented sm:flex-none" role="group" :aria-label="t('suite.studio.calendar.view_label')">
                    <button
                        v-for="option in VIEWS"
                        :key="option"
                        type="button"
                        class="flex-1 cursor-pointer rounded-md px-2.5 py-1 text-sm transition-colors min-h-7.5 sm:min-h-0 sm:flex-none"
                        :class="view === option ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
                        :aria-pressed="view === option"
                        v-on:click="view = option"
                    >
                        {{ t(`suite.studio.calendar.views.${option}`) }}
                    </button>
                </div>
                <AppButton variant="ghost" size="sm" v-on:click="goToToday">
                    {{ t("suite.studio.calendar.today") }}
                </AppButton>
            </div>
        </div>

        <p v-if="failed" class="text-sm text-red-500">{{ t("suite.studio.calendar.errors.load") }}</p>

        <AppNoData v-if="!spaces.length" :message="t('suite.studio.calendar.no_spaces')" />

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
            <AppNoData v-if="!loading && !byDay.length" :message="t(acrossMonths ? 'suite.studio.calendar.empty_state' : 'suite.studio.calendar.empty_month')" />
            <section v-for="day in byDay" :key="day.key" class="space-y-1">
                <h3 class="text-xs font-medium uppercase tracking-wide text-secondary">
                    {{ day.date ? formatDate(day.date, acrossMonths ? { weekday: "long", day: "numeric", month: "long", year: "numeric" } : { weekday: "long", day: "numeric", month: "long" }) : t("suite.studio.calendar.undated") }}
                </h3>
                <ul class="aurora-card divide-y divide-line/60">
                    <li v-for="item in day.items" :key="item.id">
                        <a :href="item.path" class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:gap-3 hover:bg-surface-2/40">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-primary">{{ item.title }}</span>
                                <span class="block truncate text-xs text-muted">
                                    {{ spaceName(item) }} · {{ item.stepName }}<template v-if="item.startAt"> · {{ formatDate(new Date(item.startAt), { hour: "2-digit", minute: "2-digit" }) }}</template>
                                </span>
                            </span>
                            <span class="flex flex-wrap items-center gap-1.5">
                                <span
                                    v-for="itemState in item.states"
                                    :key="itemState"
                                    class="rounded-full border border-line px-2 py-0.5 text-xs text-secondary"
                                >
                                    {{ t(`suite.studio.calendar.states.${itemState}`) }}
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
