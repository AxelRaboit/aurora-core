<script setup>
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useComments } from "./composables/useComments.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { Check, ChevronLeft, ChevronRight, ShieldAlert, Trash2, X } from "lucide-vue-next";

const { t, d } = useI18n();
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

function formatDate(value) {
    return d(new Date(value), "short");
}

function badgeColor(value) {
    return { approved: "green", spam: "rose" }[value] ?? "amber";
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex p-1 bg-surface-2 border border-line rounded-lg gap-1">
                <AppTab
                    size="sm"
                    :active="status === ''"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    v-on:click="status = ''"
                >
                    {{ t("backend.comments.all") }}
                </AppTab>
                <AppTab
                    v-for="option in statuses"
                    :key="option.value"
                    size="sm"
                    :active="status === option.value"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    v-on:click="status = option.value"
                >
                    {{ t(option.labelKey) }}
                    <span v-if="counts[option.value]" class="ml-1 text-xs text-muted">{{ counts[option.value] }}</span>
                </AppTab>
            </div>

            <AppInput
                v-model="search"
                class="w-full sm:w-72"
                :placeholder="t('backend.comments.search_placeholder')"
                :loading="loading"
            />
        </div>

        <AppNoData v-if="!comments.length && !loading" :message="t('backend.comments.empty')" />

        <div v-else class="space-y-2">
            <article
                v-for="comment in comments"
                :key="comment.id"
                class="bg-surface border border-line rounded-xl p-4 space-y-2"
            >
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-primary truncate">
                            {{ comment.authorName }}
                            <span class="text-muted font-normal">&lt;{{ comment.authorEmail }}&gt;</span>
                        </p>
                        <p class="text-xs text-muted mt-0.5 truncate">
                            {{ t("backend.comments.on_post") }} {{ comment.postTitle }}
                            · {{ formatDate(comment.createdAt) }}
                            <span v-if="comment.parentAuthorName">
                                · {{ t("backend.comments.in_reply_to", { name: comment.parentAuthorName }) }}
                            </span>
                        </p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <AppBadge :color="badgeColor(comment.status)">
                            {{ t(`backend.comments.status.${comment.status}`) }}
                        </AppBadge>

                        <div class="flex items-center gap-0.5">
                            <AppIconButton
                                v-if="can('editorial.comments.moderate') && comment.status !== 'approved'"
                                color="green"
                                :title="t('backend.comments.approve')"
                                v-on:click="approve(comment)"
                            >
                                <Check class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                v-if="can('editorial.comments.moderate') && comment.status !== 'spam'"
                                color="amber"
                                :title="t('backend.comments.spam')"
                                v-on:click="markAsSpam(comment)"
                            >
                                <ShieldAlert class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                v-if="can('editorial.comments.delete')"
                                color="rose"
                                :title="t('shared.common.delete')"
                                v-on:click="pendingDelete = comment"
                            >
                                <Trash2 class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </div>
                </header>

                <p class="text-sm text-secondary whitespace-pre-line">{{ comment.content }}</p>

                <footer v-if="comment.replyCount || comment.reactions" class="flex flex-wrap gap-3 text-xs text-muted">
                    <span v-if="comment.replyCount">{{ t("backend.comments.replies", { count: comment.replyCount }) }}</span>
                    <span v-for="(count, type) in comment.reactions" :key="type">{{ type }} · {{ count }}</span>
                </footer>
            </article>
        </div>

        <div v-if="totalPages > 1" class="flex items-center justify-center gap-2 pt-2">
            <AppButton variant="ghost" size="sm" :disabled="page <= 1" v-on:click="goToPage(page - 1)">
                <ChevronLeft class="w-4 h-4" :stroke-width="2" />
            </AppButton>
            <span class="text-xs text-secondary tabular-nums">{{ page }} / {{ totalPages }}</span>
            <AppButton variant="ghost" size="sm" :disabled="page >= totalPages" v-on:click="goToPage(page + 1)">
                <ChevronRight class="w-4 h-4" :stroke-width="2" />
            </AppButton>
        </div>

        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t("backend.comments.delete_confirm", { name: pendingDelete?.authorName ?? "" }) }}
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
