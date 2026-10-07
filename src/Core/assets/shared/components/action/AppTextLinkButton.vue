<script setup>
/**
 * Inline text-button styled like a hyperlink - used for compact actions
 * inside paragraphs or beside form fields ("Clear", "Show more", "Cancel
 * this change"). Picks `<button>` semantics (the action lives on the
 * current page, no navigation).
 *
 * For real navigation use AppLink. For prominent buttons use AppButton.
 */
defineProps({
    color: { type: String, default: "default", validator: (color) => ["default", "danger", "muted"].includes(color) },
    size: { type: String, default: "sm", validator: (size) => ["xs", "sm", "md"].includes(size) },
});

const colors = {
    default: "text-accent-500 hover:text-accent-400",
    danger: "text-danger hover:underline",
    muted: "text-muted hover:text-primary",
};
const sizes = { xs: "text-xs", sm: "text-sm", md: "text-base" };
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-1 hover:underline transition-colors"
        :class="[colors[color], sizes[size]]"
    >
        <slot />
    </button>
</template>
