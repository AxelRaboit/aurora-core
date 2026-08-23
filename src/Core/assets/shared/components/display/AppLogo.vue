<script setup>
/**
 * The mark, drawn to follow whatever accent colour the admin picked.
 *
 * `currentColor` plus `text-accent-500` makes it track the active theme's primary
 * colour, which defines `--color-accent-*` at runtime. The gradient uses two
 * opacities of the same hue so the depth survives any colour.
 *
 * The note is here and not above the `<svg>` on purpose: a comment before the
 * root element gives the component two root nodes, and Vue cannot pass a parent's
 * attributes to a component that has no single root. That is not theoretical -
 * `AppSidemenu` passes `class="shrink-0"` to this component and it was being
 * dropped, so the logo could squash when the sidebar narrowed.
 */
import { getCurrentInstance } from "vue";

defineProps({
    size: { type: Number, default: 40 },
});

const instanceUid = getCurrentInstance().uid;
const gradientId = `aurora-bg-${instanceUid}`;
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 64 64"
        xmlns="http://www.w3.org/2000/svg"
        class="text-accent-500"
    >
        <defs>
            <linearGradient
                :id="gradientId"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
            >
                <stop offset="0%" stop-color="currentColor" stop-opacity="1" />
                <stop offset="100%" stop-color="currentColor" stop-opacity="0.85" />
            </linearGradient>
        </defs>
        <rect width="64" height="64" rx="14" :fill="`url(#${gradientId})`" />
        <text
            x="32"
            y="45"
            font-family="'Inter', 'Segoe UI', sans-serif"
            font-size="36"
            font-weight="700"
            text-anchor="middle"
            fill="white"
        >V</text>
        <line
            x1="20"
            y1="52"
            x2="44"
            y2="52"
            stroke="rgba(255,255,255,0.4)"
            stroke-width="2.5"
            stroke-linecap="round"
        />
    </svg>
</template>
