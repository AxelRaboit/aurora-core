<script setup>
import { computed } from "vue";
import { FileText } from "lucide-vue-next";

/**
 * Renders a file preview by MIME type: inline image, embedded PDF, or an
 * icon + mime fallback for anything else. Used in edit/detail modals that
 * want to show "what this file is" without each one re-implementing the
 * image-vs-pdf-vs-other branching.
 *
 * Media's edit modal keeps its own image branch (it overlays a focal-point
 * picker), but any plain preview (GED, future modules) should use this.
 *
 * The image is capped, never stretched: `max-width` rather than `width`, so a
 * small file is shown at the size it is instead of being blown up to the width
 * of whatever holds it. `maxHeight` caps the other axis, and `object-contain`
 * keeps the proportions when both bite.
 */
const props = defineProps({
    url: { type: String, default: "" },
    mime: { type: String, default: "" },
    name: { type: String, default: "" },
    alt: { type: String, default: "" },
    /** CSS height of the pdf/image frame (inline, so no Tailwind purge issues). */
    maxHeight: { type: String, default: "18rem" },
});

const isImage = computed(() => props.mime.startsWith("image/"));
const isPdf = computed(() => props.mime === "application/pdf");
</script>

<template>
    <div v-if="url" class="bg-surface-2 rounded-md overflow-hidden">
        <img
            v-if="isImage"
            :src="url"
            :alt="alt || name"
            class="mx-auto block h-auto max-w-full object-contain"
            :style="{ maxHeight }"
        >
        <iframe
            v-else-if="isPdf"
            :src="url"
            class="w-full border-0"
            :style="{ height: maxHeight }"
            :title="name"
        />
        <div v-else class="p-8 flex flex-col items-center">
            <FileText class="w-12 h-12 text-muted" :stroke-width="1.5" />
            <p class="mt-2 text-xs text-muted">{{ mime }}</p>
        </div>
    </div>
</template>
