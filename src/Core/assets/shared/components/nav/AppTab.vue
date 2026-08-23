<script setup>
import { computed } from "vue";

const props = defineProps({
    active: { type: Boolean, default: false },
    /** Visual variant - `pill` for filters/sidemenu, `underline` for in-card panel switchers. */
    variant: { type: String, default: "pill" }, // pill | underline
    color: { type: String, default: "accent" }, // accent | rose | violet
    size: { type: String, default: "md" }, // md | sm | xs
    align: { type: String, default: "left" }, // left | center
    /** Escape hatches: override the resolved active/inactive classes (e.g. for per-item colour like deal stages). */
    activeClass: { type: String, default: null },
    inactiveClass: { type: String, default: null },
    /** When set, overrides the variant's base radius/shape (e.g. `rounded-full` for chip-like pills). */
    shapeClass: { type: String, default: null },
});

const variants = {
    pill: {
        base: "rounded-lg font-medium transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed",
        sizes: {
            md: "px-3 py-2 text-sm",
            sm: "px-3 py-1.5 text-sm",
            xs: "px-2 py-0.5 text-xs",
        },
        active: {
            accent: "bg-accent-600/15 text-accent-400",
            rose: "bg-rose-500/15 text-rose-400",
            violet: "bg-violet-500/15 text-violet-400",
        },
        inactive: "text-secondary hover:text-primary hover:bg-surface-2",
    },
    underline: {
        base: "font-medium transition-colors flex items-center gap-1.5 border-b-2 -mb-px whitespace-nowrap shrink-0 disabled:opacity-50 disabled:cursor-not-allowed",
        sizes: {
            md: "px-4 py-2 text-sm",
            sm: "px-3 py-1.5 text-sm",
            xs: "px-2 py-0.5 text-xs",
        },
        active: {
            accent: "border-accent-500 text-accent-400",
            rose: "border-rose-500 text-rose-400",
            violet: "border-violet-500 text-violet-400",
        },
        inactive: "border-transparent text-secondary hover:text-primary",
    },
};

const aligns = {
    left: "text-left",
    center: "justify-center",
};

const classes = computed(() => {
    const v = variants[props.variant] ?? variants.pill;
    const activeFallback = v.active[props.color] ?? v.active.accent;
    return [
        v.base,
        v.sizes[props.size] ?? v.sizes.md,
        aligns[props.align] ?? aligns.left,
        props.shapeClass,
        props.active
            ? props.activeClass ?? activeFallback
            : props.inactiveClass ?? v.inactive,
    ];
});
</script>

<template>
    <button type="button" :class="classes">
        <slot />
    </button>
</template>
