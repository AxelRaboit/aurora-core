<script setup>
/**
 * The way up from a screen, the only one the app draws.
 *
 * Five drawings carried this one gesture (07/10/2026): this component, a
 * Twig copy in the client spaces, a link in the reading pages, a link of
 * its own in the client's presentation, and a dead link in a shared note.
 * The copies had drifted. Its Twig twin,
 * `@Shared/components/back_link.html.twig`, writes the same HTML for the
 * pages the server renders, and `BackLinkRuleTest` refuses any other.
 *
 * **On the gutter, at the height of the bar.** The box used to start 8px
 * before the content (`-ml-2`) so that the chevron, not the box, met the
 * gutter: on a phone, where the gutter is 8px, the box touched the edge of
 * the screen. Its edge is now the gutter, like the cards and the language
 * switch below it. Thirty-eight pixels high at every width, as the commands
 * of the same bar: a square holding the chevron below `sm`, the chevron and
 * the destination above.
 *
 * **It names where it goes, never "Back".** The label is the parent the
 * breadcrumb shows. Hidden below `sm` but still the element's text, so a
 * screen reader reads it and voice control can say it.
 *
 * **It is not an action, it is navigation.** Outlined, never filled: it
 * keeps its rank under "Save", placed a centimetre away.
 *
 * **Back to the list as it was left.** When the previous page is the very
 * list it leads to, a click goes back in history rather than reloading the
 * list: its filters, page and scroll position come back. Anything else, a
 * modified click or a page reached from elsewhere, follows the link.
 */
import { ChevronLeft, X } from "lucide-vue-next";

const props = defineProps({
    /** The parent's address. */
    href: { type: String, default: "" },
    label: { type: String, required: true },
    /**
     * Handles the way back without leaving the page (the notebook returning
     * to its library). Declared as a prop rather than an event so that a
     * listener is known to exist: the link keeps its address for a new tab.
     */
    onBack: { type: Function, default: null },
    /** A preview opened in a new tab: a cross, the way out closes the tab. */
    closes: { type: Boolean, default: false },
});

function cameFromParent() {
    if (!document.referrer || window.history.length < 2) return false;

    try {
        const previous = new URL(document.referrer);
        const parent = new URL(props.href, window.location.href);

        return previous.origin === parent.origin && previous.pathname === parent.pathname;
    } catch {
        return false;
    }
}

function onClick(event) {
    if (event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    if (props.onBack) {
        event.preventDefault();
        props.onBack();

        return;
    }

    if (props.href && cameFromParent()) {
        event.preventDefault();
        window.history.back();
    }
}
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        :href="href || undefined"
        :type="href ? undefined : 'button'"
        :title="label"
        data-back-link
        class="inline-flex size-9.5 shrink-0 items-center justify-center gap-1.5 rounded-lg border border-line text-sm text-secondary transition-colors hover:bg-surface-2 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-500 sm:w-auto sm:justify-start sm:pl-2.5 sm:pr-3"
        v-on:click="onClick"
    >
        <component :is="closes ? X : ChevronLeft" class="h-4 w-4 shrink-0" :stroke-width="2" aria-hidden="true" />
        <span class="sr-only sm:not-sr-only sm:max-w-64 sm:truncate">{{ label }}</span>
    </component>
</template>
