<script setup>
/**
 * A note's history: its past versions, what has changed since, and the way
 * back to them.
 *
 * Same template as the publications history (the list on the left, the
 * version on the right), but a note is text: rather than two columns to
 * reread, the lines removed and added since that version, in red and in
 * green. "Voir la version entière" shows its text as is.
 *
 * Restoring first keeps the current state as a version: going back loses
 * nothing, and is undone the same way.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { History, RotateCcw, X } from "lucide-vue-next";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { diffStats, lineDiff } from "../composables/noteLineDiff.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    noteId: { type: Number, default: null },
    /** The note's current state, `{title, content}`, to compare. */
    current: { type: Object, default: () => ({}) },
    /** `revisions(id)`, `revision(id, revisionId)`, `restoreRevision(id, revisionId)`. */
    api: { type: Object, required: true },
    canRestore: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "restored"]);

const { t } = useI18n();
const { formatDate } = useDateFormat();

const revisions = ref([]);
const loading = ref(false);
const selected = ref(null);
const detail = ref(null);
const detailLoading = ref(false);
const restoring = ref(false);
const confirming = ref(false);
const whole = ref(false);

const lineDifference = computed(() => (detail.value ? lineDiff(detail.value.content ?? "", props.current?.content ?? "") : null));
const stats = computed(() => diffStats(lineDifference.value));
const titleChanged = computed(() => detail.value && (detail.value.title ?? "") !== (props.current?.title ?? ""));

async function load() {
    if (null === props.noteId) return;

    loading.value = true;
    try {
        const { ok, payload } = await props.api.revisions(props.noteId);
        revisions.value = ok ? (payload.revisions ?? []) : [];
        selected.value = revisions.value[0]?.id ?? null;
    } finally {
        loading.value = false;
    }
}

async function loadDetail(id) {
    detailLoading.value = true;
    detail.value = null;
    whole.value = false;
    try {
        const { ok, payload } = await props.api.revision(props.noteId, id);
        if (ok) detail.value = payload.revision;
    } finally {
        detailLoading.value = false;
    }
}

async function restore() {
    if (restoring.value || null === selected.value) return;

    restoring.value = true;
    try {
        const { ok, payload } = await props.api.restoreRevision(props.noteId, selected.value);
        if (ok) {
            confirming.value = false;
            emit("restored", payload.note);
        }
    } finally {
        restoring.value = false;
    }
}

// `immediate`: a dialog mounted already open never sees the switch from
// closed to open, and would stay empty.
watch(
    () => props.show,
    (open) => {
        if (open) {
            confirming.value = false;
            load();
        }
    },
    { immediate: true },
);

watch(selected, (id) => {
    if (null !== id) loadDetail(id);
});

// The theme's semantic colours, readable in light as in dark.
const LINE_CLASSES = {
    added: "bg-success-soft text-success",
    removed: "bg-danger-soft text-danger line-through",
    same: "text-muted",
};
</script>

<template>
    <AppModal
        :show="show"
        max-width="4xl"
        :title="t('notes.markdown.revisions.title')"
        :icon="History"
        v-on:close="emit('close')"
    >
        <AppNoData
            v-if="!loading && !revisions.length"
            :message="t('notes.markdown.revisions.empty')"
            :hint="t('notes.markdown.revisions.empty_hint')"
            :icon="History"
        />

        <div v-else class="grid gap-4 md:grid-cols-[14rem_1fr]">
            <ol class="m-0 flex list-none flex-col gap-1 p-0 md:max-h-[28rem] md:overflow-y-auto" data-note-revisions>
                <li v-for="revision in revisions" :key="revision.id">
                    <button
                        type="button"
                        class="w-full rounded-lg px-3 py-2 text-left text-sm transition"
                        :class="revision.id === selected ? 'bg-surface-2 text-primary' : 'text-secondary hover:bg-surface-2/60'"
                        v-on:click="selected = revision.id"
                    >
                        <span class="block">{{ formatDate(revision.createdAt) }}</span>
                        <span class="mt-0.5 block truncate text-xs text-muted">
                            {{ revision.authorName || t('notes.markdown.revisions.no_author') }}
                        </span>
                    </button>
                </li>
            </ol>

            <div class="flex min-w-0 flex-col gap-3">
                <p v-if="detailLoading" class="m-0 text-sm text-muted">{{ t('shared.common.loading') }}</p>

                <template v-else-if="detail">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="m-0 text-xs text-muted" data-revision-stats>
                            {{ t('notes.markdown.revisions.since', { added: stats.added, removed: stats.removed }) }}
                        </p>
                        <AppButton variant="ghost" size="sm" v-on:click="whole = !whole">
                            {{ whole ? t('notes.markdown.revisions.show_changes') : t('notes.markdown.revisions.show_whole') }}
                        </AppButton>
                    </div>

                    <p v-if="titleChanged" class="m-0 text-sm text-secondary">
                        {{ t('notes.markdown.revisions.title_was', { title: detail.title || t('notes.markdown.untitled') }) }}
                    </p>

                    <pre
                        v-if="whole || null === lineDifference"
                        class="m-0 max-h-[24rem] overflow-auto whitespace-pre-wrap break-words rounded-lg border border-line bg-surface-2/40 p-3 text-xs text-secondary"
                    >{{ detail.content }}</pre>
                    <div
                        v-else
                        class="max-h-[24rem] overflow-auto rounded-lg border border-line bg-surface-2/40 py-2 font-mono text-xs"
                        data-revision-diff
                    >
                        <div
                            v-for="(line, index) in lineDifference"
                            :key="index"
                            class="whitespace-pre-wrap break-words px-3 py-px"
                            :class="LINE_CLASSES[line.kind]"
                        >
                            <span class="select-none pr-2 opacity-60">{{ 'added' === line.kind ? '+' : 'removed' === line.kind ? '-' : ' ' }}</span>{{ line.text || ' ' }}
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <template #footer>
            <AppModalFooter>
                <p v-if="confirming" class="mr-auto text-sm text-secondary">
                    {{ t('notes.markdown.revisions.restore_confirm') }}
                </p>
                <AppButton variant="ghost" size="md" v-on:click="confirming ? (confirming = false) : emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ confirming ? t('shared.common.cancel') : t('shared.common.close') }}
                </AppButton>
                <AppButton
                    v-if="canRestore && revisions.length"
                    variant="primary"
                    size="md"
                    :loading="restoring"
                    :disabled="null === selected"
                    v-on:click="confirming ? restore() : (confirming = true)"
                >
                    <RotateCcw class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ confirming ? t('notes.markdown.revisions.restore_now') : t('notes.markdown.revisions.restore') }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
