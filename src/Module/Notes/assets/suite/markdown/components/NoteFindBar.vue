<script setup>
/**
 * Find and replace in the note being written (10/10/2026), over the editor
 * as in VS Code: Cmd/Ctrl+F finds, Cmd+Option+F (Ctrl+Alt+F) replaces.
 *
 * **The occurrences are drawn over the field.** A textarea paints one
 * selection, and none at all while the focus is in this bar: every match
 * is a tinted box placed by {@see caretPositionIn}, the current one darker,
 * and the current one is also the field's selection, so Escape gives the
 * caret back on it.
 *
 * **A replacement goes through the editor's own way in**, the one shortcuts
 * use: the co-editing room sees an ordinary edit.
 *
 * Accents and case are ignored unless "Aa" is on, as in the notebook search.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { CaseSensitive, ChevronDown, ChevronRight, ChevronUp, Replace, ReplaceAll, X } from "lucide-vue-next";
import AppIconButton from "@shared/components/action/AppIconButton.vue";
import AppInput from "@shared/components/form/input/AppInput.vue";
import { caretPositionIn } from "@notes/suite/markdown/composables/caretPosition.js";
import { findMatches, matchIndexFrom, replaceEvery, replaceOne } from "@notes/suite/markdown/composables/noteFindReplace.js";

const props = defineProps({
    textarea: { type: Object, default: null },
    text: { type: String, default: "" },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "replace"]);

const { t } = useI18n();

const query = ref("");
const replacement = ref("");
const exact = ref(false);
const replacing = ref(false);
const current = ref(-1);
const findInput = ref(null);
const replaceInput = ref(null);

/** Drawn boxes beyond this would cost more to measure than they help. */
const DRAWN = 80;

const matches = computed(() => findMatches(props.text, query.value, { exact: exact.value }));

/** Opens the bar, with the selection as the query when it is one line. */
async function open({ replace = false } = {}) {
    const field = props.textarea;
    const selected = field ? field.value.slice(field.selectionStart, field.selectionEnd) : "";
    if (selected && !selected.includes("\n")) query.value = selected;
    replacing.value = replace && !props.readonly;
    await nextTick();
    current.value = matchIndexFrom(matches.value, field?.selectionStart ?? 0);
    reveal();
    const target = replacing.value && query.value ? replaceInput.value : findInput.value;
    target?.focus();
    target?.select();
}

defineExpose({ open, replacing });

watch([query, exact], () => {
    current.value = matchIndexFrom(matches.value, props.textarea?.selectionStart ?? 0);
    reveal();
});

// A replacement, or someone else's edit, moves the matches: the current one
// stays in range and selected.
watch(matches, async (list) => {
    if (current.value >= list.length) current.value = list.length - 1;
    if (current.value < 0 && list.length) current.value = 0;
    await nextTick();
    reveal();
});

function step(direction) {
    const total = matches.value.length;
    if (0 === total) return;
    current.value = (current.value + direction + total) % total;
    reveal();
}

/** Selects the current match in the field and scrolls it into view. */
function reveal() {
    const field = props.textarea;
    const match = matches.value[current.value];
    if (!field || !match) {
        remeasure();

        return;
    }
    field.setSelectionRange(match.start, match.end);
    const at = caretPositionIn(field, match.start);
    const box = field.getBoundingClientRect();
    if (at.top < box.top || at.top + at.lineHeight > box.bottom) {
        field.scrollTop += at.top - box.top - box.height / 3;
    }
    remeasure();
}

/**
 * The focus stays in the bar: Enter replaces the next one, as in any
 * editor. The current index then points at the following match, since the
 * replaced one is gone.
 */
function replaceCurrent() {
    const match = matches.value[current.value];
    if (props.readonly || !match) return;
    emit("replace", replaceOne(props.text, match, replacement.value));
}

function replaceAll() {
    if (props.readonly || 0 === matches.value.length) return;
    emit("replace", replaceEvery(props.text, matches.value, replacement.value));
}

function close() {
    const field = props.textarea;
    const match = matches.value[current.value];
    emit("close");
    if (!field) return;
    field.focus();
    if (match) field.setSelectionRange(match.start, match.end);
}

function onFindKeydown(event) {
    if ("Enter" === event.key) {
        event.preventDefault();
        step(event.shiftKey ? -1 : 1);
    } else if ("Escape" === event.key) {
        event.preventDefault();
        event.stopPropagation();
        close();
    }
}

function onReplaceKeydown(event) {
    if ("Enter" === event.key) {
        event.preventDefault();
        if (event.metaKey || event.ctrlKey) replaceAll();
        else replaceCurrent();
    } else if ("Escape" === event.key) {
        event.preventDefault();
        event.stopPropagation();
        close();
    }
}

const counter = computed(() => {
    if ("" === query.value) return "";
    if (0 === matches.value.length) return t("notes.markdown.find.none");

    return t("notes.markdown.find.count", { current: current.value + 1, total: matches.value.length });
});

// ── The boxes over the field ─────────────────────────────────────────

const tick = ref(0);
function remeasure() {
    tick.value += 1;
}

// The editor makes room for the replace row: the text moves down.
watch(replacing, async () => {
    await nextTick();
    remeasure();
});

watch(
    () => props.textarea,
    (field, previous) => {
        previous?.removeEventListener("scroll", remeasure);
        field?.addEventListener("scroll", remeasure, { passive: true });
    },
    { immediate: true },
);
window.addEventListener("resize", remeasure, { passive: true });
onBeforeUnmount(() => {
    props.textarea?.removeEventListener("scroll", remeasure);
    window.removeEventListener("resize", remeasure);
});

/**
 * The boxes of the matches around the current one, clipped to the field. A
 * match that wraps is drawn on its first line only.
 */
const boxes = computed(() => {
    void tick.value;
    const field = props.textarea;
    const list = matches.value;
    if (!field || 0 === list.length) return [];
    const bounds = field.getBoundingClientRect();
    const from = Math.max(0, Math.min(current.value, list.length - 1) - DRAWN / 2);

    return list.slice(from, from + DRAWN).flatMap((match, offset) => {
        const start = caretPositionIn(field, match.start);
        if (start.top + start.lineHeight < bounds.top || start.top > bounds.bottom) return [];
        const end = caretPositionIn(field, match.end);
        const right = end.top === start.top ? end.left : bounds.right - 12;

        return [{
            key: match.start,
            current: from + offset === current.value,
            top: start.top,
            left: start.left,
            width: Math.max(4, right - start.left),
            height: start.lineHeight,
        }];
    });
});
</script>

<template>
    <div class="pointer-events-none fixed inset-0 z-20 print:hidden" aria-hidden="true">
        <span
            v-for="box in boxes"
            :key="box.key"
            class="fixed rounded-sm"
            :class="box.current ? 'bg-amber-400/55 ring-1 ring-amber-500' : 'bg-amber-300/30'"
            :style="{ top: `${box.top}px`, left: `${box.left}px`, width: `${box.width}px`, height: `${box.height}px` }"
        />
    </div>

    <div
        data-note-find-bar
        role="search"
        class="absolute right-3 top-2 z-30 flex w-[min(28rem,calc(100%-1.5rem))] flex-col gap-1.5 rounded-lg border border-line bg-surface p-1.5 shadow-lg"
    >
        <div class="flex items-center gap-1">
            <AppIconButton
                v-if="!readonly"
                data-note-find-toggle
                :title="t('notes.markdown.find.toggle_replace')"
                :active="replacing"
                v-on:click="replacing = !replacing"
            >
                <ChevronDown v-if="replacing" class="h-3.5 w-3.5" :stroke-width="2" />
                <ChevronRight v-else class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppInput
                ref="findInput"
                v-model="query"
                data-note-find-field
                class="min-w-0 flex-1"
                :placeholder="t('notes.markdown.find.placeholder')"
                :aria-label="t('notes.markdown.find.placeholder')"
                v-on:keydown="onFindKeydown"
            />
            <span data-note-find-count class="w-16 shrink-0 text-center text-2xs tabular-nums text-muted" aria-live="polite">{{ counter }}</span>
            <AppIconButton
                data-note-find-exact
                :title="t('notes.markdown.find.exact')"
                :active="exact"
                v-on:click="exact = !exact"
            >
                <CaseSensitive class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton :title="t('notes.markdown.find.previous')" :disabled="0 === matches.length" v-on:click="step(-1)">
                <ChevronUp class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton :title="t('notes.markdown.find.next')" :disabled="0 === matches.length" v-on:click="step(1)">
                <ChevronDown class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton data-note-find-close :title="t('notes.markdown.find.close')" v-on:click="close">
                <X class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
        </div>
        <div v-if="replacing" class="flex items-center gap-1 pl-8">
            <AppInput
                ref="replaceInput"
                v-model="replacement"
                data-note-replace-field
                class="min-w-0 flex-1"
                :placeholder="t('notes.markdown.find.replace_placeholder')"
                :aria-label="t('notes.markdown.find.replace_placeholder')"
                v-on:keydown="onReplaceKeydown"
            />
            <AppIconButton
                data-note-replace-one
                :title="t('notes.markdown.find.replace')"
                :disabled="0 === matches.length"
                v-on:click="replaceCurrent"
            >
                <Replace class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                data-note-replace-all
                :title="t('notes.markdown.find.replace_all')"
                :disabled="0 === matches.length"
                v-on:click="replaceAll"
            >
                <ReplaceAll class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
        </div>
    </div>
</template>
