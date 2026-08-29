<script setup>
import { toRef } from "vue";
import { useI18n } from "vue-i18n";
import { useNoteEditorTextarea } from "@notes/backend/markdown/composables/useNoteEditorTextarea.js";
import { useNoteImageUpload } from "@notes/backend/markdown/composables/useNoteImageUpload.js";
import AppFloatingMenu from "@shared/components/overlay/AppFloatingMenu.vue";
import AppSearchInput from "@shared/components/form/input/AppSearchInput.vue";
import { FileText } from "lucide-vue-next";

const props = defineProps({
    modelValue: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    /**
     * Flat list of the user's notes - fed into the `[[` autocomplete so
     * suggestions reflect the live note list (a newly-created note
     * appears as soon as the parent's notes ref refreshes).
     */
    flatNotes: { type: Array, default: () => [] },
    /**
     * Optional `(file) => Promise<{ok, payload}>` upload hook. When
     * provided, drag-drop + clipboard paste of images upload and splice
     * `![filename](url)` into the markdown at the caret.
     */
    uploadImage: { type: Function, default: null },
    /** Max edge (px) for client-side resize before upload. */
    imageMaxEdge: { type: Number, default: 2048 },
    /** WebP encoding quality (0-1). */
    imageQuality: { type: Number, default: 0.85 },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const {
    textareaRef,
    searchInputRef,
    applyInsert,
    showSlash,
    slashIndex,
    slashPosition,
    filteredCommands,
    selectCommand,
    highlightCommand,
    showSuggestions,
    suggestionQuery,
    suggestionIndex,
    suggestionPosition,
    filteredSuggestions,
    selectSuggestion,
    highlightSuggestion,
    onSearchKeydown,
    onSearchBlur,
    onInput,
    onKeydown,
    onBlur,
} = useNoteEditorTextarea({
    emitUpdate: (value) => emit("update:modelValue", value),
    t,
    flatNotes: toRef(props, "flatNotes"),
    untitledLabel: t("notes.markdown.untitled"),
});

// Image upload (drag-drop + Ctrl+V). Only active when the parent
// provides an upload hook - the editor degrades gracefully without it.
if (props.uploadImage) {
    useNoteImageUpload({
        textareaRef,
        uploadImage: props.uploadImage,
        applyInsert,
        t,
        maxEdge: props.imageMaxEdge,
        quality: props.imageQuality,
    });
}
</script>

<template>
    <div class="relative h-full w-full">
        <!-- No frame: the editor is the pane, and its bounds are the pane's.
             The padding stays, so the text keeps its margin. Focus shows as the
             caret, which is what a text field has instead of a ring - dropping
             the ring here loses nothing a keyboard user relies on. -->
        <textarea
            ref="textareaRef"
            :value="modelValue"
            :placeholder="placeholder"
            class="block h-full w-full bg-transparent px-3 py-2 text-primary placeholder-muted outline-none resize-none font-mono text-sm"
            rows="20"
            v-on:input="onInput"
            v-on:keydown="onKeydown"
            v-on:blur="onBlur"
        />

        <!-- Slash command palette ('/' at line start) -->
        <AppFloatingMenu
            v-if="showSlash"
            :items="filteredCommands"
            :position="slashPosition"
            :max-height="slashPosition.maxHeight ?? null"
            :active-index="slashIndex"
            v-on:select="selectCommand"
            v-on:highlight="highlightCommand"
        >
            <template #default="{ item }">
                <span class="inline-flex w-6 shrink-0 justify-center font-mono text-xs text-muted">
                    {{ item.icon }}
                </span>
                <span class="flex-1">{{ item.label }}</span>
            </template>
            <template #empty>
                {{ t('notes.markdown.slash.no_results') }}
            </template>
        </AppFloatingMenu>

        <!-- Wiki-link autocomplete ('[[' anywhere on a line) - header
             shows the inline filter so the user sees "what they're
             searching for". Keystrokes still go into the textarea (the
             actual source of truth) so this stays a passive display. -->
        <AppFloatingMenu
            v-if="showSuggestions"
            :items="filteredSuggestions"
            :position="suggestionPosition"
            :max-height="suggestionPosition.maxHeight ?? null"
            :active-index="suggestionIndex"
            v-on:select="selectSuggestion"
            v-on:highlight="highlightSuggestion"
        >
            <template #header>
                <!-- Real, focusable search field. `AppSearchInput` is our
                     shared search-bar component (built on `AppInput` with
                     a magnifier prefix and a clearable X). We disable
                     debouncing because filtering must feel instant, and
                     listen on `focusout` rather than `blur` because blur
                     events don't bubble through the wrapping <div>. -->
                <div class="p-2">
                    <AppSearchInput
                        ref="searchInputRef"
                        v-model="suggestionQuery"
                        :placeholder="t('notes.markdown.wiki_search_placeholder')"
                        :debounce="0"
                        :clearable="false"
                        v-on:keydown="onSearchKeydown"
                        v-on:focusout="onSearchBlur"
                    />
                </div>
            </template>
            <template #default="{ item }">
                <FileText class="w-3.5 h-3.5 shrink-0 text-muted" :stroke-width="2" />
                <span class="flex-1 truncate">
                    {{ item.title || t('notes.markdown.untitled') }}
                </span>
            </template>
            <template #empty>
                {{ t('notes.markdown.wiki_no_results', { query: suggestionQuery }) }}
            </template>
        </AppFloatingMenu>
    </div>
</template>
