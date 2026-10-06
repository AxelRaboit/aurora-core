<script setup>
/**
 * A free slide's elements, stacked in the order they are listed.
 *
 * Drawn by `SlideFrame`, in every one of its places - thumbnail, editor,
 * player, presenter, print, share link - so a free slide looks the same in
 * all of them for the reason a laid-out one does: there is one drawing.
 *
 * **A reveal is a press.** An element whose `reveal` is 2 comes in on the
 * second press after the slide appears; one without a reveal is there with
 * the slide. The player counts the presses (`revealableIn`), this only reads
 * how many have been made.
 */
import { computed } from "vue";
import FreeElement from "./FreeElement.vue";
import { useFreeFonts } from "./fonts.js";

const props = defineProps({
    elements: { type: Array, default: () => [] },
    appearance: { type: Object, default: null },
    live: { type: Boolean, default: false },
    still: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
    /** How many presses have been made on this slide, or null for all. */
    revealed: { type: Number, default: null },
    editingId: { type: String, default: null },
});

const emit = defineEmits(["text-input"]);

const elements = computed(() => (Array.isArray(props.elements) ? props.elements : []));

/** The families the text boxes name, loaded when this slide is drawn. */
const { families } = useFreeFonts(() => elements.value.map((element) => element.font).filter(Boolean), () => props.appearance);

const isShown = (element) => props.revealed === null || !element.reveal || element.reveal <= props.revealed;
</script>

<template>
    <div class="free-layer">
        <FreeElement
            v-for="element in elements"
            :key="element.id"
            :element="element"
            :appearance="appearance"
            :families="families"
            :live="live"
            :still="still"
            :compact="compact"
            :shown="isShown(element)"
            :editing="editingId === element.id"
            v-on:text-input="(id, html) => emit('text-input', id, html)"
        />
    </div>
</template>

<style scoped>
.free-layer {
    position: absolute;
    inset: 0;
}
</style>
