<script setup>
/**
 * The other people's carets, drawn over the editor.
 *
 * **A textarea cannot show a second caret**, so these are drawn beside it:
 * each one is a `position: fixed` bar at the place {@see caretPositionIn}
 * computes for that person's offset, with their name on it. The same
 * measurement the slash palette and the wiki-link autocomplete use, which is
 * why it is a shared helper rather than a third copy of the mirror-div trick.
 *
 * **Recomputed, never cached.** A caret's place changes when the text changes,
 * when the pane is resized, and when the textarea scrolls - and the offset it
 * is drawn at did not move in any of those cases. So the positions are a
 * computed over a tick that those three events bump; measuring is cheap next
 * to being wrong.
 *
 * `pointer-events: none` throughout: this floats over the field somebody is
 * typing in, and nothing here may ever intercept a click.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { caretPositionIn } from "@notes/suite/markdown/composables/caretPosition.js";

const props = defineProps({
    /** The field the carets belong to. */
    textarea: { type: Object, default: null },
    /** `[{userId, name, index}]`, as the live room reports them. */
    cursors: { type: Array, default: () => [] },
    /** The text, watched so a caret follows what it is anchored in. */
    text: { type: String, default: "" },
});

/**
 * Bumped by everything that moves a caret without moving its offset.
 *
 * A counter rather than the values themselves: scroll and resize carry no
 * information a position could be derived from, only the fact that it has to
 * be taken again.
 */
const tick = ref(0);

function remeasure() {
    tick.value += 1;
}

let stop = () => {};

onMounted(() => {
    window.addEventListener("resize", remeasure, { passive: true });
    stop = () => window.removeEventListener("resize", remeasure);
});

onBeforeUnmount(() => stop());

// The field arrives after the first render, and scrolling it moves every
// caret in it.
watch(
    () => props.textarea,
    (field, previous) => {
        if (previous) previous.removeEventListener("scroll", remeasure);
        if (field) field.addEventListener("scroll", remeasure, { passive: true });
        remeasure();
    },
    { immediate: true },
);

watch(() => props.text, remeasure);
watch(() => props.cursors, remeasure, { deep: true });

/**
 * Each caret, placed.
 *
 * A cursor pointing past the end of the text is dropped rather than clamped:
 * it means the page is holding a position from a version it no longer has, and
 * a caret drawn at the wrong place is worse than no caret.
 */
const placed = computed(() => {
    void tick.value;

    const field = props.textarea;
    if (!field) return [];

    const length = (props.text ?? "").length;

    return props.cursors
        .filter((cursor) => Number.isFinite(cursor.index) && cursor.index <= length)
        .map((cursor) => {
            const at = caretPositionIn(field, cursor.index);

            return {
                userId: cursor.userId,
                name: cursor.name,
                top: at.top,
                left: at.left,
                height: at.lineHeight,
                // One of six hues, picked from the account id so the same
                // person keeps the same colour from one session to the next -
                // and from one reader's screen to another's.
                hue: (Number(cursor.userId) * 47) % 360,
            };
        });
});
</script>

<template>
    <div class="pointer-events-none fixed inset-0 z-20 print:hidden" aria-hidden="true">
        <div
            v-for="caret in placed"
            :key="caret.userId"
            class="pointer-events-none fixed"
            :style="{
                top: `${caret.top}px`,
                left: `${caret.left}px`,
                height: `${caret.height}px`,
            }"
        >
            <!-- The bar itself: two pixels, the width of a caret. -->
            <span
                class="absolute inset-y-0 left-0 w-0.5 rounded-sm"
                :style="{ backgroundColor: `hsl(${caret.hue} 70% 45%)` }"
            />
            <!-- The name, above the line rather than beside it: beside, it sat
                 on the text somebody was reading. -->
            <span
                v-if="caret.name"
                class="absolute -top-4 left-0 whitespace-nowrap rounded-sm px-1 text-[10px] font-medium leading-4 text-white"
                :style="{ backgroundColor: `hsl(${caret.hue} 70% 45%)` }"
            >{{ caret.name }}</span>
        </div>
    </div>
</template>
