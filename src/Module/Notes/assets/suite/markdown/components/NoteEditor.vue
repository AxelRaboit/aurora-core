<script setup>
import { toRef } from "vue";
import { useI18n } from "vue-i18n";
import { useNoteEditorTextarea } from "@notes/suite/markdown/composables/useNoteEditorTextarea.js";
import { useNoteImageUpload } from "@notes/suite/markdown/composables/useNoteImageUpload.js";
import AppFloatingMenu from "@shared/components/overlay/AppFloatingMenu.vue";
import NoteRemoteCarets from "@notes/suite/markdown/components/NoteRemoteCarets.vue";
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
    /**
     * The other people's carets, as the live room reports them:
     * `[{userId, name, index}]`. Empty without a hub, and then nothing is
     * drawn.
     */
    cursors: { type: Array, default: () => [] },
    /** The notebook's tags, offered after `#` (09/10/2026). */
    allTags: { type: Array, default: () => [] },
    /** `(url) => Promise<string|null>`: a pasted address's page title. */
    fetchLinkTitle: { type: Function, default: null },
});

const emit = defineEmits(["update:modelValue", "caret", "block-link"]);

const { t, locale } = useI18n();

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
    emojiMenu,
    tagMenu,
    selectEmoji,
    selectTag,
    onInput,
    onKeydown,
    onBlur,
    onPaste,
} = useNoteEditorTextarea({
    emitUpdate: (value) => emit("update:modelValue", value),
    t,
    flatNotes: toRef(props, "flatNotes"),
    untitledLabel: t("notes.markdown.untitled"),
    locale: String(locale.value ?? "fr").slice(0, 2),
    allTags: toRef(props, "allTags"),
    fetchLinkTitle: props.fetchLinkTitle,
    onBlockLink: (id) => emit("block-link", id),
});

/**
 * The editor's textarea, for the parent: the help panel inserts its examples
 * at the caret (09/10/2026).
 */
defineExpose({ textareaRef });

/**
 * Says where this reader's caret is, on every event that could have moved it.
 *
 * `selectionchange` would be the right event and it is not reliable on a
 * textarea across engines, so the three that actually move a caret are
 * listened to instead. Throttling belongs to the parent: it is the one that
 * knows this goes on a network.
 */
function reportCaret() {
    emit("caret", textareaRef.value?.selectionStart ?? null);
}

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
        <textarea
            ref="textareaRef"
            :value="modelValue"
            :placeholder="placeholder"
            class="block h-full w-full rounded-md border border-line bg-surface px-3 py-2 text-primary placeholder-muted focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition resize-none font-mono text-sm"
            rows="20"
            v-on:input="onInput"
            v-on:keydown="onKeydown"
            v-on:blur="onBlur"
            v-on:paste="onPaste"
            v-on:keyup="reportCaret"
            v-on:click="reportCaret"
            v-on:select="reportCaret"
        />

        <!-- Over the field, never in it: a textarea cannot show a second
             caret, so the others' are drawn beside it. -->
        <NoteRemoteCarets
            v-if="cursors.length"
            :textarea="textareaRef"
            :cursors="cursors"
            :text="modelValue"
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

        <!-- Emoji by name (':') and tags ('#'), 09/10/2026. -->
        <AppFloatingMenu
            v-if="emojiMenu.show.value"
            :items="emojiMenu.items.value"
            :position="emojiMenu.position.value"
            :max-height="emojiMenu.position.value.maxHeight ?? null"
            :active-index="emojiMenu.index.value"
            v-on:select="selectEmoji"
            v-on:highlight="emojiMenu.highlight"
        >
            <template #default="{ item }">
                <span class="inline-flex w-6 shrink-0 justify-center text-base">{{ item.emoji }}</span>
                <span class="flex-1 truncate">{{ item.label }}</span>
                <span v-if="item.shortcodes[0]" class="shrink-0 font-mono text-2xs text-muted">:{{ item.shortcodes[0] }}:</span>
            </template>
        </AppFloatingMenu>
        <AppFloatingMenu
            v-if="tagMenu.show.value"
            :items="tagMenu.items.value"
            :position="tagMenu.position.value"
            :max-height="tagMenu.position.value.maxHeight ?? null"
            :active-index="tagMenu.index.value"
            v-on:select="selectTag"
            v-on:highlight="tagMenu.highlight"
        >
            <template #default="{ item }">
                <span class="inline-flex w-6 shrink-0 justify-center font-mono text-xs text-muted">#</span>
                <span class="flex-1 truncate">{{ item.tag }}</span>
            </template>
        </AppFloatingMenu>
    </div>
</template>
