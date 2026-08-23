<script setup>
/**
 * Scoped loader overlay.
 *
 * Renders a semi-transparent overlay with a centered spinner. Must be placed
 * inside a `position: relative` parent (e.g. a list/table wrapper) - the
 * overlay covers the parent only, not the whole viewport.
 *
 * Visual: shares the spinner styling with the global app loader (loader.css).
 *
 * Example:
 *   <div class="relative">
 *     <table>…</table>
 *     <AppLoader :active="loading" />
 *   </div>
 */
defineProps({
    active: { type: Boolean, default: false },
    size: { type: String, default: "md" }, // "sm" | "md" | "lg"
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div v-if="active" class="aurora-loader-scoped" aria-hidden="true">
            <div class="aurora-loader-spinner" :class="`aurora-loader-spinner--${size}`" />
        </div>
    </Transition>
</template>
