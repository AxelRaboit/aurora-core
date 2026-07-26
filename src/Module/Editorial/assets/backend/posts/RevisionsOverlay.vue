<script setup>
import "./prose.css";

import { ref, computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { X, RotateCcw, History as HistoryIcon } from "lucide-vue-next";
import { renderBlocks } from "@/shared/utils/editor/blocksRenderer.js";
import { diffBlocksAgainstRevision, summarizeRevisionDiff, RevisionDiffKind } from "@/shared/utils/editor/revisionDiff.js";
import { statusBadgeColor } from "@/shared/utils/format/statusStyles.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { t } = useI18n();
const { formatDateTime } = useDateFormat();
const { request } = useRequest();

const props = defineProps({
    postId: { type: Number, required: true },
    show: { type: Boolean, default: false },
    locales: { type: Array, default: () => [] },
    currentTranslations: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["close", "restored"]);

const revisions = ref([]);
const loadingList = ref(false);
const selectedRevision = ref(null);
const loadingSelected = ref(false);
const restoring = ref(false);
const activeLocale = ref(props.locales[0] ?? "fr");

watch(
    () => props.show,
    async (visible) => {
        if (!visible) return;
        activeLocale.value = props.locales[0] ?? "fr";
        selectedRevision.value = null;
        await fetchRevisions();
    },
);

async function fetchRevisions() {
    loadingList.value = true;
    const data = await request(`/backend/editorial/posts/${props.postId}/revisions`, null, { method: HttpMethod.Get, noGuard: true });
    loadingList.value = false;
    if (!data) return;
    revisions.value = data.revisions ?? [];
}

async function selectRevision(revision) {
    selectedRevision.value = null;
    loadingSelected.value = true;
    const data = await request(`/backend/editorial/posts/${props.postId}/revisions/${revision.id}`, null, { method: HttpMethod.Get, noGuard: true });
    loadingSelected.value = false;
    if (!data) return;
    selectedRevision.value = data.revision ?? null;
}

async function restore() {
    if (!selectedRevision.value) return;
    restoring.value = true;
    const data = await request(`/backend/editorial/posts/${props.postId}/revisions/${selectedRevision.value.id}/restore`);
    restoring.value = false;
    if (!data) return;
    if (data.success) {
        toast.success(t("backend.posts.revisions.restored"));
        emit("restored");
    } else {
        toast.error(t("shared.common.error"));
    }
}

const revisionTranslations = computed(() => selectedRevision.value?.snapshot?.translations ?? {});

const diffEntries = computed(() => {
    const locale = activeLocale.value;
    const currentBlocks = props.currentTranslations?.[locale]?.blocks ?? [];
    const revisionBlocks = revisionTranslations.value?.[locale]?.blocks ?? [];
    return diffBlocksAgainstRevision(currentBlocks, revisionBlocks);
});

const stats = computed(() => summarizeRevisionDiff(diffEntries.value));

const KIND_CLASS = {
    [RevisionDiffKind.Unchanged]: "bg-surface-2 text-muted",
    [RevisionDiffKind.Added]: "bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300",
    [RevisionDiffKind.Removed]: "bg-rose-100 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300",
    [RevisionDiffKind.Modified]: "bg-amber-100 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300",
};

function renderBlock(block) {
    if (!block) return "";
    return renderBlocks([block]);
}

function formatDate(iso) {
    if (!iso) return "";
    try {
        return formatDateTime(iso);
    } catch {
        return iso;
    }
}

const showUnchanged = ref(false);
const visibleEntries = computed(() =>
    showUnchanged.value
        ? diffEntries.value
        : diffEntries.value.filter((entry) => entry.kind !== RevisionDiffKind.Unchanged),
);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-50 flex flex-col bg-bg overflow-hidden">
                <div class="flex items-center gap-3 px-6 py-3 border-b border-line bg-surface/90 backdrop-blur-sm shrink-0">
                    <HistoryIcon class="w-4 h-4 text-secondary" :stroke-width="2" />
                    <span class="flex-1 text-sm font-medium text-secondary truncate">
                        {{ t("backend.posts.revisions.title") }}
                    </span>
                    <AppIconButton :title="t('shared.common.close')" v-on:click="emit('close')">
                        <X class="w-5 h-5" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="flex-1 flex min-h-0">
                    <aside class="w-80 shrink-0 border-r border-line bg-surface overflow-y-auto scrollbar-thin">
                        <div v-if="loadingList" class="p-4 text-sm text-muted">{{ t("shared.common.loading") }}</div>
                        <div v-else-if="revisions.length === 0" class="p-4 text-sm text-muted">
                            {{ t("backend.posts.revisions.empty") }}
                        </div>
                        <ul v-else class="divide-y divide-line">
                            <li
                                v-for="revision in revisions"
                                :key="revision.id"
                                class="px-4 py-3 cursor-pointer transition-colors"
                                :class="selectedRevision?.id === revision.id ? 'bg-surface-2' : 'hover:bg-surface-2/50'"
                                v-on:click="selectRevision(revision)"
                            >
                                <div class="flex items-center gap-2 text-xs">
                                    <AppBadge :color="statusBadgeColor(revision.status)">
                                        {{ t("backend.posts.status_options." + revision.status) }}
                                    </AppBadge>
                                    <span class="text-muted font-mono">v{{ revision.postVersion }}</span>
                                </div>
                                <div class="mt-1 text-sm text-primary">{{ formatDate(revision.createdAt) }}</div>
                                <div v-if="revision.author" class="text-xs text-muted truncate">
                                    {{ revision.author.email }}
                                </div>
                            </li>
                        </ul>
                    </aside>

                    <section class="flex-1 overflow-y-auto scrollbar-thin">
                        <div v-if="!selectedRevision && !loadingSelected" class="p-8 text-sm text-muted text-center">
                            {{ t("backend.posts.revisions.select_hint") }}
                        </div>
                        <div v-else-if="loadingSelected" class="p-8 text-sm text-muted text-center">
                            {{ t("shared.common.loading") }}
                        </div>
                        <div v-else class="p-6 space-y-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <div v-if="locales.length > 1" class="flex gap-1">
                                    <AppTab
                                        v-for="locale in locales"
                                        :key="locale"
                                        size="xs"
                                        :active="activeLocale === locale"
                                        active-class="bg-accent-600 text-white"
                                        v-on:click="activeLocale = locale"
                                    >
                                        {{ locale.toUpperCase() }}
                                    </AppTab>
                                </div>
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="text-emerald-600 dark:text-emerald-400">+{{ stats.added }}</span>
                                    <span class="text-amber-600 dark:text-amber-400">~{{ stats.modified }}</span>
                                    <span class="text-rose-600 dark:text-rose-400">-{{ stats.removed }}</span>
                                    <span class="text-muted">· {{ stats.unchanged }} {{ t("backend.posts.revisions.unchanged") }}</span>
                                </div>
                                <AppCheckbox
                                    v-model="showUnchanged"
                                    :label="t('backend.posts.revisions.show_unchanged')"
                                    class="text-xs ml-auto"
                                />
                                <AppButton variant="primary" size="sm" :loading="restoring" v-on:click="restore">
                                    <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" />
                                    {{ t("backend.posts.revisions.restore") }}
                                </AppButton>
                            </div>

                            <div class="space-y-3">
                                <div v-if="visibleEntries.length === 0" class="p-4 text-sm text-muted text-center border border-line rounded-lg">
                                    {{ t("backend.posts.revisions.no_change") }}
                                </div>
                                <div
                                    v-for="entry in visibleEntries"
                                    :key="entry.id"
                                    class="border border-line rounded-lg overflow-hidden"
                                >
                                    <div class="flex items-center gap-2 px-3 py-1.5 text-xs bg-surface-2 border-b border-line">
                                        <span class="px-2 py-0.5 rounded-md font-medium" :class="KIND_CLASS[entry.kind]">{{ t(`backend.posts.revisions.kind.${entry.kind}`) }}</span>
                                        <span class="text-muted font-mono">#{{ entry.id.slice(0, 8) }}</span>
                                    </div>

                                    <div v-if="entry.kind === RevisionDiffKind.Modified" class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-line">
                                        <div class="p-3">
                                            <div class="text-xs text-rose-600 dark:text-rose-400 mb-1.5">{{ t("backend.posts.revisions.revision_side") }}</div>
                                            <div class="prose-preview text-sm" v-html="renderBlock(entry.revision)" />
                                        </div>
                                        <div class="p-3">
                                            <div class="text-xs text-emerald-600 dark:text-emerald-400 mb-1.5">{{ t("backend.posts.revisions.current_side") }}</div>
                                            <div class="prose-preview text-sm" v-html="renderBlock(entry.current)" />
                                        </div>
                                    </div>

                                    <div v-else class="p-3 prose-preview text-sm">
                                        <div v-html="renderBlock(entry.current ?? entry.revision)" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
