<script setup>
import { Check } from "lucide-vue-next";

/**
 * Round selection indicator overlaid on a card/thumbnail. Provides a single
 * visual language for "this item is selected" across the app: filled accent
 * pill with a white check when active, semi-transparent dark pill otherwise.
 *
 * Display only - wrap in a clickable element (button, AppOverlayIconButton)
 * for the actual toggle behavior.
 */
defineProps({
    active: { type: Boolean, default: false },
    size: { type: String, default: "sm", validator: (v) => ["xs", "sm", "md"].includes(v) },
});

const sizes = {
    xs: { box: "w-5 h-5", icon: "w-3 h-3" },
    sm: { box: "w-6 h-6", icon: "w-3.5 h-3.5" },
    md: { box: "w-7 h-7", icon: "w-4 h-4" },
};
</script>

<template>
    <span
        class="rounded-full inline-flex items-center justify-center transition-colors backdrop-blur-sm"
        :class="[sizes[size].box, active ? 'bg-accent-500 text-white' : 'bg-black/40 text-white/0 ring-1 ring-white/40']"
    >
        <Check :class="sizes[size].icon" :stroke-width="3" />
    </span>
</template>
