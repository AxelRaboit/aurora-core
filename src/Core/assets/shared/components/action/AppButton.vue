<script setup>
import { computed } from "vue";
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
    /**
     * The name of the button, when it goes with an icon passed in the slot.
     * Required with `iconOnlyOnPhone`: it is what stays as the tooltip and for
     * screen readers when the word disappears.
     */
    label: { type: String, default: null },
    /**
     * **A bar command, icon only below `sm`** (02/10/2026). The word goes, the
     * button becomes a square the height of its neighbours, and keeps its
     * background or its border: in a bar, a command is a real button. Reserved
     * for bars that stay horizontal; a full-width command keeps its name (see
     * {@see AppPageActions}).
     */
    iconOnlyOnPhone: { type: Boolean, default: false },
    /**
     * Always icon only, at every width: a bar toggle (favourite) whose name
     * would sit badly next to the others. Same square, same background, the
     * name as a tooltip and for screen readers.
     */
    iconOnly: { type: Boolean, default: false },
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
    // **A transparent border on solid buttons** (02/10/2026): without it, a
    // primary was two pixels shorter than a secondary or a ghost placed next to
    // it (36 against 38 in md), and a header bar lined up three different
    // heights. All the buttons of one size have the same height.
    //
    // **Disabled, a solid button goes grey**, not a washed-out green: the
    // customer page's Enregistrer, inactive while nothing changed, read as a
    // display bug at half its colour (UI audit of 07/10/2026).
    primary: 'bg-accent-600 hover:bg-accent-700 text-white border border-transparent focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-accent-500 disabled:bg-surface-3 disabled:text-secondary disabled:border-line disabled:hover:bg-surface-3',
    secondary: 'bg-surface-3 hover:bg-surface-2 text-primary border border-line focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-base',
    danger: 'bg-rose-600 hover:bg-rose-500 text-white border border-transparent focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-rose-500',
    'danger-outline': 'bg-transparent hover:bg-rose-500/10 text-rose-400 border border-line focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-rose-500',

    accent: 'bg-accent hover:bg-accent-hover text-white border border-transparent focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-accent disabled:bg-surface-3 disabled:text-secondary disabled:border-line disabled:hover:bg-surface-3',
    // **Never transparent on a phone.** A ghost button reads with a mouse: it
    // waits for the hover to exist, and its place in a dense bar already says
    // that it is one. With a finger there is no hover, and a full-width button
    // without a background looks like a line of text - nobody knows it can be
    // pressed. Below `sm` it therefore carries a muted surface and a border;
    // above, it becomes the ghost it should be again.
    ghost: 'bg-surface-2 border border-line text-secondary hover:bg-surface-2 hover:text-primary sm:border-transparent sm:bg-transparent',
    dashed: 'bg-transparent border-2 border-dashed border-line text-secondary hover:bg-surface-2 hover:text-primary',
    link: 'bg-transparent text-muted hover:text-secondary underline p-0 text-sm',
    'link-accent': 'bg-transparent text-accent hover:underline p-0 text-sm',
    icon: 'bg-transparent p-0',
    // Sidemenu list entry: full-width, left-aligned, surface card. Combine with `active` to highlight the selected row.
    nav: '!justify-start text-left w-full bg-surface hover:bg-surface-2 text-primary border border-line',
    // Frontend - use inside a parent that sets the text color via CSS variable
    'front-ghost': 'bg-transparent hover:opacity-80 transition-opacity',
    'front-primary': 'bg-transparent text-primary hover:opacity-80 transition-opacity',
    'front-accent': 'bg-transparent font-medium text-accent hover:opacity-80 transition-opacity',
};

const activeStyles = {
    nav: 'bg-accent-600/15 hover:bg-accent-600/15 text-accent-400 border-accent-600/30',
    // A bar toggle that is on (panel open): the accent tint, the same look as
    // the active tab of a switcher placed next to it.
    secondary: 'bg-accent-600/15 hover:bg-accent-600/20 text-accent-400 border-accent-600/30',
};

const sizes = {
    sm: 'py-1.5 px-3 text-xs',
    md: 'py-2 px-4 text-sm',
    lg: 'py-3 px-6 text-base',
    nav: 'px-3 py-2 text-sm',
    none: '',
};

// The square of an icon-only command, at the height of the size: an md
// button is 38 px high with its border, so 38 wide.
// The height too: without the word, only the icon (16 px) is left to give
// it, and the square dropped to 34 px high next to neighbours of 38.
const squares = {
    sm: 'max-sm:size-7.5 max-sm:p-0',
    md: 'max-sm:size-9.5 max-sm:p-0',
    lg: 'max-sm:size-12.5 max-sm:p-0',
};
const alwaysSquares = {
    sm: 'size-7.5 p-0',
    md: 'size-9.5 p-0',
    lg: 'size-12.5 p-0',
};

// `icon` carries its own padding: the size's padding won (Tailwind orders
// `padding` before `padding-inline`) and gave a 26 × 38 target.
// Same for `iconOnly`: its `p-0` came after the size's `px-4` and the icon
// dropped to six pixels wide (seen on 02/10/2026). The `max-sm:` variants of
// `iconOnlyOnPhone` come later in the sheet and win on their own.
const sizeClass = computed(() => ('icon' === props.variant || props.iconOnly ? '' : (sizes[props.size] ?? sizes.md)));
const squareClass = computed(() => {
    if (props.iconOnly) return alwaysSquares[props.size] ?? alwaysSquares.md;

    return props.iconOnlyOnPhone ? (squares[props.size] ?? squares.md) : '';
});
// The word goes below `sm` (or always) but is still read: never `hidden`.
const labelClass = computed(() => {
    if (props.iconOnly) return 'sr-only';

    return props.iconOnlyOnPhone ? 'sr-only sm:not-sr-only' : '';
});
const titleText = computed(() => (props.iconOnly || props.iconOnlyOnPhone ? props.label : undefined));
</script>

<template>
    <a
        v-if="href"
        :href="href"
        :title="titleText"
        :class="[base, variants[variant] ?? variants.primary, sizeClass, squareClass, active ? activeStyles[variant] ?? '' : '']"
    >
        <slot />
        <span v-if="label" :class="labelClass">{{ label }}</span>
    </a>
    <button
        v-else
        :type="type"
        :disabled="disabled || loading"
        :title="titleText"
        :class="[base, variants[variant] ?? variants.primary, sizeClass, squareClass, active ? activeStyles[variant] ?? '' : '']"
        v-on:click="onClick"
    >
        <Loader2 v-if="loading" class="animate-spin h-4 w-4" :stroke-width="2" />
        <slot />
        <span v-if="label" :class="labelClass">{{ label }}</span>
    </button>
</template>
