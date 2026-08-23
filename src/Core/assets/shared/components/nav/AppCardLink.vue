<script setup>
import { ChevronRight } from "lucide-vue-next";

defineProps({
    href: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, default: "" },
    /** Lucide-vue-next component to render in the leading badge. Optional. */
    icon: { type: [Object, Function], default: null },
    target: { type: String, default: null },
    /**
     * Color of the leading icon badge + hover accent.
     * Matches the palette tokens used elsewhere in the app.
     */
    color: {
        type: String,
        default: "accent",
        validator: (v) => ["accent", "emerald", "amber", "rose"].includes(v),
    },
    /** Hide the trailing chevron - useful for non-navigational uses. */
    hideChevron: { type: Boolean, default: false },
});

const BADGE_CLASSES = {
    accent: "bg-accent-600/10 text-accent-400",
    emerald: "bg-emerald-600/10 text-emerald-400",
    amber: "bg-amber-600/10 text-amber-400",
    rose: "bg-rose-600/10 text-rose-400",
};

const HOVER_CLASSES = {
    accent: "hover:border-accent-500/40",
    emerald: "hover:border-emerald-500/40",
    amber: "hover:border-amber-500/40",
    rose: "hover:border-rose-500/40",
};

const CHEVRON_HOVER_CLASSES = {
    accent: "group-hover:text-accent-400",
    emerald: "group-hover:text-emerald-400",
    amber: "group-hover:text-amber-400",
    rose: "group-hover:text-rose-400",
};
</script>

<template>
    <a
        :href="href"
        :target="target"
        :rel="target === '_blank' ? 'noopener' : undefined"
        class="block bg-surface border border-line/60 rounded-2xl p-4 sm:p-5 shadow-sm hover:bg-surface-2 transition-colors group"
        :class="HOVER_CLASSES[color]"
    >
        <div class="flex items-center gap-4">
            <div
                v-if="icon"
                class="shrink-0 w-10 h-10 rounded-xl flex items-center justify-center"
                :class="BADGE_CLASSES[color]"
            >
                <component :is="icon" class="w-5 h-5" :stroke-width="2" />
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-primary">
                    <slot name="title">{{ title }}</slot>
                </p>
                <p v-if="description || $slots.description" class="text-xs text-secondary mt-0.5">
                    <slot name="description">{{ description }}</slot>
                </p>
            </div>
            <ChevronRight
                v-if="!hideChevron"
                class="w-4 h-4 text-muted transition-colors shrink-0"
                :class="CHEVRON_HOVER_CLASSES[color]"
                :stroke-width="2"
            />
        </div>
    </a>
</template>
