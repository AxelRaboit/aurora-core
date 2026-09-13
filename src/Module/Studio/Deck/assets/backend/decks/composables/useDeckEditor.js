import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Composing one deck: add a slide, fill it, move it, remove it.
 *
 * **A slide is saved when the reader leaves it**, not on every keystroke and
 * not behind a Save button. A deck is edited by hopping from slide to slide,
 * so the moment of leaving is the moment the work on that one is finished,
 * and it is the only moment that needs no button. `flushCurrent` is therefore
 * called by every path out: selecting another slide, adding one, reordering,
 * and leaving the page.
 */
export function useDeckEditor(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const slides = ref([...(props.deck?.slides ?? [])]);
    const selectedId = ref(slides.value[0]?.id ?? null);

    const selected = computed(
        () =>
            slides.value.find((slide) => slide.id === selectedId.value) ?? null,
    );

    const layoutOf = (value) =>
        (props.layouts ?? []).find((layout) => layout.value === value) ?? null;

    /** The slots the selected slide's shape offers, in the declared order. */
    const slots = computed(() => layoutOf(selected.value?.layout)?.slots ?? []);

    /**
     * Whether the slide on screen differs from what the server holds.
     *
     * Compared against a snapshot taken at selection rather than against a
     * flag set by every input: a reader who types a word and deletes it again
     * has changed nothing, and saving then would be a version in the history
     * for no edit.
     */
    const snapshot = ref("");
    const saving = ref(false);

    function takeSnapshot() {
        snapshot.value = JSON.stringify({
            layout: selected.value?.layout,
            content: selected.value?.content,
            speakerNotes: selected.value?.speakerNotes,
        });
    }

    const dirty = computed(() => {
        if (!selected.value) return false;

        return (
            snapshot.value !==
            JSON.stringify({
                layout: selected.value.layout,
                content: selected.value.content,
                speakerNotes: selected.value.speakerNotes,
            })
        );
    });

    takeSnapshot();

    /** Save the slide being edited, if it changed. */
    async function flushCurrent() {
        if (!selected.value || !dirty.value || saving.value) return;

        const slide = selected.value;
        saving.value = true;

        try {
            const data = await request(
                buildPath(props.slideUpdatePath, { slideId: slide.id }),
                {
                    layout: slide.layout,
                    content: slide.content,
                    speakerNotes: slide.speakerNotes ?? "",
                },
            );

            if (data?.slide) {
                replace(data.slide);
                takeSnapshot();
            }
        } finally {
            saving.value = false;
        }
    }

    function replace(slide) {
        const at = slides.value.findIndex((row) => row.id === slide.id);

        if (at !== -1) slides.value = slides.value.toSpliced(at, 1, slide);
    }

    async function select(id) {
        if (id === selectedId.value) return;

        await flushCurrent();
        selectedId.value = id;
        takeSnapshot();
    }

    async function addSlide(layout) {
        await flushCurrent();

        const data = await request(props.slideCreatePath, { layout });

        if (!data?.slide) return;

        slides.value = [...slides.value, data.slide];
        selectedId.value = data.slide.id;
        takeSnapshot();
        toast.success(t("backend.studio.decks.slide_added"));
    }

    const pendingDelete = ref(null);

    async function confirmDeleteSlide() {
        const slide = pendingDelete.value;

        if (!slide) return;

        const data = await request(
            buildPath(props.slideDeletePath, { slideId: slide.id }),
        );

        if (!data?.success) return;

        const at = slides.value.findIndex((row) => row.id === slide.id);
        slides.value = slides.value.filter((row) => row.id !== slide.id);
        pendingDelete.value = null;

        // Land on the neighbour rather than on nothing: deleting slide four of
        // nine and being sent back to the first is a scroll the reader did not
        // ask for.
        if (selectedId.value === slide.id) {
            const next = slides.value[Math.min(at, slides.value.length - 1)];
            selectedId.value = next?.id ?? null;
            takeSnapshot();
        }

        toast.success(t("backend.studio.decks.slide_deleted"));
    }

    /**
     * Move a slide by one place.
     *
     * Arrows rather than a drag, for now: a deck is a short list and two
     * buttons work on a touchscreen, with a keyboard, and for somebody who
     * cannot drag. The order is sent whole, so the server never has to
     * reconstruct a gesture from a sequence of swaps.
     */
    async function move(slide, by) {
        const at = slides.value.findIndex((row) => row.id === slide.id);
        const to = at + by;

        if (at === -1 || to < 0 || to >= slides.value.length) return;

        await flushCurrent();

        const next = [...slides.value];
        next.splice(to, 0, ...next.splice(at, 1));
        slides.value = next;

        await request(props.slideReorderPath, {
            orderedIds: next.map((row) => row.id),
        });
    }

    /**
     * The order after a drag, saved as one write.
     *
     * The dragged slide is already where the reader dropped it - the component
     * hands back the reordered list - so this is the same call `move` makes,
     * without the arithmetic.
     */
    async function reorder(ordered) {
        await flushCurrent();

        slides.value = ordered;

        await request(props.slideReorderPath, {
            orderedIds: ordered.map((row) => row.id),
        });
    }

    /**
     * Copy a slide, right after the one it copies.
     *
     * At the end would be the cheaper answer and the wrong one: a slide is
     * duplicated to write a variant of it, and a variant that lands twenty
     * slides away has to be dragged back before it can be edited.
     */
    async function duplicateSlide(slide) {
        await flushCurrent();

        const data = await request(
            buildPath(props.slideDuplicatePath, { slideId: slide.id }),
        );

        if (!data?.slide) return;

        const at = slides.value.findIndex((row) => row.id === slide.id);

        slides.value = slides.value.toSpliced(at + 1, 0, data.slide);
        selectedId.value = data.slide.id;
        takeSnapshot();
        toast.success(t("backend.studio.decks.slide_duplicated"));
    }

    /** Write one slot of the selected slide, without touching the others. */
    function writeSlot(slot, value) {
        if (!selected.value) return;

        replace({
            ...selected.value,
            content: { ...selected.value.content, [slot]: value },
        });
    }

    function writeLayout(value) {
        if (!selected.value) return;

        replace({ ...selected.value, layout: value });
    }

    function writeNotes(value) {
        if (!selected.value) return;

        replace({ ...selected.value, speakerNotes: value });
    }

    return {
        slides,
        selectedId,
        selected,
        slots,
        dirty,
        saving,
        pendingDelete,
        select,
        addSlide,
        confirmDeleteSlide,
        duplicateSlide,
        move,
        reorder,
        writeSlot,
        writeLayout,
        writeNotes,
        flushCurrent,
    };
}
