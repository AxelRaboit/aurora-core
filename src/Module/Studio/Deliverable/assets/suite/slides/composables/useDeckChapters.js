import { computed, ref } from "vue";

/**
 * The chapters a deck falls into, and which of them are folded.
 *
 * A section slide is already a divider announcing what follows, so it is
 * already a chapter head: nothing new is stored, the structure is read off the
 * layouts. A deck that never uses the section layout simply has one chapter,
 * which is the truth about it.
 *
 * **Folded slides are hidden, never removed.** The thumbnails are what the
 * drag-and-drop reorders, and it reorders the list it is given: a list filtered
 * down to what is visible would come back without the folded slides and save
 * that as the new order. They stay in the DOM and lose their height instead, so
 * what is dragged is always the whole deck.
 */
export function useDeckChapters(slides) {
    const folded = ref(new Set());

    /** For each position, the id of the section slide that opens its chapter. */
    const heads = computed(() => {
        let head = null;

        return slides.value.map((slide) => {
            if (slide.layout === "section") {
                head = slide.id;

                return head;
            }

            return head;
        });
    });

    /** How many slides a chapter holds, its own head not counted. */
    const sizes = computed(() => {
        const counted = {};

        slides.value.forEach((slide, at) => {
            const head = heads.value[at];

            if (head === null || slide.id === head) return;

            counted[head] = (counted[head] ?? 0) + 1;
        });

        return counted;
    });

    /** A head is never hidden by its own folding: it is what unfolds it again. */
    function isHidden(at) {
        const head = heads.value[at];

        if (head === null || slides.value[at]?.id === head) return false;

        return folded.value.has(head);
    }

    function isFolded(id) {
        return folded.value.has(id);
    }

    function toggle(id) {
        const next = new Set(folded.value);

        next.has(id) ? next.delete(id) : next.add(id);
        folded.value = next;
    }

    /** Chapters with nothing under them have nothing to fold. */
    const foldable = (slide) =>
        slide.layout === "section" && (sizes.value[slide.id] ?? 0) > 0;

    return { folded, heads, sizes, isHidden, isFolded, toggle, foldable };
}
