<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useCommentRowActions } from "./composables/useCommentRowActions.js";
import { useComments } from "./composables/useComments.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { Trash2, X } from "lucide-vue-next";

const { t, te: hasTranslation } = useI18n();
const { formatDateTime } = useDateFormat();
const { can } = usePrivileges();

const props = defineProps({
    counts: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    listPath: { type: String, required: true },
    approvePathTemplate: { type: String, required: true },
    spamPathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
});

const {
    comments, counts, total, page, totalPages,
    status, search, loading, goToPage,
    approve, markAsSpam,
    pendingDelete, deleteLoading, doDelete,
} = useComments(props);

const actionsFor = useCommentRowActions({
    can,
    approve,
    markAsSpam,
    confirmDelete: (comment) => {
        pendingDelete.value = comment;
    },
});

/** A reaction by the name readers see under the post (« J'aime »), not its code. */
function reactionLabel(type) {
    const key = `frontend.editorial.comments.reactions.${type}`;

    return hasTranslation(key) ? t(key) : type;
}

/**
 * Approved reads as done (green), pending as waiting (amber), spam as
 * refused (rose). "green" was not a colour the badge knows, so approved
 * comments came out grey, like nothing at all.
 */
function badgeColor(value) {
    return { approved: "emerald", spam: "rose" }[value] ?? "amber";
}

/** « Tous » first, counting every queue, then one tab per status. */
const tabs = computed(() => [
    {
        value: "",
        label: t("suite.comments.all"),
        count: Object.values(counts.value).reduce((sum, count) => sum + count, 0),
    },
    ...props.statuses.map((option) => ({
        value: option.value,
        label: t(option.labelKey),
        count: counts.value[option.value] ?? 0,
    })),
]);
</script>

<template>
    <div class="aurora-stack">
        <!-- The queues first, as a segmented group like the other lists of
             the suite, each with its count as a muted number. One line on
             a phone too: it scrolls sideways rather than wrapping. -->
        <div class="overflow-x-auto scrollbar-thin">
            <div
                class="inline-flex items-center gap-0.5 rounded-lg border border-line bg-surface-2/40 p-0.5"
                role="group"
                :aria-label="t('suite.comments.title')"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-sm transition-colors"
                    :class="status === tab.value ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
                    :aria-pressed="status === tab.value"
                    v-on:click="status = tab.value"
                >
                    {{ tab.label }}
                    <span class="text-xs tabular-nums text-muted">{{ tab.count }}</span>
                </button>
            </div>
        </div>

        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('suite.comments.search_placeholder')" />
        </AppListToolbar>

        <!-- The screen's how-to guide, next to what it explains; collapsed
             or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.comments.guide.title')" storage-key="comments">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.comments.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <AppNoData v-if="!comments.length && !loading" :message="t('suite.comments.empty')" />

        <div v-else class="space-y-2">
            <article
                v-for="comment in comments"
                :key="comment.id"
                class="aurora-card p-4 space-y-2"
            >
                <header class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <!-- **The address under the name on a phone.** Both
                             on one line gives "Best SEO Offer <contact@e…"
                             for three hundred and twenty-five pixels: the name
                             loses its end and the address never started. Each
                             on its own line, and both can be read. -->
                        <p class="text-sm font-medium text-primary truncate">
                            {{ comment.authorName }}
                            <span class="hidden text-muted font-normal sm:inline">
                                &lt;{{ comment.authorEmail }}&gt;
                            </span>
                        </p>
                        <p class="truncate text-xs text-muted sm:hidden">{{ comment.authorEmail }}</p>
                        <!-- Truncated on one line, "En réponse à …" always
                             fell outside the frame on a phone: it wraps
                             below `sm`. -->
                        <p class="text-xs text-muted mt-0.5 sm:truncate">
                            {{ t("suite.comments.on_post") }} {{ comment.postTitle }}
                            · {{ formatDateTime(comment.createdAt) }}
                            <span v-if="comment.parentAuthorName">
                                · {{ t("suite.comments.in_reply_to", { name: comment.parentAuthorName }) }}
                            </span>
                        </p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <AppBadge :color="badgeColor(comment.status)">
                            {{ t(`suite.comments.status.${comment.status}`) }}
                        </AppBadge>

                        <!-- The three dots on every screen, as on every list
                             (Axel's decision of 04/10/2026). -->
                        <AppRowActions :actions="actionsFor(comment)" :label="comment.authorName ?? ''" />
                    </div>
                </header>

                <p class="text-sm text-secondary whitespace-pre-line">{{ comment.content }}</p>

                <footer v-if="comment.replyCount || comment.reactions" class="flex flex-wrap gap-3 text-xs text-muted">
                    <span v-if="comment.replyCount">{{ t("suite.comments.replies", { count: comment.replyCount }) }}</span>
                    <span v-for="(count, type) in comment.reactions" :key="type">{{ reactionLabel(type) }} · {{ count }}</span>
                </footer>
            </article>
        </div>

        <!-- The pagination of every list: "précédent" was a bare icon and
             "suivant" a framed button, both without a name (02/10/2026). -->
        <AppPagination :page="page" :total-pages="totalPages" class="pt-2" v-on:change="goToPage" />

        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t("suite.comments.delete_confirm", { name: pendingDelete?.authorName ?? "" }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
