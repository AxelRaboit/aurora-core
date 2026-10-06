<script setup>
/**
 * The free slide, as something to take hold of.
 *
 * **The slide drawn is the real one.** Underneath is the same `SlideFrame`
 * every other screen draws, elements included; on top is a layer that draws
 * only what an editor adds - outlines, handles, guides, the lasso - and
 * catches nothing but the handles. A pointer on an element reaches the element
 * itself, which is how the canvas knows what was picked without keeping a
 * second map of where everything is.
 *
 * **Every gesture is computed in per cent of the slide**, never in pixels, so
 * the same drag means the same thing in a narrow column and on a large
 * screen. A turned element is resized in a square space, where per cent of
 * the width is the unit on both axes, because a turn in per cent of two
 * different lengths is not a turn.
 *
 * Pointer events throughout, so a finger drags what a mouse drags.
 */
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
    Bold,
    Highlighter,
    Italic,
    List,
    ListOrdered,
    Lock,
    RemoveFormatting,
    Strikethrough,
    Underline,
} from "lucide-vue-next";
import SlideFrame from "../components/SlideFrame.vue";
import { boundsOf, RATIO, round, unionOf } from "./model.js";
import { snapAngle, snapEdges, snapMove } from "./snapping.js";

const props = defineProps({
    slide: { type: Object, required: true },
    appearance: { type: Object, default: null },
    index: { type: Number, default: 1 },
    editor: { type: Object, required: true },
    editable: { type: Boolean, default: true },
    /**
     * Whether the keys belong to the canvas. Off while something covers it -
     * the player, a panel - where an arrow means the next slide, not a nudge.
     */
    keyboard: { type: Boolean, default: true },
});

const { t } = useI18n();

const root = ref(null);
const guides = ref([]);
const marquee = ref(null);
const hoveredId = ref(null);
const readout = ref(null);

/** Read once per gesture: what is under the pointer, and where. */
let gesture = null;

/**
 * The fingers on the slide, by pointer id, where they are now.
 *
 * Two of them on a selected element is a pinch: the element grows, shrinks
 * and turns between them, as a photo does in any phone's gallery.
 */
const pointers = new Map();

const editor = props.editor;
const elements = computed(() => editor.elements.value);
const selected = computed(() => editor.selected.value);
const editingId = computed(() => editor.editingId.value);

/** The box drawn around several, unturned: the group's own handles. */
const union = computed(() => (selected.value.length > 1 ? unionOf(selected.value) : null));

const lockedOnly = computed(() => selected.value.length > 0 && selected.value.every((element) => element.locked));

/**
 * A point of the pointer in the two units of the slide.
 *
 * `x` in per cent of the width, `y` in per cent of the height, and `s` - the
 * vertical position in per cent of the width - for the square-space
 * arithmetic of turns.
 */
function pointOf(event) {
    const rect = root.value.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * 100;
    const squareY = ((event.clientY - rect.top) / rect.width) * 100;

    return { x, y: squareY / RATIO, s: squareY };
}

const outlineStyle = (element) => ({
    left: `${element.x}%`,
    top: `${element.y}%`,
    width: `${element.w}%`,
    height: `${element.h}%`,
    transform: element.rotate ? `rotate(${element.rotate}deg)` : undefined,
});

const unionStyle = computed(() => {
    const box = union.value;

    if (!box) return null;

    return {
        left: `${box.left}%`,
        top: `${box.top}%`,
        width: `${box.right - box.left}%`,
        height: `${box.bottom - box.top}%`,
    };
});

const HANDLES = [
    { key: "nw", x: -1, y: -1 },
    { key: "n", x: 0, y: -1 },
    { key: "ne", x: 1, y: -1 },
    { key: "e", x: 1, y: 0 },
    { key: "se", x: 1, y: 1 },
    { key: "s", x: 0, y: 1 },
    { key: "sw", x: -1, y: 1 },
    { key: "w", x: -1, y: 0 },
];

const CORNERS = HANDLES.filter((handle) => handle.x !== 0 && handle.y !== 0);

const handleStyle = (handle) => ({
    left: `${(handle.x + 1) * 50}%`,
    top: `${(handle.y + 1) * 50}%`,
});

/** Which elements keep their proportions when a corner is dragged. */
const keepsRatio = (element, shift) => {
    const byDefault = ["image", "video", "icon", "text", "embed"].includes(element.type);

    return shift ? !byDefault : byDefault;
};

function elementAt(event) {
    const node = event.target.closest?.("[data-free-id]");

    if (!node || !root.value.contains(node)) return null;

    return elements.value.find((element) => element.id === node.dataset.freeId) ?? null;
}

function onPointerDown(event) {
    if (!props.editable || event.button > 0) return;

    const handle = event.target.closest?.("[data-handle]")?.dataset.handle;
    const element = handle ? null : elementAt(event);

    // Inside the box being typed into, the pointer belongs to the words.
    if (element && element.id === editingId.value) return;

    if (editingId.value) editor.stopEditing();

    root.value.focus({ preventScroll: true });

    const start = pointOf(event);

    pointers.set(event.pointerId, start);

    if (pointers.size === 2 && beginPinch()) {
        event.preventDefault();

        return;
    }

    if (handle) {
        event.preventDefault();
        beginHandle(handle, start, event);

        return;
    }

    if (element) {
        event.preventDefault();

        const additive = event.shiftKey || event.metaKey || event.ctrlKey;

        // A tap on the text box already picked opens it for typing: on a
        // phone there is no double click, and on a computer it is what every
        // editor does with a second click.
        const tapToType = !additive
            && element.type === "text"
            && editor.selection.value.length === 1
            && editor.selection.value[0] === element.id;

        if (additive) {
            editor.select([element.id], { add: true });
        } else if (!editor.selection.value.includes(element.id)) {
            editor.select([element.id]);
        }

        const movable = editor.selected.value.filter((row) => !row.locked);

        if (movable.length) {
            gesture = {
                kind: "move",
                start,
                originals: new Map(movable.map((row) => [row.id, { ...row }])),
                moved: false,
                tapToType: tapToType ? element.id : null,
            };
        }

        capture(event);

        return;
    }

    // The empty slide: a lasso, which picks what it touches.
    if (!event.shiftKey) editor.clear();

    gesture = { kind: "marquee", start, before: [...editor.selection.value] };
    marquee.value = { left: start.x, top: start.y, right: start.x, bottom: start.y };
    capture(event);
}

/**
 * Two fingers down on one selected element: from now on they hold it.
 *
 * Measured in the square space, like a turn, so the angle between the
 * fingers is the angle the element turns by.
 */
function beginPinch() {
    const target = editor.single.value;

    if (!target || target.locked) return false;

    const [first, second] = [...pointers.values()];

    // The first finger may have dragged the element a little before the
    // second one landed: the pinch starts from where it was, and the step
    // already recorded for that drag is the one this gesture undoes to.
    const dragged = gesture?.kind === "move" && gesture.moved;
    const original = dragged ? gesture.originals.get(target.id) ?? target : target;

    if (dragged) editor.patch([target.id], { x: original.x, y: original.y }, { record: false });

    gesture = {
        kind: "pinch",
        originals: new Map([[target.id, { ...original }]]),
        distance: Math.hypot(second.x - first.x, second.s - first.s),
        angle: Math.atan2(second.s - first.s, second.x - first.x),
        moved: dragged,
    };

    marquee.value = null;

    return true;
}

function pinchTo() {
    const [first, second] = [...pointers.values()];

    if (!first || !second || !gesture.distance) return;

    startRecording();

    const [id, original] = [...gesture.originals.entries()][0];
    const scale = Math.max(0.1, Math.hypot(second.x - first.x, second.s - first.s) / gesture.distance);
    const turn = ((Math.atan2(second.s - first.s, second.x - first.x) - gesture.angle) * 180) / Math.PI;
    const centreX = original.x + original.w / 2;
    const centreY = original.y + original.h / 2;
    const width = original.w * scale;
    const height = original.h * scale;
    const next = {
        x: round(centreX - width / 2),
        y: round(centreY - height / 2),
        w: round(width),
        h: round(height),
        rotate: round(snapAngle((original.rotate ?? 0) + turn), 2) || null,
    };

    if (original.type === "text") next.size = round(Math.max(5, (original.size ?? 40) * scale), 1);

    readout.value = `${Math.round(scale * 100)} % · ${Math.round(next.rotate ?? 0)}°`;
    editor.patch([id], next, { record: false });
}

function beginHandle(handle, start, event) {
    const targets = selected.value.filter((element) => !element.locked);

    if (!targets.length) return;

    const originals = new Map(targets.map((element) => [element.id, { ...element }]));

    if (handle === "rotate") {
        const centre = targets.length > 1 ? unionOf(targets) : boundsOf(targets[0]);

        gesture = {
            kind: "rotate",
            originals,
            centre: { x: centre.centreX, s: centre.centreY * RATIO },
            startAngle: Math.atan2(start.s - centre.centreY * RATIO, start.x - centre.centreX),
            moved: false,
        };
    } else {
        const spec = HANDLES.find((row) => row.key === handle);

        gesture = {
            kind: targets.length > 1 ? "scale" : "resize",
            handle: spec,
            originals,
            union: targets.length > 1 ? unionOf(targets) : null,
            moved: false,
        };
    }

    capture(event);
}

function capture(event) {
    window.addEventListener("pointermove", onPointerMove);
    window.addEventListener("pointerup", onPointerUp, { once: true });
    window.addEventListener("pointercancel", onPointerUp, { once: true });
    event.target.setPointerCapture?.(event.pointerId);
}

/** The first real movement records the step; a click records nothing. */
function startRecording() {
    if (gesture.moved) return;

    gesture.moved = true;
    editor.checkpoint();
}

const others = () => elements.value.filter((element) => !gesture?.originals?.has(element.id)).map(boundsOf);

function onPointerMove(event) {
    if (!gesture) return;

    const point = pointOf(event);

    if (pointers.has(event.pointerId)) pointers.set(event.pointerId, point);

    if (gesture.kind === "pinch") {
        pinchTo();

        return;
    }

    if (gesture.kind === "move") moveTo(point, event);
    else if (gesture.kind === "resize") resizeTo(point, event);
    else if (gesture.kind === "scale") scaleTo(point, event);
    else if (gesture.kind === "rotate") rotateTo(point, event);
    else if (gesture.kind === "marquee") lassoTo(point, event);
}

function moveTo(point, event) {
    let deltaX = point.x - gesture.start.x;
    let deltaY = point.y - gesture.start.y;

    if (!gesture.moved && Math.hypot(deltaX, deltaY * RATIO) < 0.25) return;

    startRecording();

    // Shift holds the drag to one axis, as everywhere.
    if (event.shiftKey) {
        if (Math.abs(deltaX) > Math.abs(deltaY * RATIO)) deltaY = 0;
        else deltaX = 0;
    }

    const moved = [...gesture.originals.values()].map((element) => ({ ...element, x: element.x + deltaX, y: element.y + deltaY }));
    let snap = { dx: 0, dy: 0, guides: [] };

    // Alt lets go of the guides, for the one placement that sits between them.
    if (!event.altKey) snap = snapMove(unionOf(moved), others());

    guides.value = snap.guides;

    editor.patch(
        [...gesture.originals.keys()],
        (element) => {
            const original = gesture.originals.get(element.id);

            return { ...element, x: round(original.x + deltaX + snap.dx), y: round(original.y + deltaY + snap.dy) };
        },
        { record: false },
    );
}

/**
 * One element, by one handle, possibly turned.
 *
 * The corner or side opposite the handle stays where it is on the slide; the
 * new size is the pointer's distance from it, measured along the element's
 * own axes.
 */
function resizeTo(point, event) {
    startRecording();

    const [id, original] = [...gesture.originals.entries()][0];
    const { handle } = gesture;
    const angle = ((original.rotate ?? 0) * Math.PI) / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);

    const width = original.w;
    const height = original.h * RATIO;
    const centre = { x: original.x + width / 2, s: original.y * RATIO + height / 2 };

    // The anchor: the point opposite the handle, in square space.
    const local = { x: (-handle.x * width) / 2, y: (-handle.y * height) / 2 };
    const anchor = { x: centre.x + local.x * cos - local.y * sin, s: centre.s + local.x * sin + local.y * cos };

    // The pointer, seen from the anchor along the element's own axes.
    const pointerX = point.x - anchor.x;
    const pointerY = point.s - anchor.s;
    const along = { x: pointerX * cos + pointerY * sin, y: -pointerX * sin + pointerY * cos };

    let newWidth = handle.x !== 0 ? Math.max(0.5, handle.x * along.x) : width;
    let newHeight = handle.y !== 0 ? Math.max(0.5 * RATIO, handle.y * along.y) : height;

    const corner = handle.x !== 0 && handle.y !== 0;
    let scale = 1;

    if (corner && keepsRatio(original, event.shiftKey)) {
        scale = Math.max(newWidth / width, newHeight / height);
        newWidth = width * scale;
        newHeight = height * scale;
    }

    // Unturned, the moving edges catch on the guides like a moving box.
    if (!original.rotate && !event.altKey && !(corner && keepsRatio(original, event.shiftKey))) {
        const left = handle.x < 0 ? anchor.x - newWidth : anchor.x;
        const top = (handle.y < 0 ? anchor.s - newHeight : anchor.s) / RATIO;
        const edges = {};

        if (handle.x < 0) edges.left = left;
        if (handle.x > 0) edges.right = left + newWidth;
        if (handle.y < 0) edges.top = top;
        if (handle.y > 0) edges.bottom = top + newHeight / RATIO;

        const snapped = snapEdges(edges, others());

        if (snapped.edges.left != null) newWidth = anchor.x - snapped.edges.left;
        if (snapped.edges.right != null) newWidth = snapped.edges.right - anchor.x;
        if (snapped.edges.top != null) newHeight = anchor.s - snapped.edges.top * RATIO;
        if (snapped.edges.bottom != null) newHeight = snapped.edges.bottom * RATIO - anchor.s;

        guides.value = snapped.guides;
    } else {
        guides.value = [];
    }

    newWidth = Math.max(0.5, newWidth);
    newHeight = Math.max(0.5 * RATIO, newHeight);

    // The new centre, from the anchor, along the element's axes.
    const offset = { x: (handle.x * newWidth) / 2, y: (handle.y * newHeight) / 2 };
    const newCentre = {
        x: anchor.x + offset.x * cos - offset.y * sin,
        s: anchor.s + offset.x * sin + offset.y * cos,
    };

    const next = {
        x: round(newCentre.x - newWidth / 2),
        y: round((newCentre.s - newHeight / 2) / RATIO),
        // A side handle leaves the other side exactly as it was, rather than
        // as it comes back from a trip through the square space.
        w: newWidth === width ? original.w : round(newWidth),
        h: newHeight === height ? original.h : round(newHeight / RATIO),
    };

    // A text box's corner scales its words with it; its sides only move the
    // edges the words wrap against.
    if (original.type === "text" && corner && keepsRatio(original, event.shiftKey)) {
        next.size = round(Math.max(5, (original.size ?? 40) * scale), 1);
    }

    readout.value = `${Math.round(next.w)} × ${Math.round(next.h)}`;

    editor.patch([id], next, { record: false });
}

/** Several elements, by a corner of the box around them: all of it scales. */
function scaleTo(point) {
    startRecording();

    const box = gesture.union;
    const { handle } = gesture;

    if (handle.x === 0 || handle.y === 0) return;

    const anchor = { x: handle.x > 0 ? box.left : box.right, s: (handle.y > 0 ? box.top : box.bottom) * RATIO };
    const width = box.right - box.left;
    const height = (box.bottom - box.top) * RATIO;
    const scale = Math.max(0.05, Math.max((handle.x * (point.x - anchor.x)) / width, (handle.y * (point.s - anchor.s)) / height));

    editor.patch(
        [...gesture.originals.keys()],
        (element) => {
            const original = gesture.originals.get(element.id);
            const left = anchor.x + (original.x - anchor.x) * scale;
            const top = anchor.s + (original.y * RATIO - anchor.s) * scale;
            const next = {
                ...element,
                x: round(left),
                y: round(top / RATIO),
                w: round(original.w * scale),
                h: round(original.h * scale),
            };

            if (original.type === "text") next.size = round(Math.max(5, (original.size ?? 40) * scale), 1);
            if (original.type === "table") next.size = round(Math.max(5, (original.size ?? 22) * scale), 1);

            return next;
        },
        { record: false },
    );

    readout.value = `${Math.round(scale * 100)} %`;
}

function rotateTo(point, event) {
    startRecording();

    const { centre } = gesture;
    const now = Math.atan2(point.s - centre.s, point.x - centre.x);
    const delta = ((now - gesture.startAngle) * 180) / Math.PI;
    const single = gesture.originals.size === 1;

    editor.patch(
        [...gesture.originals.keys()],
        (element) => {
            const original = gesture.originals.get(element.id);
            const target = event.altKey ? (original.rotate ?? 0) + delta : snapAngle((original.rotate ?? 0) + delta, event.shiftKey);
            const turn = single ? 0 : ((target - (original.rotate ?? 0)) * Math.PI) / 180;

            if (single) return { ...element, rotate: round(target, 2) || null };

            // Several turn around the middle of their box, each with it.
            const offsetX = original.x + original.w / 2 - centre.x;
            const offsetSquareY = original.y * RATIO + (original.h * RATIO) / 2 - centre.s;
            const x = centre.x + offsetX * Math.cos(turn) - offsetSquareY * Math.sin(turn);
            const turnedSquareY = centre.s + offsetX * Math.sin(turn) + offsetSquareY * Math.cos(turn);

            return {
                ...element,
                x: round(x - original.w / 2),
                y: round((turnedSquareY - (original.h * RATIO) / 2) / RATIO),
                rotate: round(target, 2) || null,
            };
        },
        { record: false },
    );

    const first = editor.elements.value.find((element) => gesture.originals.has(element.id));

    readout.value = `${Math.round(first?.rotate ?? 0)}°`;
}

function lassoTo(point, event) {
    const { start } = gesture;
    const box = {
        left: Math.min(start.x, point.x),
        right: Math.max(start.x, point.x),
        top: Math.min(start.y, point.y),
        bottom: Math.max(start.y, point.y),
    };

    marquee.value = box;

    const touched = elements.value
        .filter((element) => {
            const bounds = boundsOf(element);

            return bounds.left < box.right && bounds.right > box.left && bounds.top < box.bottom && bounds.bottom > box.top;
        })
        .map((element) => element.id);

    editor.select([...(event.shiftKey ? gesture.before : []), ...touched]);
}

function onPointerUp(event) {
    pointers.delete(event?.pointerId);

    // One finger lifted from a pinch: the other one stays put, holding
    // nothing, until it lifts too.
    if (gesture?.kind === "pinch" && pointers.size > 0) {
        gesture = { kind: "idle", originals: new Map() };
        readout.value = null;

        return;
    }

    pointers.clear();
    window.removeEventListener("pointermove", onPointerMove);

    if (gesture?.kind === "move" && !gesture.moved && gesture.tapToType) {
        editor.startEditing(gesture.tapToType);
    }

    gesture = null;
    guides.value = [];
    marquee.value = null;
    readout.value = null;
}

function onDoubleClick(event) {
    const element = elementAt(event);

    if (element?.type === "text") editor.startEditing(element.id);
}

function onHover(event) {
    if (gesture) return;

    hoveredId.value = elementAt(event)?.id ?? null;
}

const hovered = computed(() =>
    hoveredId.value && !editor.selection.value.includes(hoveredId.value)
        ? elements.value.find((element) => element.id === hoveredId.value) ?? null
        : null,
);

/**
 * The keys, while the canvas has the page.
 *
 * Nothing is taken from a field being typed in: a Delete in the layers'
 * rename box deletes a letter, not an element.
 */
function onKey(event) {
    if (!props.editable || !props.keyboard || !root.value) return;

    const target = event.target;
    const typing = target?.closest?.("input, textarea, select, [contenteditable='true']");
    const command = event.metaKey || event.ctrlKey;

    if (editingId.value) {
        if (event.key === "Escape") {
            event.preventDefault();
            editor.stopEditing();
            root.value.focus({ preventScroll: true });
        }

        return;
    }

    if (typing || document.querySelector("[role='dialog'][aria-modal='true']")) return;

    // Only when the canvas is the thing being worked on: focused, or holding
    // a selection. Otherwise these keys belong to the rest of the page.
    const active = root.value.contains(document.activeElement) || editor.selection.value.length > 0;

    if (!active) return;

    const key = event.key.toLowerCase();
    const step = event.shiftKey ? 5 : 0.5;
    const hasSelection = editor.selection.value.length > 0;

    /** Each key and what it does, first match wins. */
    const bindings = [
        [command && key === "z", () => (event.shiftKey ? editor.redo() : editor.undo())],
        [command && key === "y", () => editor.redo()],
        [command && key === "a", () => editor.selectAll()],
        [command && key === "v", () => editor.paste()],
        [hasSelection && (key === "delete" || key === "backspace"), () => editor.remove()],
        [hasSelection && command && key === "c" && !window.getSelection()?.toString(), () => editor.copy()],
        [hasSelection && command && key === "x", () => editor.cut()],
        [hasSelection && command && key === "d", () => editor.duplicate()],
        [hasSelection && command && key === "g", () => (event.shiftKey ? editor.ungroup() : editor.group())],
        [hasSelection && command && key === "l", () => editor.toggleLock()],
        [hasSelection && command && event.key === "]", () => editor.arrange(event.altKey ? "front" : "forward")],
        [hasSelection && command && event.key === "[", () => editor.arrange(event.altKey ? "back" : "backward")],
        [hasSelection && key === "arrowleft", () => editor.nudge(-step, 0)],
        [hasSelection && key === "arrowright", () => editor.nudge(step, 0)],
        [hasSelection && key === "arrowup", () => editor.nudge(0, -step)],
        [hasSelection && key === "arrowdown", () => editor.nudge(0, step)],
        [hasSelection && key === "escape", () => editor.clear()],
        [key === "enter" && editor.single.value?.type === "text", () => editor.startEditing(editor.single.value.id)],
    ];

    const match = bindings.find(([applies]) => applies);

    if (match) match[1]();

    const handled = !!match;

    if (handled) event.preventDefault();
}

onMounted(() => window.addEventListener("keydown", onKey));
onBeforeUnmount(() => {
    window.removeEventListener("keydown", onKey);
    window.removeEventListener("pointermove", onPointerMove);
});

/**
 * The words' toolbar, over the canvas while a box is being typed into.
 *
 * The browser's own commands, on the selection inside the box. Pressed with a
 * mouse-down that does not take the focus, or the selection they act on would
 * be gone by the time they ran.
 */
let remembered = null;

/** The selection inside the box, kept while a colour field has the focus. */
function remember() {
    const selection = window.getSelection();

    if (selection?.rangeCount) remembered = selection.getRangeAt(0).cloneRange();
}

function format(command, value = null) {
    const words = root.value?.querySelector(".fe.is-editing .fe-words");

    if (words && document.activeElement !== words) {
        words.focus({ preventScroll: true });

        if (remembered) {
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(remembered);
        }
    }

    document.execCommand("styleWithCSS", false, true);
    document.execCommand(command, false, value);
    remember();
}

const wordColour = ref("#e11d48");
const wordHighlight = ref("#fde047");
</script>

<template>
    <div class="free-canvas">
        <div v-if="editingId" class="fc-text-bar" role="toolbar" :aria-label="t('suite.studio.deliverables.slides.free.text_toolbar')">
            <button type="button" :title="t('suite.studio.deliverables.slides.free.bold')" v-on:mousedown.prevent="format('bold')"><Bold /></button>
            <button type="button" :title="t('suite.studio.deliverables.slides.free.italic')" v-on:mousedown.prevent="format('italic')"><Italic /></button>
            <button type="button" :title="t('suite.studio.deliverables.slides.free.underline')" v-on:mousedown.prevent="format('underline')"><Underline /></button>
            <button type="button" :title="t('suite.studio.deliverables.slides.free.strike')" v-on:mousedown.prevent="format('strikeThrough')"><Strikethrough /></button>
            <span class="fc-sep" />
            <label class="fc-swatch" :title="t('suite.studio.deliverables.slides.free.word_colour')">
                <span class="fc-swatch-mark" :style="{ background: wordColour }">A</span>
                <input v-model="wordColour" type="color" v-on:pointerdown="remember" v-on:change="format('foreColor', wordColour)">
            </label>
            <button type="button" :title="t('suite.studio.deliverables.slides.free.word_colour_apply')" v-on:mousedown.prevent="format('foreColor', wordColour)">
                <span class="fc-dot" :style="{ background: wordColour }" />
            </button>
            <label class="fc-swatch" :title="t('suite.studio.deliverables.slides.free.highlight')">
                <Highlighter />
                <input v-model="wordHighlight" type="color" v-on:pointerdown="remember" v-on:change="format('hiliteColor', wordHighlight)">
            </label>
            <button type="button" :title="t('suite.studio.deliverables.slides.free.highlight_apply')" v-on:mousedown.prevent="format('hiliteColor', wordHighlight)">
                <span class="fc-dot" :style="{ background: wordHighlight }" />
            </button>
            <span class="fc-sep" />
            <button type="button" :title="t('suite.studio.deliverables.slides.free.bullets')" v-on:mousedown.prevent="format('insertUnorderedList')"><List /></button>
            <button type="button" :title="t('suite.studio.deliverables.slides.free.numbers')" v-on:mousedown.prevent="format('insertOrderedList')"><ListOrdered /></button>
            <button type="button" :title="t('suite.studio.deliverables.slides.free.clear_format')" v-on:mousedown.prevent="format('removeFormat')"><RemoveFormatting /></button>
        </div>

        <div
            ref="root"
            class="fc-stage"
            :class="editable ? 'is-editable' : ''"
            tabindex="0"
            :aria-label="t('suite.studio.deliverables.slides.free.canvas')"
            v-on:pointerdown="onPointerDown"
            v-on:pointermove="onHover"
            v-on:pointerleave="hoveredId = null"
            v-on:dblclick="onDoubleClick"
        >
            <SlideFrame
                :slide="slide"
                :appearance="appearance"
                :index="index"
                :editing-id="editingId"
                v-on:text-input="editor.typeText"
            />

            <div class="fc-overlay" aria-hidden="true">
                <div v-if="hovered" class="fc-hover" :style="outlineStyle(hovered)" />

                <div
                    v-for="element in selected"
                    :key="element.id"
                    class="fc-outline"
                    :class="[element.locked ? 'is-locked' : '', element.id === editingId ? 'is-editing' : '']"
                    :style="outlineStyle(element)"
                >
                    <template v-if="selected.length === 1 && !element.locked && element.id !== editingId && editable">
                        <span
                            v-for="handle in HANDLES"
                            :key="handle.key"
                            class="fc-handle"
                            :class="`is-${handle.key}`"
                            :data-handle="handle.key"
                            :style="handleStyle(handle)"
                        />
                        <span class="fc-rotate" data-handle="rotate" />
                    </template>
                    <span v-if="element.locked" class="fc-lock"><Lock /></span>
                </div>

                <div v-if="unionStyle && !lockedOnly && editable" class="fc-union" :style="unionStyle">
                    <span
                        v-for="handle in CORNERS"
                        :key="handle.key"
                        class="fc-handle"
                        :class="`is-${handle.key}`"
                        :data-handle="handle.key"
                        :style="handleStyle(handle)"
                    />
                    <span class="fc-rotate" data-handle="rotate" />
                </div>

                <span
                    v-for="(guide, at) in guides"
                    :key="`${guide.axis}-${at}`"
                    class="fc-guide"
                    :class="guide.axis === 'x' ? 'is-vertical' : 'is-horizontal'"
                    :style="guide.axis === 'x' ? { left: `${guide.at}%` } : { top: `${guide.at}%` }"
                />

                <div
                    v-if="marquee"
                    class="fc-marquee"
                    :style="{
                        left: `${marquee.left}%`,
                        top: `${marquee.top}%`,
                        width: `${marquee.right - marquee.left}%`,
                        height: `${marquee.bottom - marquee.top}%`,
                    }"
                />

                <span v-if="readout" class="fc-readout">{{ readout }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.free-canvas { display: flex; flex-direction: column; gap: 0.5rem; }

.fc-stage {
    position: relative;
    outline: none;
    touch-action: none;
    user-select: none;
}

.fc-stage:focus-visible { box-shadow: 0 0 0 2px rgb(var(--color-accent-500, 99 102 241) / 0.5); border-radius: 0.5rem; }

.fc-stage.is-editable :deep(.fe) { cursor: move; }
.fc-stage.is-editable :deep(.fe.is-editing) { cursor: text; user-select: text; }
.fc-stage :deep(.fe.is-editing .fe-words) { user-select: text; }

.fc-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.fc-hover,
.fc-outline,
.fc-union {
    position: absolute;
    box-sizing: border-box;
    transform-origin: 50% 50%;
}

.fc-hover { outline: 1px solid rgb(56 189 248 / 0.7); }
.fc-outline { outline: 1.5px solid #38bdf8; }
.fc-outline.is-locked { outline: 1.5px dashed #f59e0b; }
.fc-outline.is-editing { outline: 1.5px dashed #38bdf8; }
.fc-union { outline: 1.5px dashed #38bdf8; }

.fc-handle {
    position: absolute;
    width: 10px;
    height: 10px;
    margin: -5px 0 0 -5px;
    background: #fff;
    border: 1.5px solid #0284c7;
    border-radius: 2px;
    pointer-events: auto;
    touch-action: none;
}

.fc-handle.is-n,
.fc-handle.is-s { width: 16px; height: 6px; margin: -3px 0 0 -8px; border-radius: 3px; cursor: ns-resize; }
.fc-handle.is-e,
.fc-handle.is-w { width: 6px; height: 16px; margin: -8px 0 0 -3px; border-radius: 3px; cursor: ew-resize; }
.fc-handle.is-nw,
.fc-handle.is-se { cursor: nwse-resize; }
.fc-handle.is-ne,
.fc-handle.is-sw { cursor: nesw-resize; }

.fc-rotate {
    position: absolute;
    left: 50%;
    top: -26px;
    width: 14px;
    height: 14px;
    margin-left: -7px;
    background: #fff;
    border: 1.5px solid #0284c7;
    border-radius: 9999px;
    pointer-events: auto;
    cursor: grab;
    touch-action: none;
}

.fc-rotate::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 12px;
    width: 1.5px;
    height: 12px;
    background: #0284c7;
    transform: translateX(-50%);
}

/* With a finger, handles a finger can grab. */
@media (pointer: coarse) {
    .fc-handle,
    .fc-handle.is-n,
    .fc-handle.is-s,
    .fc-handle.is-e,
    .fc-handle.is-w {
        width: 22px;
        height: 22px;
        margin: -11px 0 0 -11px;
        border-radius: 9999px;
    }

    .fc-rotate { width: 24px; height: 24px; margin-left: -12px; top: -40px; }
    .fc-rotate::after { top: 22px; height: 16px; }
}

.fc-lock {
    position: absolute;
    right: -8px;
    top: -8px;
    display: flex;
    width: 18px;
    height: 18px;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    background: #f59e0b;
    color: #fff;
}

.fc-lock > svg { width: 11px; height: 11px; }

.fc-guide { position: absolute; background: #ec4899; }
.fc-guide.is-vertical { top: 0; bottom: 0; width: 1px; }
.fc-guide.is-horizontal { left: 0; right: 0; height: 1px; }

.fc-marquee {
    position: absolute;
    background: rgb(56 189 248 / 0.12);
    outline: 1px solid rgb(56 189 248 / 0.8);
}

.fc-readout {
    position: absolute;
    right: 6px;
    bottom: 6px;
    padding: 2px 6px;
    border-radius: 4px;
    background: #0284c7;
    color: #fff;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}

/* The text bar: small, above the slide, stuck to what it edits. */
.fc-text-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px;
    padding: 4px;
    border: 1px solid var(--color-line, #30363d);
    border-radius: 0.5rem;
    background: var(--color-surface, #0d1117);
}

.fc-text-bar button,
.fc-swatch {
    position: relative;
    display: inline-flex;
    width: 30px;
    height: 30px;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--color-text-secondary, inherit);
    cursor: pointer;
}

.fc-text-bar button:hover,
.fc-swatch:hover { background: color-mix(in srgb, currentColor 12%, transparent); }
.fc-text-bar svg { width: 16px; height: 16px; }

.fc-swatch input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.fc-swatch-mark {
    display: inline-flex;
    width: 18px;
    height: 18px;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
}

.fc-dot { display: block; width: 12px; height: 12px; border-radius: 9999px; border: 1px solid rgb(0 0 0 / 0.2); }
.fc-sep { width: 1px; height: 20px; margin: 0 4px; background: var(--color-line, #30363d); }
</style>
