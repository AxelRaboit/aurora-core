import { describe, expect, it } from "vitest";
import { computed, nextTick, ref } from "vue";
import { useFreeEditor } from "./useFreeEditor.js";

/** A slide held the way the deck editor holds it, written through `writeSlide`. */
function editorOn(elements) {
    const slide = ref({ id: 1, layout: "free", content: { elements } });
    const selected = computed(() => slide.value);
    const editor = useFreeEditor({
        slide: selected,
        writeSlide: ({ layout, content }) => {
            slide.value = { ...slide.value, layout, content };
        },
    });

    return { slide, editor };
}

const shape = (id, overrides = {}) => ({
    id,
    type: "shape",
    shape: "rect",
    x: 10,
    y: 10,
    w: 10,
    h: 10,
    ...overrides,
});

describe("useFreeEditor", () => {
    it("adds, selects, and takes a step back", () => {
        const { slide, editor } = editorOn([]);

        const added = editor.add("shape");

        expect(slide.value.content.elements).toHaveLength(1);
        expect(editor.selection.value).toEqual([added.id]);

        editor.undo();
        expect(slide.value.content.elements).toHaveLength(0);

        editor.redo();
        expect(slide.value.content.elements).toHaveLength(1);
    });

    /** A step back after a conversion is the laid-out slide, layout included. */
    it("restores the layout with the content", () => {
        const { slide, editor } = editorOn([]);

        slide.value = { id: 1, layout: "bullets", content: { title: "Avant" } };
        editor.checkpoint();
        slide.value = {
            id: 1,
            layout: "free",
            content: { elements: [shape("a")] },
        };

        editor.undo();

        expect(slide.value.layout).toBe("bullets");
        expect(slide.value.content.title).toBe("Avant");
    });

    it("records the strokes of one field as one step", () => {
        const { slide, editor } = editorOn([shape("a")]);

        editor.select(["a"]);
        editor.patchSelected({ opacity: 0.9 }, { coalesce: "opacity" });
        editor.patchSelected({ opacity: 0.6 }, { coalesce: "opacity" });
        editor.patchSelected({ opacity: 0.3 }, { coalesce: "opacity" });

        editor.undo();

        expect(slide.value.content.elements[0].opacity).toBeUndefined();
    });

    it("keeps a locked element through a select-all and a Delete", () => {
        const { slide, editor } = editorOn([
            shape("a", { locked: true }),
            shape("b"),
        ]);

        editor.selectAll();
        expect(editor.selection.value).toEqual(["b"]);

        editor.select(["a", "b"]);
        editor.remove();

        expect(
            slide.value.content.elements.map((element) => element.id),
        ).toEqual(["a"]);
    });

    it("groups, and then picks the group whole", () => {
        const { slide, editor } = editorOn([
            shape("a"),
            shape("b"),
            shape("c"),
        ]);

        editor.select(["a", "b"]);
        editor.group();
        editor.clear();
        editor.select(["a"]);

        expect(editor.selection.value.sort()).toEqual(["a", "b"]);

        editor.ungroup();
        expect(
            slide.value.content.elements.every((element) => !element.group),
        ).toBe(true);
    });

    it("pastes a copy beside its original, with a new id", () => {
        const { slide, editor } = editorOn([shape("a")]);

        editor.select(["a"]);
        editor.copy();
        editor.paste();

        const [original, copy] = slide.value.content.elements;

        expect(copy.id).not.toBe(original.id);
        expect(copy.x).toBe(original.x + 2);
        expect(editor.selection.value).toEqual([copy.id]);
    });

    it("lines one element up with the slide, several with each other", () => {
        const { slide, editor } = editorOn([
            shape("a", { x: 10 }),
            shape("b", { x: 40 }),
        ]);

        editor.select(["a"]);
        editor.align("center");
        expect(slide.value.content.elements[0].x).toBe(45);

        editor.select(["a", "b"]);
        editor.align("left");
        expect(
            slide.value.content.elements.map((element) => element.x),
        ).toEqual([40, 40]);
    });

    it("spaces three elements evenly", () => {
        const { slide, editor } = editorOn([
            shape("a", { x: 0 }),
            shape("b", { x: 15 }),
            shape("c", { x: 80 }),
        ]);

        editor.select(["a", "b", "c"]);
        editor.distribute("x");

        expect(
            slide.value.content.elements.map((element) => element.x),
        ).toEqual([0, 40, 80]);
    });

    /** A text box with nothing in it is an invisible thing to trip over. */
    it("removes a text box left empty", () => {
        const { slide, editor } = editorOn([
            { id: "t", type: "text", x: 0, y: 0, w: 10, h: 10, html: "x" },
        ]);

        editor.startEditing("t");
        editor.typeText("t", "<br>&nbsp;");
        editor.stopEditing();

        expect(slide.value.content.elements).toHaveLength(0);
    });

    it("takes no step for a box opened and closed unchanged", () => {
        const { editor } = editorOn([
            { id: "t", type: "text", x: 0, y: 0, w: 10, h: 10, html: "Titre" },
        ]);

        editor.startEditing("t");
        editor.stopEditing();

        expect(editor.canUndo.value).toBe(false);
    });

    it("forgets the selection when another slide comes on screen", async () => {
        const { slide, editor } = editorOn([shape("a")]);

        editor.select(["a"]);
        slide.value = { id: 2, layout: "free", content: { elements: [] } };
        await nextTick();

        expect(editor.selection.value).toEqual([]);
    });
});
