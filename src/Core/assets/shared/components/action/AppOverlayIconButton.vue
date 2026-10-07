<script setup>
/**
 * Icon-only button designed to live on a dark/translucent overlay (lightbox,
 * photo cards hover, gallery viewers). Sibling of AppIconButton, which is
 * meant for light backgrounds.
 *
 * Variants:
 *   - default : bg-black/40 → bg-black/60   (overlay on a photo)
 *   - light   : bg-white/10 → bg-white/20   (overlay on a fully dark backdrop)
 *   - danger  : like default but bg-red-600 on hover
 *
 * `active=true` switches the icon color to accent (e.g. picked heart).
 */
const props = defineProps({
    size: { type: String, default: "md", validator: (size) => ["xs", "sm", "md", "lg"].includes(size) },
    variant: { type: String, default: "default", validator: (variant) => ["default", "light", "danger"].includes(variant) },
    active: { type: Boolean, default: false },
    title: { type: String, default: null },
    ariaLabel: { type: String, default: null },
});

const sizes = {
    xs: "w-6 h-6",
    sm: "w-8 h-8",
    md: "w-10 h-10",
    lg: "w-12 h-12",
};

const variants = {
    default: "bg-black/40 hover:bg-black/60 text-white/80 hover:text-white",
    light: "bg-white/10 hover:bg-white/20 text-white",
    danger: "bg-black/40 hover:bg-red-600 text-white/80 hover:text-white",
};

const computedAriaLabel = props.ariaLabel ?? props.title ?? undefined;
</script>

<template>
    <button
        type="button"
        :title="title"
        :aria-label="computedAriaLabel"
        class="rounded-full inline-flex items-center justify-center backdrop-blur-sm transition-colors"
        :class="[
            sizes[size],
            active ? 'text-accent-500 bg-black/40 hover:bg-black/60' : variants[variant],
        ]"
    >
        <slot />
    </button>
</template>
