<script setup>
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, Download, Inbox } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useFormSubmissions } from "../composables/useFormSubmissions.js";

/**
 * Ce que les visiteurs ont envoyé, du plus récent au plus ancien.
 *
 * Chargé à l'ouverture de l'onglet, et pas avec la page : on vient le plus
 * souvent pour les questions, et une boîte de réception chargée pour rien
 * ralentit l'écran qu'on voulait.
 */
const props = defineProps({
    submissionsPath: { type: String, required: true },
    exportPath: { type: String, required: true },
});

const { t, d } = useI18n();
const { submissions, total, page, totalPages, loading, load, goToPage, exportUrl } = useFormSubmissions(props);

onMounted(load);

const formatDate = (value) => d(new Date(value), { dateStyle: "medium", timeStyle: "short" });
</script>

<template>
    <div class="aurora-card relative space-y-4 p-3 sm:p-5">
        <AppLoader :active="loading" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-primary">
                    {{ t("backend.forms.submissions.title") }}
                    <span v-if="total" class="font-normal text-muted">({{ total }})</span>
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.forms.submissions.intro") }}</p>
            </div>
            <AppButton
                v-if="total"
                variant="ghost"
                size="sm"
                class="w-full justify-center sm:w-auto"
                :href="exportUrl()"
                as="a"
            >
                <Download class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.forms.submissions.export") }}
            </AppButton>
        </div>

        <AppNoData
            v-if="!loading && !submissions.length"
            :icon="Inbox"
            :message="t('backend.forms.submissions.empty')"
            :hint="t('backend.forms.submissions.empty_hint')"
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

        <div v-if="totalPages > 1" class="flex items-center justify-center gap-2 pt-1">
            <AppButton
                variant="ghost"
                size="sm"
                :disabled="page <= 1"
                :title="t('backend.forms.submissions.previous')"
                v-on:click="goToPage(page - 1)"
            >
                <ChevronLeft class="h-4 w-4" :stroke-width="2" />
            </AppButton>
            <span class="text-xs tabular-nums text-secondary">{{ page }} / {{ totalPages }}</span>
            <AppButton
                variant="ghost"
                size="sm"
                :disabled="page >= totalPages"
                :title="t('backend.forms.submissions.next')"
                v-on:click="goToPage(page + 1)"
            >
                <ChevronRight class="h-4 w-4" :stroke-width="2" />
            </AppButton>
        </div>
    </div>
</template>
