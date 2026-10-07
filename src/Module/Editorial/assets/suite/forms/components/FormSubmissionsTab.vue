<script setup>
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { Download, Inbox } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useFormSubmissions } from "../composables/useFormSubmissions.js";

/**
 * What visitors sent, newest first.
 *
 * Loaded when the tab opens, and not with the page: people most often come
 * for the questions, and an inbox loaded for nothing slows down the screen
 * they wanted.
 */
const props = defineProps({
    submissionsPath: { type: String, required: true },
    exportPath: { type: String, required: true },
});

const { t } = useI18n();
const { formatDateTime } = useDateFormat();
const { submissions, total, page, totalPages, loading, load, goToPage, exportUrl } = useFormSubmissions(props);

onMounted(load);

const formatDate = (value) => formatDateTime(value);
</script>

<template>
    <div class="aurora-card relative space-y-4 p-3 sm:p-5">
        <AppLoader :active="loading" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-primary">
                    {{ t("suite.forms.submissions.title") }}
                    <span v-if="total" class="font-normal text-muted">({{ total }})</span>
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("suite.forms.submissions.intro") }}</p>
            </div>
            <AppButton
                v-if="total"
                variant="ghost"
                size="sm"
                class="w-full justify-center sm:w-auto"
                :href="exportUrl()"
                as="a"
            >
                <Download class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.forms.submissions.export") }}
            </AppButton>
        </div>

        <AppNoData
            v-if="!loading && !submissions.length"
            :icon="Inbox"
            :message="t('suite.forms.submissions.empty')"
            :hint="t('suite.forms.submissions.empty_hint')"
        />

        <div v-else class="space-y-2">
            <article v-for="submission in submissions" :key="submission.id" class="rounded-lg border border-line p-3 space-y-2">
                <p class="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                    <span class="font-medium text-secondary">{{ formatDate(submission.submittedAt) }}</span>
                    <span>·</span>
                    <span class="font-mono">{{ submission.reference }}</span>
                    <span class="rounded bg-surface-2 px-1.5 py-0.5 uppercase">{{ submission.locale }}</span>
                </p>
                <dl class="m-0 grid grid-cols-1 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                    <template v-for="pair in submission.pairs" :key="pair.label">
                        <dt class="text-secondary">{{ pair.label }}</dt>
                        <dd class="m-0 min-w-0 whitespace-pre-line break-words text-primary">{{ pair.value }}</dd>
                    </template>
                </dl>
            </article>
        </div>

        <!-- The pagination shared by every list (02/10/2026). -->
        <AppPagination :page="page" :total-pages="totalPages" class="pt-1" v-on:change="goToPage" />
    </div>
</template>
