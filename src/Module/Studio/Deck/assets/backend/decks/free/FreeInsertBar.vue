<script setup>
/**
 * What can be put on a free slide, one press away.
 *
 * Words first, in the three sizes a slide is written in; then what comes from
 * the library - a picture, a film - and from elsewhere, a YouTube or Vimeo
 * link; then what is drawn here: shapes, icons, a chart, a table. The shapes
 * and the icons open a tray under the bar rather than a modal, so the slide
 * stays in view while somebody picks the one that suits it.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
    ChartColumn,
    Film,
    Heading1,
    Heading2,
    Image as ImageIcon,
    Pilcrow,
    Shapes,
    Smile,
    Table,
    Youtube,
} from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";
import { FREE_ICONS } from "./icons.js";
import { BOXES, maskImage } from "./shapes.js";
import { embedPreview } from "./model.js";

const props = defineProps({
    shapes: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["insert"]);

const { t } = useI18n();

/** Which tray is open under the bar: shapes, icons, a link, or none. */
const tray = ref(null);

const toggle = (name) => {
    tray.value = tray.value === name ? null : name;
};

const TEXTS = {
    title: { html: "", size: 80, weight: 700, font: "heading", h: 18, w: 70, placeholder: true },
    subtitle: { html: "", size: 48, weight: 600, font: "heading", h: 12, w: 60, placeholder: true },
    body: { html: "", size: 28, weight: 400, font: "body", h: 18, w: 50, lineHeight: 1.4, placeholder: true },
};

function addText(kind) {
    const { placeholder, ...preset } = TEXTS[kind];

    emit("insert", "text", { ...preset, html: placeholder ? t(`backend.studio.decks.free.text_presets.${kind}`) : "" });
}

async function addPicture() {
    const item = await openDocumentPicker({ imagesOnly: true });

    if (!item) return;

    emit("insert", "image", { mediaId: item.id, mediaUrl: item.fileUrl ?? item.url ?? null });
}

async function addFilm() {
    const item = await openDocumentPicker({ mimePrefix: "video/" });

    if (!item) return;

    emit("insert", "video", { mediaId: item.id, videoUrl: item.fileUrl ?? item.url ?? null, autoplay: true, loop: true, muted: true });
}

const link = ref("");

/** A link added at once, previewed until the server resolves it on save. */
function addLink() {
    const url = link.value.trim();

    if (!/^https?:\/\//i.test(url)) return;

    emit("insert", "embed", embedPreview(url));

    link.value = "";
    tray.value = null;
}

function addShape(shape) {
    const line = shape === "line";

    emit("insert", "shape", line
        ? { shape, w: 30, h: 4, head: "none", stroke: { color: "ink", width: 6, style: "solid" }, fill: null }
        : { shape });
}

const iconQuery = ref("");

const icons = computed(() => {
    const query = iconQuery.value.trim().toLowerCase();

    return Object.entries(FREE_ICONS).filter(([name]) => !query || name.includes(query));
});

const shapeStyle = (shape) => {
    if (shape === "line") return { height: "3px", background: "currentColor", borderRadius: "9999px" };
    if (shape in BOXES) return { background: "currentColor", borderRadius: BOXES[shape] ?? "2px", aspectRatio: "1" };

    const image = maskImage(shape);

    return {
        background: "currentColor",
        aspectRatio: "1",
        maskImage: image,
        WebkitMaskImage: image,
        maskSize: "100% 100%",
        WebkitMaskSize: "100% 100%",
    };
};
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0 [&>*]:shrink-0" role="toolbar" :aria-label="t('backend.studio.decks.free.insert')">
            <AppButton variant="ghost" size="sm" :disabled="disabled" v-on:click="addText('title')">
                <Heading1 class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_title") }}
            </AppButton>
            <AppButton variant="ghost" size="sm" :disabled="disabled" v-on:click="addText('subtitle')">
                <Heading2 class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_subtitle") }}
            </AppButton>
            <AppButton variant="ghost" size="sm" :disabled="disabled" v-on:click="addText('body')">
                <Pilcrow class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_body") }}
            </AppButton>
            <span class="mx-1 h-5 w-px bg-line" />
            <AppButton variant="ghost" size="sm" :disabled="disabled" v-on:click="addPicture">
                <ImageIcon class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_image") }}
            </AppButton>
            <AppButton variant="ghost" size="sm" :disabled="disabled" v-on:click="addFilm">
                <Film class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_video") }}
            </AppButton>
            <AppButton
                variant="ghost"
                size="sm"
                :disabled="disabled"
                :active="tray === 'link'"
                v-on:click="toggle('link')"
            >
                <Youtube class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_embed") }}
            </AppButton>
            <span class="mx-1 h-5 w-px bg-line" />
            <AppButton
                variant="ghost"
                size="sm"
                :disabled="disabled"
                :active="tray === 'shapes'"
                v-on:click="toggle('shapes')"
            >
                <Shapes class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_shape") }}
            </AppButton>
            <AppButton
                variant="ghost"
                size="sm"
                :disabled="disabled"
                :active="tray === 'icons'"
                v-on:click="toggle('icons')"
            >
                <Smile class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_icon") }}
            </AppButton>
            <AppButton variant="ghost" size="sm" :disabled="disabled" v-on:click="emit('insert', 'chart', {})">
                <ChartColumn class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_chart") }}
            </AppButton>
            <AppButton variant="ghost" size="sm" :disabled="disabled" v-on:click="emit('insert', 'table', {})">
                <Table class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.free.insert_table") }}
            </AppButton>
        </div>

        <div v-if="tray === 'link'" class="flex flex-wrap items-end gap-2 rounded-lg border border-line p-2">
            <AppInput
                v-model="link"
                class="min-w-56 flex-1"
                :label="t('backend.studio.decks.free.embed_url')"
                :placeholder="t('backend.studio.decks.free.embed_placeholder')"
                v-on:keydown.enter.prevent="addLink"
            />
            <AppButton variant="primary" size="md" :disabled="!link.trim()" v-on:click="addLink">
                {{ t("backend.studio.decks.free.embed_add") }}
            </AppButton>
        </div>

        <div v-if="tray === 'shapes'" class="grid grid-cols-5 gap-1 rounded-lg border border-line p-2 sm:grid-cols-10">
            <button
                v-for="shape in shapes"
                :key="shape"
                type="button"
                class="flex h-12 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent p-2 text-accent-400 hover:bg-surface-2"
                :title="t(`backend.studio.decks.free.shapes.${shape}`)"
                v-on:click="addShape(shape)"
            >
                <span class="block w-full max-w-8" :style="shapeStyle(shape)" />
            </button>
        </div>

        <div v-if="tray === 'icons'" class="flex flex-col gap-2 rounded-lg border border-line p-2">
            <AppSearchInput v-model="iconQuery" :debounce="0" :placeholder="t('backend.studio.decks.free.icon_search')" />
            <div class="grid max-h-48 grid-cols-6 gap-1 overflow-y-auto sm:grid-cols-12">
                <button
                    v-for="[name, component] in icons"
                    :key="name"
                    type="button"
                    class="flex h-10 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent text-primary hover:bg-surface-2"
                    :title="name"
                    v-on:click="emit('insert', 'icon', { icon: name })"
                >
                    <component :is="component" class="h-5 w-5" :stroke-width="2" />
                </button>
            </div>
        </div>
    </div>
</template>
