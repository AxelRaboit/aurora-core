<script setup>
import { computed } from "vue";

/**
 * Coloured pill. Renders as a `<span>` by default, or as an `<a>` when an
 * `href` is provided - handy for status filters / counts that link to a
 * filtered list. The hover state is only added in linked mode.
 *
 * `color` accepts either a preset name (accent/rose/sky/amber/emerald/violet/
 * slate/gray) or a hex value (`#10B981`). When a hex is passed, the badge
 * renders with solid background + white text via inline style.
 */
const props = defineProps({
    color: { type: String, default: "gray" },
    href: { type: String, default: null },
    spinning: { type: Boolean, default: false },
    size: { type: String, default: "xs" }, // xs | sm
});

const sizes = {
    xs: "px-2 py-0.5 text-xs",
    sm: "px-3 py-1 text-sm",
};

const colors = {
    accent: "bg-accent-600/15 text-accent-400",
    rose: "bg-rose-500/15 text-rose-400",
    sky: "bg-sky-500/15 text-sky-400",
    amber: "bg-amber-500/15 text-amber-400",
    emerald: "bg-emerald-500/15 text-emerald-400",
    violet: "bg-violet-500/15 text-violet-400",
    slate: "bg-slate-500/15 text-slate-400",
    gray: "bg-surface-2 text-secondary",
};

const isHex = computed(() => /^#[0-9a-fA-F]{6}$/.test(props.color));
const presetClasses = computed(() => isHex.value ? "" : (colors[props.color] ?? colors.gray));
const hexStyle = computed(() => isHex.value ? { backgroundColor: props.color, color: "#fff" } : {});
</script>

<template>
    <component
        :is="href ? 'a' : 'span'"
        v-bind="href ? { href } : {}"
        class="inline-flex items-center gap-1 rounded-full font-medium"
        :class="[
            sizes[size] ?? sizes.xs,
            presetClasses,
            href ? 'hover:opacity-80 transition-opacity cursor-pointer' : '',
        ]"
        :style="hexStyle"
    >
        <span v-if="spinning" class="inline-block w-2.5 h-2.5 rounded-full border border-current border-t-transparent animate-spin shrink-0" />
        <slot />
    </component>
</template>
