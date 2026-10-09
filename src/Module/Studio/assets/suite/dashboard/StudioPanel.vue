<script setup>
/**
 * The Studio panel: what is waiting for me at my clients' today.
 *
 * Same shape as the other panels, deliberately: a row of numbers then the
 * detail. A dashboard where each tab invents its own layout forces people to
 * learn it again every time.
 *
 * **Every number leads somewhere**, and the list names the spaces. A count
 * with no destination forces opening the spaces one by one to find the one
 * that is waiting; one row per space, the most urgent at the top, says whose
 * place to go to.
 *
 * The numbers come from `SpaceWorkload`, like those of the space and the
 * editorial calendar: the same word counts the same thing everywhere.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { AlarmClock, CalendarClock, CalendarX, FileSignature,
         PenLine, MessageSquareWarning, NotebookText, UserRoundCheck } from "lucide-vue-next";
import AppSectionCard from "@/shared/components/display/AppSectionCard.vue";
import AppStatTile from "@/shared/components/display/AppStatTile.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import SpaceWorkloadBadges from "../../../SpaceContent/assets/shared/SpaceWorkloadBadges.vue";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { formatDate } = useDateFormat();

/** The editorial calendar, filtered on a state, for a tile that leads there. */
function calendarFor(state) {
    const path = props.stats.calendarPath;

    // As a list: for a state, the list shows all its cards, from past months
    // as well as undated, where the displayed month hid part of them.
    return path ? `${path}?scope=${props.stats.scope ?? "mine"}&view=list&state=${state}` : null;
}

const tiles = computed(() =>
    [
        { key: "missed", icon: CalendarX, value: props.stats.missed ?? 0, href: calendarFor("missed"), urgent: true },
        { key: "late_review", icon: AlarmClock, value: props.stats.lateReview ?? 0, href: calendarFor("late_review"), urgent: true },
        { key: "changes_requested", icon: MessageSquareWarning, value: props.stats.changesRequested ?? 0, href: calendarFor("changes_requested"), urgent: true },
        { key: "with_client", icon: UserRoundCheck, value: props.stats.withClient ?? 0, href: calendarFor("with_client") },
        { key: "upcoming", icon: CalendarClock, value: props.stats.upcoming ?? 0, href: calendarFor("upcoming") },
        // What is waiting on my gesture, urgently, before what is waiting on the client.
        { key: "awaiting_countersignature", icon: PenLine, value: props.stats.awaitingCountersignature, href: props.stats.contractsToCountersignPath, urgent: true },
        { key: "awaiting_signature", icon: FileSignature, value: props.stats.awaitingSignature, href: props.stats.contractsWithCustomerPath },
        { key: "deliverables", icon: NotebookText, value: props.stats.deliverables, href: props.stats.deliverablesPath },
    ]
        // Null: a number this reader has no right to open.
        .filter((tile) => null !== tile.value && undefined !== tile.value)
        .map((tile) => ({ ...tile, tone: tile.urgent && tile.value > 0 ? "attention" : "default" })),
);

/** The same address, the other scope: the panel is recomputed on the server. */
function scopeHref(scope) {
    const searchParameters = new URLSearchParams(window.location.search);
    searchParameters.set("module", "studio");
    searchParameters.set("studioScope", scope);

    return `?${searchParameters.toString()}`;
}
</script>

<template>
    <div class="aurora-stack">
        <!-- The same scope tabs as the spaces list and the editorial
             calendar. -->
        <div
            v-if="stats.hasScopeChoice"
            class="flex w-fit items-center gap-1 rounded-xl bg-surface-2 p-1"
            role="group"
            :aria-label="t('suite.stats.studio.scope_label')"
        >
            <a
                v-for="scope in ['mine', 'all']"
                :key="scope"
                :href="scopeHref(scope)"
                class="flex items-center gap-1.5 rounded-lg px-3 py-1 text-sm font-medium transition-colors"
                :class="scope === stats.scope ? 'bg-surface text-primary shadow-sm' : 'text-secondary hover:text-primary'"
                :aria-current="scope === stats.scope ? 'true' : undefined"
            >
                {{ t(`suite.stats.studio.scopes.${scope}`) }}
            </a>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <AppStatTile
                v-for="tile in tiles"
                :key="tile.key"
                :href="tile.href ?? ''"
                :icon="tile.icon"
                :label="t(`suite.stats.studio.${tile.key}`)"
                :value="tile.value"
                :tone="tile.tone"
            />
        </div>

        <AppSectionCard :title="t('suite.stats.studio.attention_title')">
            <p v-if="!(stats.attention ?? []).length" class="text-sm text-secondary">
                {{ t("suite.stats.studio.attention_empty") }}
            </p>

            <ul v-else class="divide-y divide-line/60">
                <li v-for="space in stats.attention" :key="space.id">
                    <a
                        :href="space.path"
                        class="flex flex-col gap-1.5 py-2.5 sm:flex-row sm:items-center sm:gap-3 hover:bg-surface-2/40 rounded-md px-1.5 -mx-1.5"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-primary">{{ space.name }}</span>
                            <span class="block truncate text-xs text-muted">
                                {{ space.customerName }}
                                <template v-if="space.nextPublication">
                                    · {{ t("suite.studio.workload.next_publication", { date: formatDate(space.nextPublication) }) }}
                                </template>
                            </span>
                        </span>
                        <SpaceWorkloadBadges :workload="space" />
                    </a>
                </li>
            </ul>
        </AppSectionCard>
    </div>
</template>
