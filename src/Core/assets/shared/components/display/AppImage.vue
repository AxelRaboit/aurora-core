<script setup>
import { ref, watch } from "vue";
import { Image as ImageIcon } from "lucide-vue-next";
import { ImageLoadStatus } from "@/shared/utils/enums/imageLoadStatus.js";

const props = defineProps({
    src: { type: String, default: null },
    alt: { type: String, default: "" },
    objectFit: { type: String, default: "cover" }, // cover | contain | fill | none
    focalPoint: { type: String, default: "50% 50%" },
    loading: { type: String, default: "lazy" }, // lazy | eager
    rounded: { type: String, default: "" }, // any Tailwind rounded class
    fallbackIcon: { type: Boolean, default: true },
});

const status = ref(ImageLoadStatus.Idle); // idle | loading | loaded | error

function onLoad() { status.value = ImageLoadStatus.Loaded; }
function onError() { status.value = ImageLoadStatus.Error; }
function onLoadStart() { status.value = ImageLoadStatus.Loading; }

// Reset status whenever `src` changes - otherwise a previous error /
// loaded state persists and the new image either stays hidden (img
// element pruned by `v-if` on Error) or shows the wrong opacity.
// Real-world trigger: media picker swap in the Settings page where
// the same AppImage instance is reused across multiple selections.
watch(() => props.src, () => {
    status.value = ImageLoadStatus.Idle;
});
</script>

<template>
    <div class="relative w-full h-full overflow-hidden bg-surface-2" :class="rounded">
        <div
            v-if="status === ImageLoadStatus.Loading"
            class="absolute inset-0 animate-pulse bg-surface-3"
        />

        <img
            v-if="src && status !== ImageLoadStatus.Error"
            :src="src"
            :alt="alt"
            :loading="loading"
            class="w-full h-full transition-opacity duration-300"
            :class="[
                `object-${objectFit}`,
                status === ImageLoadStatus.Loaded ? 'opacity-100' : 'opacity-0',
            ]"
            :style="{ objectPosition: focalPoint }"
            v-on:loadstart="onLoadStart"
            v-on:load="onLoad"
            v-on:error="onError"
        >

        <div
            v-if="(status === ImageLoadStatus.Error || !src) && fallbackIcon"
            class="absolute inset-0 flex items-center justify-center"
        >
            <ImageIcon class="w-1/3 max-w-10 text-muted/40" :stroke-width="1.5" />
        </div>
    </div>
</template>
