<script setup>
import { Loader2 } from "lucide-vue-next";

const props = defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    href: { type: String, default: null },
    /**
     * Visual "selected" state. Currently honored by the `nav` variant -
     * swaps the inactive surface styling for the accent-tinted look used
     * by sidemenu lists (taxonomies / post types).
     */
    active: { type: Boolean, default: false },
});

/**
 * Click handler that fixes the common modal-footer-submit pattern: a
 * `type="submit"` button placed in `<AppModalFooter>` lives OUTSIDE the
 * `<form>` it logically belongs to (form sits in the modal's default
 * slot, footer in `#footer` - they are siblings). Per HTML spec, a
 * submit button without a form owner does nothing on click. To keep
 * the natural authoring pattern working, manually call `requestSubmit()`
 * on the form located in the same modal/dialog scope.
 *
 * No-op when the button is already inside a `<form>` (native browser
 * handling takes over) or when `type !== "submit"`.
 */
function onClick(event) {
    if (props.type !== 'submit') return;
    if (props.disabled || props.loading) return;

    const button = event.currentTarget;
    if (button.form) return; // browser will submit natively

    // Look for the closest dialog/modal scope; fall back to document.
    const scope = button.closest('[role="dialog"]') ?? document;
    const form = scope.querySelector('form');
    if (form) {
        event.preventDefault();
        // `requestSubmit()` without the submitter arg - passing the button
        // would throw `NotFoundError: not owned by this form element`
        // precisely because the button lives in a sibling slot
        // (e.g. AppModal footer), which is the case we're working around.
        form.requestSubmit();
    }
}

const base = 'inline-flex items-center justify-center gap-2 rounded-lg transition duration-150 ease-in-out focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed';

const variants = {
    primary: 'bg-accent-600 hover:bg-accent-700 text-white focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-accent-500',
    secondary: 'bg-surface-3 hover:bg-surface-2 text-primary border border-line focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-base',
    danger: 'bg-rose-600 hover:bg-rose-500 text-white focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-rose-500',
    'danger-outline': 'bg-transparent hover:bg-rose-500/10 text-rose-400 border border-line focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-rose-500',

    accent: 'bg-accent hover:bg-accent-hover text-white focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-accent',
    ghost: 'bg-transparent hover:bg-surface-2 text-secondary hover:text-primary',
    dashed: 'bg-transparent border-2 border-dashed border-line text-secondary hover:bg-surface-2 hover:text-primary',
    link: 'bg-transparent text-muted hover:text-secondary underline p-0 text-sm',
    'link-accent': 'bg-transparent text-accent hover:underline p-0 text-sm',
    icon: 'bg-transparent p-0',
    // Sidemenu list entry: full-width, left-aligned, surface card. Combine with `active` to highlight the selected row.
    nav: '!justify-start text-left w-full bg-surface hover:bg-surface-2 text-primary border border-line/60',
    // Frontend - use inside a parent that sets the text color via CSS variable
    'front-ghost': 'bg-transparent hover:opacity-80 transition-opacity',
    'front-primary': 'bg-transparent text-primary hover:opacity-80 transition-opacity',
    'front-accent': 'bg-transparent font-medium text-accent hover:opacity-80 transition-opacity',
};

const activeStyles = {
    nav: 'bg-accent-600/15 hover:bg-accent-600/15 text-accent-400 border-accent-600/30',
};

const sizes = {
    sm: 'py-1.5 px-3 text-xs',
    md: 'py-2 px-4 text-sm',
    lg: 'py-3 px-6 text-base',
    nav: 'px-3 py-2 text-sm',
    none: '',
};
</script>

<template>
    <a
        v-if="href"
        :href="href"
        :class="[base, variants[variant] ?? variants.primary, sizes[size] ?? sizes.md, active ? activeStyles[variant] ?? '' : '']"
    >
        <slot />
    </a>
    <button
        v-else
        :type="type"
        :disabled="disabled || loading"
        :class="[base, variants[variant] ?? variants.primary, sizes[size] ?? sizes.md, active ? activeStyles[variant] ?? '' : '']"
        v-on:click="onClick"
    >
        <Loader2 v-if="loading" class="animate-spin h-4 w-4" :stroke-width="2" />
        <slot />
    </button>
</template>
