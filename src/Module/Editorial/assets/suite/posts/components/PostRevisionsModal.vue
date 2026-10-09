<script setup>
import { computed, ref, watch } from "vue";
import { POST_STATUS_COLORS } from "@/shared/utils/format/statusStyles.js";
import { useI18n } from "vue-i18n";
import { History, RotateCcw, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";

/**
 * The history of a publication: what was saved, when, by whom, and a way back.
 *
 * Every save already wrote a revision, and the endpoints to read and restore
 * them already existed, tested and permissioned. Nothing called them: the
 * feature was complete and unreachable, and the documentation described a
 * safety net nobody could pull.
 *
 * Side by side rather than a text diff. What a reader needs first is "which
 * version is this one" - the title, the address, the summary and the state
 * it was in - and that is answerable without a diff engine. The body is
 * shown as its plain text so a version can be recognised, not reviewed line
 * by line.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    postId: { type: [Number, String], required: true },
    locale: { type: String, required: true },
    current: { type: Object, default: () => ({}) },
    listPathTemplate: { type: String, required: true },
    showPathTemplate: { type: String, required: true },
    restorePathTemplate: { type: String, required: true },
    canRestore: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "restored"]);

const { t } = useI18n();
const { request } = useRequest();
const { formatDate } = useDateFormat();

const revisions = ref([]);
const loading = ref(false);
const selected = ref(null);
const detail = ref(null);
const detailLoading = ref(false);
const restoring = ref(false);
const confirming = ref(false);


/**
 * The snapshot keeps a translation per language; the one worth showing is the
 * one being edited. A revision older than a language returns nothing for it,
 * which is a fact about that revision and not an error.
 */
const revisionSide = computed(() => {
    const translation = detail.value?.snapshot?.translations?.[props.locale];

    if (!translation) return null;

    return {
        title: translation.title,
        slug: translation.slug,
        description: translation.description,
        body: plainText(translation.grid),
        status: detail.value.snapshot.status,
    };
});

const currentSide = computed(() => ({
    title: props.current?.title ?? "",
    slug: props.current?.slug ?? "",
    description: props.current?.description ?? "",
    body: plainText(props.current?.grid),
    status: props.current?.status ?? null,
}));

/**
 * The words of a grid, in reading order, with the markup dropped. Enough to
 * tell two versions apart at a glance, which is what this panel is for.
 */
function plainText(grid) {
    const zones = grid?.zones ?? {};

    return Object.values(zones)
        .flatMap((zone) => zone?.blocks ?? [])
        .map((block) => {
            const data = block?.data ?? {};

            if (Array.isArray(data.items)) {
                return data.items.map((item) => item?.content ?? "").join(" ");
            }

            return data.text ?? data.caption ?? "";
        })
        .join(" ")
        .replace(/<[^>]+>/g, " ")
        .replace(/\s+/g, " ")
        .trim();
}

async function load() {
    loading.value = true;
    try {
        const data = await request(
            buildPath(props.listPathTemplate, { id: props.postId }),
            null,
            { method: "GET" },
        );

        if (!data?.success) return;

        revisions.value = data.revisions;
        selected.value = data.revisions[0]?.id ?? null;
    } finally {
        loading.value = false;
    }
}

async function loadDetail(id) {
    detailLoading.value = true;
    detail.value = null;
    try {
        const data = await request(
            buildPath(props.showPathTemplate, { id: props.postId }).replace(
                "__revisionId__",
                String(id),
            ),
            null,
            { method: "GET" },
        );

        if (data?.success) detail.value = data.revision;
    } finally {
        detailLoading.value = false;
    }
}

async function restore() {
    if (restoring.value || null === selected.value) return;

    restoring.value = true;
    try {
        const data = await request(
            buildPath(props.restorePathTemplate, { id: props.postId }).replace(
                "__revisionId__",
                String(selected.value),
            ),
        );

        if (data?.success) {
            confirming.value = false;
            emit("restored", data.post);
        }
    } finally {
        restoring.value = false;
    }
}

// `immediate`, because a panel mounted already open never sees the change
// from closed to open and would sit empty for ever.
watch(
    () => props.show,
    (open) => {
        if (open) load();
    },
    { immediate: true },
);

watch(selected, (id) => {
    if (null !== id) loadDetail(id);
});

function labelFor(revision) {
    return revision.author?.email ?? t("suite.posts.revisions.no_author");
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="4xl"
        :title="t('suite.posts.revisions.title')"
        :icon="History"
        v-on:close="emit('close')"
    >
        <AppNoData
            v-if="!loading && !revisions.length"
            :message="t('suite.posts.revisions.empty')"
        />

        <div v-else class="grid gap-4 md:grid-cols-[16rem_1fr]">
            <ol class="space-y-1 md:max-h-96 md:overflow-y-auto">
                <li v-for="revision in revisions" :key="revision.id">
                    <button
                        type="button"
                        class="w-full rounded-lg px-3 py-2 text-left text-sm transition"
                        :class="revision.id === selected ? 'bg-surface-2 text-primary' : 'text-secondary hover:bg-surface-2/60'"
                        v-on:click="selected = revision.id"
                    >
                        <span class="block">{{ formatDate(revision.createdAt) }}</span>
                        <span class="mt-0.5 flex items-center gap-2 text-xs text-muted">
                            <AppBadge :color="POST_STATUS_COLORS[revision.status] ?? 'gray'" size="sm">
                                {{ t(`suite.posts.status.${revision.status}`) }}
                            </AppBadge>
                            <span class="truncate">{{ labelFor(revision) }}</span>
                        </span>
                    </button>
                </li>
            </ol>

            <div class="min-w-0 space-y-3">
                <p v-if="detailLoading" class="text-sm text-muted">
                    {{ t("shared.common.loading") }}
                </p>

                <div v-else-if="revisionSide" class="grid gap-4 sm:grid-cols-2">
                    <section class="min-w-0 space-y-2">
                        <h4 class="text-xs uppercase tracking-wide text-muted">
                            {{ t("suite.posts.revisions.this_version") }}
                        </h4>
                        <dl class="space-y-1.5 text-sm">
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.field_title") }}</dt>
                                <dd class="text-primary break-words">{{ revisionSide.title }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.field_slug") }}</dt>
                                <dd class="text-primary break-words">{{ revisionSide.slug }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.field_description") }}</dt>
                                <dd class="text-primary break-words">{{ revisionSide.description || "-" }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.revisions.body") }}</dt>
                                <dd class="text-secondary line-clamp-6 break-words">{{ revisionSide.body || "-" }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="min-w-0 space-y-2">
                        <h4 class="text-xs uppercase tracking-wide text-muted">
                            {{ t("suite.posts.revisions.current_version") }}
                        </h4>
                        <dl class="space-y-1.5 text-sm">
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.field_title") }}</dt>
                                <dd class="text-primary break-words">{{ currentSide.title }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.field_slug") }}</dt>
                                <dd class="text-primary break-words">{{ currentSide.slug }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.field_description") }}</dt>
                                <dd class="text-primary break-words">{{ currentSide.description || "-" }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted">{{ t("suite.posts.revisions.body") }}</dt>
                                <dd class="text-secondary line-clamp-6 break-words">{{ currentSide.body || "-" }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <p v-else-if="selected" class="text-sm text-muted">
                    {{ t("suite.posts.revisions.no_translation") }}
                </p>
            </div>
        </div>

        <template #footer>
            <AppModalFooter>
                <p v-if="confirming" class="mr-auto text-sm text-secondary">
                    {{ t("suite.posts.revisions.restore_confirm") }}
                </p>
                <AppButton variant="ghost" size="md" v-on:click="confirming ? (confirming = false) : emit('close')">
                    <X class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ confirming ? t("shared.common.cancel") : t("shared.common.close") }}
                </AppButton>
                <AppButton
                    v-if="canRestore && revisions.length"
                    variant="primary"
                    size="md"
                    :loading="restoring"
                    :disabled="null === selected"
                    v-on:click="confirming ? restore() : (confirming = true)"
                >
                    <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ confirming ? t("suite.posts.revisions.restore_now") : t("suite.posts.revisions.restore") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
