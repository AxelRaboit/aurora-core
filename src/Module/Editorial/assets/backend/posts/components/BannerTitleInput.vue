<script setup>
/**
 * The banner title, with some of its words in colour.
 *
 * A one-line contenteditable rather than an input, because an input cannot
 * show colour. The toolbar applies the theme accent in one click, any colour
 * through the native picker, or removes it; see bannerTitleColor.js for why
 * nothing else survives.
 */
import { onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Eraser } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { ACCENT, clearSelection, colourSelection, serialise } from "../composables/bannerTitleColor.js";

const props = defineProps({
    modelValue: { type: String, default: "" },
    label: { type: String, default: "" },
    placeholder: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const editor = ref(null);
const custom = ref("#34d399");

// Written by hand, and only when the value comes from outside: rewriting
// innerHTML while typing would throw the caret back to the start.
function render(html) {
    if (editor.value && serialise(editor.value) !== (html ?? "")) {
        editor.value.innerHTML = html ?? "";
    }
}

onMounted(() => render(props.modelValue));
watch(() => props.modelValue, render);

function commit() {
    emit("update:modelValue", serialise(editor.value));
}

function apply(color) {
    if (colourSelection(editor.value, color)) {
        commit();
    }
}

function clear() {
    if (clearSelection(editor.value)) {
        commit();
    }
}

// A title is one line: Enter would insert a block the server then flattens.
function onKeydown(event) {
    if ("Enter" === event.key) {
        event.preventDefault();
    }
}

// Pasted text arrives as text, so a copied web page cannot bring its markup.
function onPaste(event) {
    event.preventDefault();
    const text = event.clipboardData?.getData("text/plain") ?? "";
    editor.value.ownerDocument.execCommand("insertText", false, text.replace(/\s+/g, " "));
}
</script>

<template>
    <div>
        <p v-if="label" class="text-sm font-medium text-primary mb-1">{{ label }}</p>
        <div class="rounded-lg border border-line bg-surface focus-within:border-accent">
            <div
                ref="editor"
                contenteditable="true"
                role="textbox"
                aria-multiline="false"
                :aria-label="label"
                :data-placeholder="placeholder"
                class="banner-title-input px-3 py-2 text-sm text-primary outline-none"
                v-on:input="commit"
                v-on:keydown="onKeydown"
                v-on:paste="onPaste"
            />
            <div class="flex flex-wrap items-center gap-2 border-t border-line px-2 py-1.5">
                <span class="text-xs text-muted">{{ t("backend.posts.banner.title_colour_hint") }}</span>
                <span class="flex-1" />
                <!-- mousedown.prevent keeps the selection in the title while
                     the button is pressed; a click would move focus first. -->
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-surface-2"
                    v-on:mousedown.prevent
                    v-on:click="apply(ACCENT)"
                >
                    <span class="inline-block h-3 w-3 rounded-full" style="background: var(--th-accent, #10b981)" />
                    {{ t("backend.posts.banner.title_colour_accent") }}
                </button>
                <label class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-surface-2 cursor-pointer" v-on:mousedown.prevent>
                    <input
                        v-model="custom"
                        type="color"
                        class="h-4 w-4 cursor-pointer border-0 bg-transparent p-0"
                        :aria-label="t('backend.posts.banner.title_colour_custom')"
                    >
                    <span v-on:click.prevent="apply(custom)">{{ t("backend.posts.banner.title_colour_custom") }}</span>
                </label>
                <AppIconButton color="default" :title="t('backend.posts.banner.title_colour_clear')" v-on:mousedown.prevent v-on:click="clear">
                    <Eraser class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
            </div>
        </div>
    </div>
</template>

<style scoped>
.banner-title-input:empty::before {
    content: attr(data-placeholder);
    color: var(--color-muted, #9ca3af);
    pointer-events: none;
}
</style>
