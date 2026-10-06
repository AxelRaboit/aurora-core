<script setup>
/**
 * The link that goes up from a screen.
 *
 * Four screens carried it, in four forms: a ghost button on the publication
 * editor, an anchor with an arrow in the media library, an anchor with a
 * chevron and a label that disappears on a phone in a client space, and a
 * text button in the notes. The same gesture, four drawings, only one of
 * which is right.
 *
 * **It is not an action, it is navigation.** A solid or ghost button puts it
 * at the same visual level as "Save", placed a centimetre away: on a
 * phone where the two end up one below the other, the screen no longer has
 * a hierarchy. A discreet link says it for what it is.
 *
 * **The label disappears below `sm`, everywhere, without exception.** It is
 * the find of the client space, taken up here: "Back to the list" takes
 * nearly half the bar for a word the chevron already says, and on a phone
 * that half is missing elsewhere. It is still read by a screen reader in
 * both cases, because `aria-label` carries it.
 *
 * No escape hatch to keep it visible: an option to do otherwise is an
 * invitation for each screen to decide, and that is exactly what this
 * component is here to fix.
 */
import { ChevronLeft } from "lucide-vue-next";

defineProps({
    /** An address, or nothing: without it, the component emits `back`. */
    href: { type: String, default: "" },
    label: { type: String, required: true },
});

const emit = defineEmits(["back"]);
</script>

<template>
    <!-- `inline-flex` and not `flex`: placed outside a row (a contract's
         page), a `flex` took the whole width and the entire line became
         clickable. Thirty-eight pixels on a phone, the height of the
         commands of the bar it sits in (02/10/2026).

         An anchor when there is an address, a button otherwise: the
         notebook goes back to its library without changing page, and an
         empty anchor would be a link that leads nowhere for someone
         navigating with the keyboard. Both carry the same look. -->
    <component
        :is="href ? 'a' : 'button'"
        :href="href || undefined"
        :type="href ? undefined : 'button'"
        :aria-label="label"
        class="-ml-2 inline-flex min-h-9.5 shrink-0 items-center gap-1.5 rounded-md px-2 py-2 text-sm text-muted transition-colors hover:bg-surface-2 hover:text-primary sm:min-h-0 sm:py-1"
        v-on:click="href ? undefined : emit('back')"
    >
        <ChevronLeft class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
        <span class="hidden sm:inline">{{ label }}</span>
    </component>
</template>
