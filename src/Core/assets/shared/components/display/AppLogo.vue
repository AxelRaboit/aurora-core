<script setup>
/**
 * Aurora's mark, "Horizon" (10/10/2026): an A whose hollow holds a rising
 * sun, on a night tile. It replaces a drawn letter on an accent square.
 *
 * The tile and the letter keep the brand's colours whatever the theme - a
 * logo that changes with every site is no logo - and only the sun follows
 * the accent the admin picked: `currentColor` plus `text-accent-500`, which
 * the active theme defines at runtime. With the default violet it is the
 * mark exactly as drawn; the full set lives in Proton (`06 favicon et
 * logo/aurora/horizon`).
 *
 * The note is here and not above the `<svg>` on purpose: a comment before the
 * root element gives the component two root nodes, and Vue cannot pass a parent's
 * attributes to a component that has no single root. That is not theoretical -
 * `AppSidemenu` passes `class="shrink-0"` to this component and it was being
 * dropped, so the logo could squash when the sidebar narrowed.
 */
defineProps({
    size: { type: Number, default: 40 },
});
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 64 64"
        xmlns="http://www.w3.org/2000/svg"
        class="text-accent-500"
        role="img"
        aria-label="Aurora"
    >
        <rect width="64" height="64" rx="14" fill="#130918" />
        <g transform="translate(6.4 7.2) scale(0.8)">
            <path data-logo-letter fill="#ece2d0" d="M32 8 L54 54 L44 54 L32 28.9 L20 54 L10 54 Z" />
            <path data-logo-sun fill="currentColor" d="M24.5 54 A7.5 7.5 0 0 1 39.5 54 Z" />
        </g>
    </svg>
</template>
