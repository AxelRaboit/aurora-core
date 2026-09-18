import { computed } from "vue";
import { useListViewMode } from "@/shared/composables/list/useListViewMode.js";

/**
 * The shape the cards are drawn in: a kanban, or a list.
 *
 * **Not a sixth view.** The board and the list show the same cards, in the
 * same order, with the same actions; only the drawing differs. Two entries in
 * the space's switcher said otherwise - they sat next to Calendrier, Fichiers,
 * Discussion and Notes, which really are four different subjects - and made a
 * reader choose between two spellings of one place. It is the call the files
 * already made between rows and cards, so it is made the same way here.
 *
 * **In the query string, like every other shape toggle.** It describes the
 * page being looked at, so a link carries it. That is a change from the
 * remembered per-person choice this replaces: what is remembered now is which
 * subject you were reading, which is the thing that follows a person from one
 * space to the next.
 *
 * **A narrow container gets the list, whatever the link says.** A kanban is
 * columns, and columns on a phone scroll sideways past the second one; the
 * list is the same cards, readable. {@see useListViewMode} overrules toward
 * "grid", which a board is not, so the inversion is applied here rather than
 * by naming a kanban something it is not.
 */
export function useSpaceContentShape() {
    const { storedViewMode, setViewMode, container, isNarrow } =
        useListViewMode(["board", "list"], "board", "content");

    return {
        shape: computed(() => (isNarrow.value ? "list" : storedViewMode.value)),
        /** What the reader chose, which a narrow container overrules without erasing. */
        storedShape: storedViewMode,
        setShape: setViewMode,
        container,
        /**
         * Whether the choice is currently being overruled.
         *
         * Rendu à l'appelant pour qu'il cesse de dessiner le bouton : un
         * interrupteur qu'on actionne et qui ne change rien à l'écran laisse
         * croire à une panne. Ce qui est choisi reste gardé, et le bouton
         * revient avec la place de dessiner un kanban.
         */
        overruled: isNarrow,
    };
}
