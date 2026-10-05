<script setup>
import { computed } from "vue";

/**
 * Coloured tag chip for a GED document tag. Centralises the
 * border/background derivation from `tag.color` so the list, grid card,
 * mobile card and show page all render tags identically.
 *
 * `size` maps to a standard Tailwind text scale (no arbitrary values).
 */
const props = defineProps({
    tag: { type: Object, required: true },
    size: { type: String, default: "xs" }, // xs | sm | base
});

const textClass = computed(
    () => ({ xs: "text-xs", sm: "text-sm", base: "text-base" })[props.size] ?? "text-xs",
);

const colorStyle = computed(() =>
    props.tag.color
        ? {
            backgroundColor: props.tag.color + "22",
            borderColor: props.tag.color + "66",
            color: props.tag.color,
        }
        : {},
);
</script>

<template>
    <span
        class="inline-flex items-center leading-tight px-1.5 py-0.5 rounded-full border border-line"
        :class="textClass"
        :style="colorStyle"
    >{{ tag.name }}</span>
</template>
