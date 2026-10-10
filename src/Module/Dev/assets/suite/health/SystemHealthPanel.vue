<script setup>
/**
 * « État du système », at the top of the developer overview.
 *
 * **Does everything that must run, run.** The worker, the scheduled tasks,
 * the queues, the database, the live hub, the server's own units, the disk,
 * the mail, the storage and the certificate: one summary in colour, then a
 * card per group, then the tasks and the failed messages in detail.
 *
 * Filled after the page is on screen (see `useSystemHealth`), and measured
 * again on demand. The colours are the dashboard's: emerald for running,
 * amber for something to look at, rose for down, grey for what has nothing
 * to say yet or is not configured here.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RefreshCw, RotateCcw, Trash2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppSectionCard from "@/shared/components/display/AppSectionCard.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useSystemHealth } from "./useSystemHealth.js";

const props = defineProps({
    healthPath: { type: String, required: true },
    /** With `__id__`. */
    retryPath: { type: String, required: true },
    /** With `__id__`. */
    deletePath: { type: String, required: true },
    csrfToken: { type: String, default: "" },
});

const { t } = useI18n();
const { formatDateTime, formatTime } = useDateFormat();

const { report, loading, failed, acting, load, retry, remove } = useSystemHealth({
    healthPath: props.healthPath,
    retryPath: props.retryPath,
    deletePath: props.deletePath,
    csrfToken: props.csrfToken,
});

const STATUS_COLORS = Object.freeze({
    ok: "emerald",
    warning: "amber",
    danger: "rose",
    unknown: "gray",
    off: "gray",
});

const SUMMARY_CLASSES = Object.freeze({
    ok: "border-emerald-500/40 bg-emerald-500/10",
    warning: "border-amber-500/40 bg-amber-500/10",
    danger: "border-rose-500/40 bg-rose-500/10",
    unknown: "border-line bg-surface-2",
});

const summaryClass = computed(() => SUMMARY_CLASSES[report.value?.status] ?? SUMMARY_CLASSES.unknown);

const pendingDelete = ref(null);

async function confirmDelete() {
    const failure = pendingDelete.value;
    pendingDelete.value = null;
    if (failure) await remove(failure.id);
}

/** A check's sentence, in the reader's language, from the server's key and figures. */
function sentence(check) {
    return t(check.messageKey, check.parameters ?? {});
}

function label(check) {
    return t(check.labelKey, check.parameters ?? {});
}
</script>

<template>
    <section class="space-y-3" :aria-label="t('suite.health.title')">
        <div
            class="flex flex-col gap-2 rounded-lg border px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            :class="summaryClass"
        >
            <div class="min-w-0">
                <h2 class="m-0 text-[0.9375rem] font-semibold text-primary">{{ t("suite.health.title") }}</h2>
                <p class="m-0 text-sm text-secondary">
                    <template v-if="report">{{ t(`suite.health.summary.${report.status}`) }}</template>
                    <template v-else-if="failed">{{ t("suite.health.load_failed") }}</template>
                    <template v-else>{{ t("suite.health.loading") }}</template>
                </p>
                <p v-if="report" class="m-0 text-xs text-muted">
                    {{ t("suite.health.checked_at", { time: formatTime(report.checkedAt) }) }}
                </p>
            </div>
            <AppButton
                class="w-full shrink-0 sm:w-auto"
                variant="ghost"
                size="sm"
                :loading="loading"
                v-on:click="load"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("suite.health.refresh") }}
            </AppButton>
        </div>

        <AppGuide :title="t('suite.health.guide.title')" storage-key="dev-system-health">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.health.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <template v-if="report">
            <div class="grid gap-3 lg:grid-cols-3">
                <AppSectionCard
                    v-for="section in report.sections"
                    :key="section.key"
                    :title="t(`suite.health.sections.${section.key}`)"
                >
                    <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
                        <li v-for="check in section.checks" :key="check.key" class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-primary">{{ label(check) }}</span>
                                <span class="block break-words text-xs text-secondary">{{ sentence(check) }}</span>
                            </span>
                            <AppBadge class="shrink-0" :color="STATUS_COLORS[check.status] ?? 'gray'">
                                {{ t(`suite.health.statuses.${check.status}`) }}
                            </AppBadge>
                        </li>
                    </ul>
                </AppSectionCard>
            </div>

            <AppSectionCard :title="t('suite.health.tasks.title')">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-1.5 pr-3 font-medium">{{ t("suite.health.tasks.name") }}</th>
                                <th class="py-1.5 pr-3 font-medium">{{ t("suite.health.tasks.trigger") }}</th>
                                <th class="py-1.5 pr-3 font-medium">{{ t("suite.health.tasks.last_run") }}</th>
                                <th class="py-1.5 pr-3 font-medium">{{ t("suite.health.tasks.next_run") }}</th>
                                <th class="py-1.5 font-medium" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/60">
                            <tr v-for="task in report.tasks" :key="task.id">
                                <td class="py-1.5 pr-3 font-mono text-xs text-primary">{{ task.name }}</td>
                                <td class="py-1.5 pr-3 font-mono text-xs text-secondary">{{ task.trigger }}</td>
                                <td class="py-1.5 pr-3 text-xs text-secondary">
                                    {{ task.lastRunAt ? formatDateTime(task.lastRunAt) : t("suite.health.tasks.never") }}
                                    <span v-if="task.error" class="block text-rose-400">{{ task.error }}</span>
                                </td>
                                <td class="py-1.5 pr-3 text-xs text-secondary">{{ formatDateTime(task.nextRunAt) }}</td>
                                <td class="py-1.5 text-right">
                                    <AppBadge :color="STATUS_COLORS[task.status] ?? 'gray'">
                                        {{ t(`suite.health.statuses.${task.status}`) }}
                                    </AppBadge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppSectionCard>

            <AppSectionCard :title="t('suite.health.failures.title')">
                <AppNoData v-if="!report.failures.length" :message="t('suite.health.failures.empty')" />
                <ul v-else class="m-0 flex list-none flex-col divide-y divide-line/60 p-0">
                    <li v-for="failure in report.failures" :key="failure.id" class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between">
                        <span class="min-w-0">
                            <span class="block font-mono text-xs text-primary">{{ failure.message }}</span>
                            <span class="block break-words text-xs text-secondary">{{ failure.error }}</span>
                            <span class="block text-xs text-muted">
                                {{ formatDateTime(failure.failedAt) }}
                                <template v-if="failure.from"> · {{ t("suite.health.failures.from", { transport: failure.from }) }}</template>
                            </span>
                        </span>
                        <span class="flex shrink-0 gap-2">
                            <AppButton variant="ghost" size="sm" :loading="acting === failure.id" v-on:click="retry(failure.id)">
                                <RotateCcw class="h-3.5 w-3.5" :stroke-width="2" />
                                {{ t("suite.health.failures.retry") }}
                            </AppButton>
                            <AppButton variant="danger" size="sm" :disabled="acting === failure.id" v-on:click="pendingDelete = failure">
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                                {{ t("suite.health.failures.delete") }}
                            </AppButton>
                        </span>
                    </li>
                </ul>
            </AppSectionCard>
        </template>

        <AppModal
            :show="null !== pendingDelete"
            max-width="md"
            :title="t('suite.health.failures.delete')"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-secondary">{{ t("suite.health.failures.confirm_delete") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="confirmDelete">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("suite.health.failures.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </section>
</template>
