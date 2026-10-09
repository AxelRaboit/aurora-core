<script setup>
/**
 * The editor's help: every way to write a note, its shortcut, and a click
 * that inserts it at the caret (09/10/2026).
 *
 * In the suite and on a share page that writes. Where nothing can be written,
 * a click copies the example instead.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { CircleHelp, Copy, CornerDownLeft } from "lucide-vue-next";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppSearchInput from "@shared/components/form/input/AppSearchInput.vue";
import { NOTE_HELP_SECTIONS, filterHelp, localizeExample, shortcutLabel } from "@notes/suite/markdown/composables/noteMarkdownHelp.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** Whether a click inserts the example (an editor is open) or copies it. */
    canInsert: { type: Boolean, default: true },
});

const emit = defineEmits(["close", "insert"]);

const { t, locale } = useI18n();
const query = ref("");

watch(
    () => props.show,
    (open) => {
        if (open) query.value = "";
    },
);

const language = computed(() => String(locale.value ?? "fr").slice(0, 2));

function labelOf(entry) {
    return t(`notes.markdown.help.entries.${entry.key}`);
}

const sections = computed(() => filterHelp(NOTE_HELP_SECTIONS, query.value, labelOf));

async function use(entry) {
    const text = localizeExample(entry.insert ?? entry.syntax, language.value);
    if (props.canInsert && null !== entry.insert) {
        emit("insert", { text, caret: entry.caret ?? null });
        emit("close");

        return;
    }

    try {
        await navigator.clipboard.writeText(localizeExample(entry.syntax, language.value));
    } catch {
        // The example stays on screen to select by hand.
    }
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="2xl"
        :title="t('notes.markdown.help.title')"
        :icon="CircleHelp"
        mobile-fullscreen
        v-on:close="emit('close')"
    >
        <div class="flex flex-col gap-4">
            <p class="text-sm text-muted">{{ t('notes.markdown.help.intro') }}</p>
            <AppSearchInput
                v-model="query"
                data-note-help-search
                :placeholder="t('notes.markdown.help.search')"
                :debounce="0"
            />

            <p v-if="0 === sections.length" class="py-6 text-center text-sm text-muted">
                {{ t('notes.markdown.help.no_results') }}
            </p>

            <section v-for="section in sections" :key="section.key" class="flex flex-col gap-1">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">
                    {{ t(`notes.markdown.help.sections.${section.key}`) }}
                </h3>
                <ul class="m-0 flex list-none flex-col divide-y divide-line rounded-lg border border-line p-0">
                    <li
                        v-for="entry in section.entries"
                        :key="entry.key"
                        :data-note-help-entry="entry.key"
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2"
                    >
                        <span class="min-w-40 flex-1 text-sm text-primary">{{ labelOf(entry) }}</span>
                        <code class="max-w-full whitespace-pre-wrap rounded bg-surface-2 px-1.5 py-0.5 font-mono text-xs text-secondary">{{ localizeExample(entry.syntax, language) }}</code>
                        <kbd
                            v-if="entry.shortcut"
                            class="shrink-0 rounded border border-line px-1.5 py-0.5 font-mono text-2xs text-muted"
                        >{{ shortcutLabel(entry.shortcut) }}</kbd>
                        <button
                            v-if="null !== entry.insert || !canInsert"
                            type="button"
                            class="ml-auto inline-flex shrink-0 items-center gap-1 rounded-md border border-line px-2 py-1 text-xs text-secondary transition-colors hover:bg-surface-2 hover:text-primary"
                            :data-note-help-use="entry.key"
                            v-on:click="use(entry)"
                        >
                            <CornerDownLeft v-if="canInsert && null !== entry.insert" class="h-3.5 w-3.5" :stroke-width="2" />
                            <Copy v-else class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ canInsert && null !== entry.insert ? t('notes.markdown.help.insert') : t('notes.markdown.help.copy') }}
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </AppModal>
</template>
