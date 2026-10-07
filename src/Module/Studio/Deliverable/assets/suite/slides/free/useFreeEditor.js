import { computed, ref, watch } from "vue";
import {
    boundsOf,
    clamp,
    cloneElements,
    createElement,
    newId,
    RATIO,
    restack,
    round,
    unionOf,
    withGroups,
} from "./model.js";

/**
 * What can be done to a free slide, and how to take it back.
 *
 * **One history per slide, of the whole slide.** A step back restores the
 * layout and the content together, which is what makes "turn this slide free"
 * undoable like any other gesture: the step before the conversion is the
 * laid-out slide, words and all. The history lives as long as the page does;
 * once the editor is closed, the saved slide is the only state there is.
 *
 * **A step is a gesture, not a change.** A drag writes the element sixty times
 * a second and records once, when it starts; typing into a box records once,
 * when the typing starts. Taking one step back then undoes what a person
 * remembers doing.
 *
 * The clipboard is shared by every slide and every deck: kept in the browser
 * as well as in the page, so an element copied in one deck is pasted in
 * another, which is how a free slide becomes the model for the next one.
 */
const STORED_CLIPBOARD = "aurora.decks.free.clipboard";

function storedClipboard() {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(STORED_CLIPBOARD) ?? "[]",
        );

        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
}

const clipboard = ref(typeof window === "undefined" ? [] : storedClipboard());

const HISTORY_DEPTH = 100;

export function useFreeEditor({ slide, writeSlide, maxElements = 200 }) {
    const histories = new Map();

    const elements = computed(() =>
        Array.isArray(slide.value?.content?.elements)
            ? slide.value.content.elements
            : [],
    );

    /** Ids, in the order they were picked. */
    const selection = ref([]);

    /** The text box being typed into, if any. */
    const editingId = ref(null);

    const selected = computed(() =>
        elements.value.filter((element) =>
            selection.value.includes(element.id),
        ),
    );

    /** The one element selected, when there is exactly one. */
    const single = computed(() =>
        selected.value.length === 1 ? selected.value[0] : null,
    );

    /** Whatever slide comes on screen starts with nothing selected. */
    watch(
        () => slide.value?.id,
        () => {
            selection.value = [];
            editingId.value = null;
        },
    );

    /** Bumped on every change of history, so the buttons know to re-read. */
    const version = ref(0);

    function historyOf(id) {
        if (!histories.has(id)) histories.set(id, { past: [], future: [] });

        return histories.get(id);
    }

    const snapshot = () =>
        JSON.stringify({
            layout: slide.value.layout,
            content: slide.value.content,
        });

    /** Remember the slide as it is, before a gesture changes it. */
    function checkpoint() {
        if (!slide.value) return;

        const history = historyOf(slide.value.id);
        const now = snapshot();

        if (history.past[history.past.length - 1] === now) return false;

        history.past.push(now);
        if (history.past.length > HISTORY_DEPTH) history.past.shift();
        history.future = [];
        version.value += 1;

        return true;
    }

    const canUndo = computed(
        () =>
            version.value >= 0 &&
            !!slide.value &&
            historyOf(slide.value.id).past.length > 0,
    );
    const canRedo = computed(
        () =>
            version.value >= 0 &&
            !!slide.value &&
            historyOf(slide.value.id).future.length > 0,
    );

    function travel(from, to) {
        if (!slide.value) return;

        const history = historyOf(slide.value.id);
        const target = history[from].pop();

        if (!target) return;

        history[to].push(snapshot());

        const { layout, content } = JSON.parse(target);

        editingId.value = null;
        writeSlide({ layout, content });

        const ids = new Set(
            (content.elements ?? []).map((element) => element.id),
        );
        selection.value = selection.value.filter((id) => ids.has(id));
        version.value += 1;
    }

    const undo = () => travel("past", "future");
    const redo = () => travel("future", "past");

    /**
     * The last recorded gesture of a field, so the strokes of one slider or
     * the keys of one number make one step and not fifty.
     */
    let lastField = null;

    function shouldRecord(record, coalesce) {
        if (!record) return false;
        if (!coalesce) {
            lastField = null;

            return true;
        }

        const now = Date.now();
        const key = `${slide.value?.id}:${coalesce}:${selection.value.join(",")}`;
        const repeat =
            lastField && lastField.key === key && now - lastField.at < 1500;

        lastField = { key, at: now };

        return !repeat;
    }

    /** Replace the elements; recorded unless the gesture already was. */
    function setElements(list, { record = true, coalesce = null } = {}) {
        if (!slide.value) return;

        if (shouldRecord(record, coalesce)) checkpoint();

        writeSlide({
            layout: slide.value.layout,
            content: { ...slide.value.content, elements: list },
        });
    }

    /** Write a property of the slide itself: its paint, its film. */
    function setSlot(slot, value, { record = true, coalesce = null } = {}) {
        if (!slide.value) return;

        if (shouldRecord(record, coalesce)) checkpoint();

        const content = { ...slide.value.content };

        if (value === null || value === undefined) delete content[slot];
        else content[slot] = value;

        writeSlide({ layout: slide.value.layout, content });
    }

    /**
     * Change some elements.
     *
     * `change` is either the properties to write, or a function handed each
     * element and returning its new version. `null` removes a property.
     */
    function patch(ids, change, options = {}) {
        const targets = new Set(ids);

        setElements(
            elements.value.map((element) => {
                if (!targets.has(element.id)) return element;

                const next =
                    typeof change === "function"
                        ? change(element)
                        : { ...element, ...change };

                for (const key of Object.keys(next)) {
                    if (next[key] === null || next[key] === undefined)
                        delete next[key];
                }

                return next;
            }),
            options,
        );
    }

    const patchSelected = (change, options) =>
        patch(selection.value, change, options);

    function select(ids, { add = false } = {}) {
        const grouped = withGroups(elements.value, ids);

        if (!add) {
            selection.value = grouped;

            return;
        }

        const now = new Set(selection.value);
        const everyPicked = grouped.every((id) => now.has(id));

        for (const id of grouped) {
            if (everyPicked) now.delete(id);
            else now.add(id);
        }

        selection.value = [...now];
    }

    const clear = () => {
        selection.value = [];
    };

    const selectAll = () => {
        selection.value = elements.value
            .filter((element) => !element.locked)
            .map((element) => element.id);
    };

    function add(type, overrides = {}) {
        if (elements.value.length >= maxElements) return null;

        const element = createElement(type, overrides);

        setElements([...elements.value, element]);
        selection.value = [element.id];

        return element;
    }

    /** Several at once, as one step: a paste, a conversion. */
    function addMany(list) {
        const room = Math.max(0, maxElements - elements.value.length);
        const kept = list.slice(0, room);

        if (!kept.length) return;

        setElements([...elements.value, ...kept]);
        selection.value = kept.map((element) => element.id);
    }

    /** Locked elements stay: a lock is there to survive a select-all and a Delete. */
    function remove(ids = selection.value) {
        const doomed = new Set(
            elements.value
                .filter(
                    (element) => ids.includes(element.id) && !element.locked,
                )
                .map((element) => element.id),
        );

        if (!doomed.size) return;

        setElements(
            elements.value.filter((element) => !doomed.has(element.id)),
        );
        selection.value = selection.value.filter((id) => !doomed.has(id));
    }

    function duplicate() {
        if (!selected.value.length) return;

        addMany(cloneElements(selected.value));
    }

    function copy() {
        if (!selected.value.length) return;

        clipboard.value = JSON.parse(JSON.stringify(selected.value));

        try {
            window.localStorage.setItem(
                STORED_CLIPBOARD,
                JSON.stringify(clipboard.value),
            );
        } catch {
            // A browser that keeps nothing still pastes within this page.
        }
    }

    function cut() {
        copy();
        remove();
    }

    /**
     * Pasted on top, offset unless it lands on another slide: a copy dropped
     * exactly on its original looks like nothing happened, while one carried
     * to another slide is usually meant to sit where it sat.
     */
    function paste() {
        // Copied in another tab or another deck since this page opened.
        if (typeof window !== "undefined") {
            const stored = storedClipboard();

            if (stored.length) clipboard.value = stored;
        }

        if (!clipboard.value.length) return;

        const here = new Set(elements.value.map((element) => element.id));
        const sameSlide = clipboard.value.some((element) =>
            here.has(element.id),
        );

        addMany(cloneElements(clipboard.value, sameSlide ? 2 : 0));
    }

    const hasClipboard = computed(() => clipboard.value.length > 0);

    function group() {
        if (selection.value.length < 2) return;

        const id = newId();

        patchSelected({ group: id });
    }

    function ungroup() {
        patchSelected({ group: null });
    }

    function toggleLock() {
        const lock = !selected.value.every((element) => element.locked);

        patchSelected({ locked: lock || null });
    }

    function arrange(direction) {
        if (!selection.value.length) return;

        setElements(restack(elements.value, selection.value, direction));
    }

    /** Move the selection by a distance in per cent of the slide's width. */
    function nudge(deltaX, deltaY) {
        const movable = selected.value
            .filter((element) => !element.locked)
            .map((element) => element.id);

        if (!movable.length) return;

        patch(movable, (element) => ({
            ...element,
            x: round(element.x + deltaX),
            y: round(element.y + deltaY / RATIO),
        }));
    }

    /**
     * Line the selection up.
     *
     * One element lines up with the slide, several with the box around them,
     * which is how every editor reads the same button.
     */
    function align(edge) {
        const targets = selected.value.filter((element) => !element.locked);

        if (!targets.length) return;

        const frame =
            targets.length > 1
                ? unionOf(targets)
                : {
                      left: 0,
                      right: 100,
                      top: 0,
                      bottom: 100,
                      centreX: 50,
                      centreY: 50,
                  };

        patch(
            targets.map((element) => element.id),
            (element) => {
                const box = boundsOf(element);
                let deltaX = 0;
                let deltaY = 0;

                if (edge === "left") deltaX = frame.left - box.left;
                if (edge === "center") deltaX = frame.centreX - box.centreX;
                if (edge === "right") deltaX = frame.right - box.right;
                if (edge === "top") deltaY = frame.top - box.top;
                if (edge === "middle") deltaY = frame.centreY - box.centreY;
                if (edge === "bottom") deltaY = frame.bottom - box.bottom;

                return {
                    ...element,
                    x: round(element.x + deltaX),
                    y: round(element.y + deltaY),
                };
            },
        );
    }

    /** Equal gaps between three or more, along one axis. */
    function distribute(axis) {
        const targets = selected.value.filter((element) => !element.locked);

        if (targets.length < 3) return;

        const start = axis === "x" ? "left" : "top";
        const end = axis === "x" ? "right" : "bottom";
        const boxes = targets
            .map((element) => ({ element, box: boundsOf(element) }))
            .sort((left, right) => left.box[start] - right.box[start]);
        const span = boxes[boxes.length - 1].box[end] - boxes[0].box[start];
        const used = boxes.reduce(
            (sum, { box }) => sum + (box[end] - box[start]),
            0,
        );
        const gap = (span - used) / (boxes.length - 1);

        let cursor = boxes[0].box[start];
        const moves = new Map();

        for (const { element, box } of boxes) {
            moves.set(element.id, cursor - box[start]);
            cursor += box[end] - box[start] + gap;
        }

        patch(
            targets.map((element) => element.id),
            (element) => ({
                ...element,
                [axis]: round(element[axis] + (moves.get(element.id) ?? 0)),
            }),
        );
    }

    /** The box being typed into, its words before, and whether a step was taken. */
    let typing = null;

    /** Typing started: the step to come back to is the box before it. */
    function startEditing(id) {
        const element = elements.value.find((row) => row.id === id);

        if (!element || element.type !== "text" || element.locked) return;

        typing = { id, before: element.html ?? "", recorded: checkpoint() };
        selection.value = [id];
        editingId.value = id;
    }

    /** What a key press wrote into the box, not recorded: the start was. */
    function typeText(id, html) {
        patch([id], { html }, { record: false });
    }

    /**
     * Typing stopped. A box left empty goes, as it does everywhere else: a
     * text box with no text is an invisible thing to trip over.
     */
    function stopEditing() {
        const id = editingId.value;

        editingId.value = null;

        const element = elements.value.find((row) => row.id === id);

        // Opened and closed without a change: the step taken when typing
        // started would undo nothing, so it is given back.
        if (
            typing?.id === id &&
            typing.recorded &&
            element &&
            (element.html ?? "") === typing.before
        ) {
            historyOf(slide.value.id).past.pop();
            version.value += 1;
        }

        typing = null;

        const empty =
            element &&
            !String(element.html ?? "")
                .replace(/<[^>]*>/g, "")
                .replace(/&nbsp;/g, "")
                .trim();

        if (empty) {
            setElements(
                elements.value.filter((row) => row.id !== id),
                { record: false },
            );
            selection.value = [];
        }
    }

    /** Bounded so an element never leaves the slide entirely. */
    const keepReachable = (element) => ({
        ...element,
        x: round(clamp(element.x, -element.w + 2, 98)),
        y: round(clamp(element.y, -element.h + 2, 98)),
    });

    return {
        elements,
        selection,
        selected,
        single,
        editingId,
        canUndo,
        canRedo,
        hasClipboard,
        checkpoint,
        undo,
        redo,
        setElements,
        setSlot,
        patch,
        patchSelected,
        select,
        clear,
        selectAll,
        add,
        addMany,
        remove,
        duplicate,
        copy,
        cut,
        paste,
        group,
        ungroup,
        toggleLock,
        arrange,
        nudge,
        align,
        distribute,
        startEditing,
        typeText,
        stopEditing,
        keepReachable,
    };
}
