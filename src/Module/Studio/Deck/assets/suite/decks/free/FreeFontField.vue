<script setup>
/**
 * Which family a text box is set in.
 *
 * **The deck's own two first**, by role: a box set in "the deck's headings"
 * follows the deck when its pair of fonts changes, which is what keeps free
 * slides in the same voice as the rest. Then the catalogue, by kind, each name
 * drawn in its own face so the choice is made by eye; then the files somebody
 * uploaded, which belong to every deck of the installation.
 *
 * Opening the list loads every family once, for its preview. They are small
 * Latin subsets and the browser keeps them, so the cost is paid the first time
 * the list opens, not on every slide.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown, Upload } from "lucide-vue-next";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppFilePickerButton from "@/shared/components/action/AppFilePickerButton.vue";
import { ensureFont, FONT_CATALOGUE, labelFor, stackFor, uploadedFonts } from "./fonts.js";

const props = defineProps({
    modelValue: { type: String, default: "body" },
    label: { type: String, default: "" },
    appearance: { type: Object, default: null },
    /** Whether the person may add a font file of their own. */
    canUpload: { type: Boolean, default: false },
    uploading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "upload"]);

const { t } = useI18n();

const open = ref(false);
const query = ref("");

const CATEGORIES = ["sans", "serif", "display", "script", "mono"];

const matches = (name) => name.toLowerCase().includes(query.value.trim().toLowerCase());

const groups = computed(() =>
    CATEGORIES.map((category) => ({
        category,
        fonts: FONT_CATALOGUE.filter((font) => font.category === category && matches(font.name)),
    })).filter((group) => group.fonts.length),
);

const uploads = computed(() => uploadedFonts().filter((font) => matches(font.name)));

watch(open, (now) => {
    if (!now) return;

    for (const font of FONT_CATALOGUE) ensureFont(font.key);
    for (const font of uploadedFonts()) ensureFont(font.key);
});

const current = computed(() => {
    if (props.modelValue === "heading") return t("suite.studio.decks.free.font_heading");
    if (!props.modelValue || props.modelValue === "body") return t("suite.studio.decks.free.font_body");

    return labelFor(props.modelValue);
});

const currentStack = computed(() => {
    if (props.modelValue === "heading") return props.appearance?.headingFont;
    if (!props.modelValue || props.modelValue === "body") return props.appearance?.bodyFont;

    return stackFor(props.modelValue);
});

function pick(key) {
    emit("update:modelValue", key);
    open.value = false;
}
</script>

<template>
    <div class="relative flex flex-col gap-1.5">
        <span v-if="label" class="text-xs font-medium uppercase tracking-wide text-secondary">{{ label }}</span>
        <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-left text-sm text-primary"
            :aria-expanded="open"
            v-on:click="open = !open"
        >
            <span class="truncate" :style="{ fontFamily: currentStack }">{{ current }}</span>
            <ChevronDown class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
        </button>

        <div v-if="open" class="flex flex-col gap-2 rounded-lg border border-line bg-surface p-2 shadow-lg">
            <AppSearchInput v-model="query" :placeholder="t('suite.studio.decks.free.font_search')" />

            <div class="flex max-h-80 flex-col gap-2 overflow-y-auto pr-1">
                <div v-if="!query" class="flex flex-col">
                    <p class="m-0 px-2 py-1 text-[0.65rem] uppercase tracking-wide text-muted">{{ t("suite.studio.decks.free.font_deck") }}</p>
                    <button
                        type="button"
                        class="cursor-pointer rounded-md border-0 bg-transparent px-2 py-1.5 text-left text-base text-primary hover:bg-surface-2"
                        :class="modelValue === 'heading' ? 'bg-accent-600/15' : ''"
                        :style="{ fontFamily: appearance?.headingFont }"
                        v-on:click="pick('heading')"
                    >
                        {{ t("suite.studio.decks.free.font_heading") }}
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer rounded-md border-0 bg-transparent px-2 py-1.5 text-left text-base text-primary hover:bg-surface-2"
                        :class="!modelValue || modelValue === 'body' ? 'bg-accent-600/15' : ''"
                        :style="{ fontFamily: appearance?.bodyFont }"
                        v-on:click="pick('body')"
                    >
                        {{ t("suite.studio.decks.free.font_body") }}
                    </button>
                </div>

                <div v-if="uploads.length || canUpload" class="flex flex-col">
                    <p class="m-0 px-2 py-1 text-[0.65rem] uppercase tracking-wide text-muted">{{ t("suite.studio.decks.free.font_uploaded") }}</p>
                    <button
                        v-for="font in uploads"
                        :key="font.key"
                        type="button"
                        class="cursor-pointer rounded-md border-0 bg-transparent px-2 py-1.5 text-left text-base text-primary hover:bg-surface-2"
                        :class="modelValue === font.key ? 'bg-accent-600/15' : ''"
                        :style="{ fontFamily: stackFor(font.key) }"
                        v-on:click="pick(font.key)"
                    >
                        {{ font.name }}
                    </button>
                    <AppFilePickerButton
                        v-if="canUpload"
                        accept=".woff2,.woff,.ttf,.otf"
                        variant="ghost"
                        size="sm"
                        :loading="uploading"
                        v-on:files="(files) => files?.[0] && emit('upload', files[0])"
                    >
                        <Upload class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("suite.studio.decks.free.font_upload") }}
                    </AppFilePickerButton>
                </div>

                <div v-for="group in groups" :key="group.category" class="flex flex-col">
                    <p class="m-0 px-2 py-1 text-[0.65rem] uppercase tracking-wide text-muted">
                        {{ t(`suite.studio.decks.free.font_categories.${group.category}`) }}
                    </p>
                    <button
                        v-for="font in group.fonts"
                        :key="font.key"
                        type="button"
                        class="cursor-pointer rounded-md border-0 bg-transparent px-2 py-1.5 text-left text-base text-primary hover:bg-surface-2"
                        :class="modelValue === font.key ? 'bg-accent-600/15' : ''"
                        :style="{ fontFamily: font.stack }"
                        v-on:click="pick(font.key)"
                    >
                        {{ font.name }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
