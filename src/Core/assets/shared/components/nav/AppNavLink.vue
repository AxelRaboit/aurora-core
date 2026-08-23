<script setup>
import { computed } from "vue";
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";

const props = defineProps({
    href: { type: String, required: true },
    active: { type: Boolean, default: false },
    /** Color used for the active highlight bg + text. */
    activeColor: { type: String, default: "accent" },
    /** Color used for the hover state - defaults to "primary" (neutral). Use "emerald", "amber", "rose", "accent" for branded actions. */
    hoverColor: { type: String, default: "primary" },
    target: { type: String, default: null },
    sidemenuActive: { type: Boolean, default: false },
    /** Tooltip title shown on hover (typically the item label, useful when collapsed). */
    tooltipTitle: { type: String, default: "" },
    /** Optional secondary line for the tooltip - short helper sentence. */
    tooltipDescription: { type: String, default: "" },
    /** Tooltip placement around the trigger. Default `right` matches the left-anchored sidemenu. */
    tooltipPlacement: {
        type: String,
        default: "right",
        validator: (value) => ["right", "left", "top", "bottom"].includes(value),
    },
    /**
     * Optional override for the link's active / hover Tailwind class
     * string - supersedes the internal `activeColor` + `hoverColor`
     * maps. The sidemenu uses this to inject per-section colours from
     * `useSidemenuSectionTheme` without having to extend the local map.
     * Pass a single string already containing both active *and* hover
     * variants; the consumer is responsible for picking based on the
     * active state.
     */
    linkClassesOverride: { type: String, default: "" },
});

const ACTIVE_CLASSES = {
    accent: "bg-accent-600/15 text-accent-400",
    rose: "bg-rose-600/15 text-rose-400",
};

const HOVER_CLASSES = {
    primary: "text-secondary hover:text-primary hover:bg-surface-2",
    emerald: "text-secondary hover:text-emerald-400 hover:bg-emerald-500/10",
    amber: "text-secondary hover:text-amber-400 hover:bg-amber-500/10",
    rose: "text-secondary hover:text-rose-400 hover:bg-rose-600/10",
    accent: "text-secondary hover:text-accent-400 hover:bg-accent-600/10",
};

const linkClasses = computed(() => {
    if (props.linkClassesOverride) {
        return props.linkClassesOverride;
    }
    if (props.active) {
        return ACTIVE_CLASSES[props.activeColor] ?? ACTIVE_CLASSES.accent;
    }
    const effectiveHoverColor = props.hoverColor !== "primary" ? props.hoverColor : props.activeColor;
    return HOVER_CLASSES[effectiveHoverColor] ?? HOVER_CLASSES.primary;
});
</script>

<template>
    <AppTooltip :title="tooltipTitle" :description="tooltipDescription" :placement="tooltipPlacement">
        <a
            :href="href"
            :target="target"
            :rel="target === '_blank' ? 'noopener' : undefined"
            :data-sidemenu-active="sidemenuActive ? 'true' : null"
            class="si flex items-center rounded-lg text-sm font-medium transition-colors group relative"
            :class="linkClasses"
        >
            <slot />
        </a>
    </AppTooltip>
</template>
