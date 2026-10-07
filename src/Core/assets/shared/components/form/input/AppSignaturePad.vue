<script setup>
/**
 * A signature drawn with a finger or a mouse.
 *
 * Written rather than pulled in: the job is a canvas, pointer events and a
 * data URI, and a dependency for one screen of one module would have to be
 * carried by every deployment of the bundle. Pointer events rather than
 * separate mouse and touch handlers, so a stylus, a finger and a trackpad are
 * the same code path.
 *
 * In `@shared` rather than in the module that needed it first: the customer
 * signs on a public page and the provider countersigns in the back office, so
 * there were two consumers on the day it was written, and it knows nothing
 * about contracts.
 *
 * The canvas is sized from its own box and the device pixel ratio, on mount and
 * on resize. Without that the drawing is either blurry on a phone or stretched
 * when the layout reflows, and a signature that does not look like the person's
 * is worse than no picture at all.
 */
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Eraser } from "lucide-vue-next";

const props = defineProps({
    disabled: { type: Boolean, default: false },
    label: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue", "drawn"]);

const { t } = useI18n();

const canvas = ref(null);
const hasDrawing = ref(false);

let context = null;
let drawing = false;
let observer = null;

/**
 * Matches the backing store to the box and the screen.
 *
 * The existing drawing is carried across rather than cleared: a resize can be
 * a phone rotating, and losing a signature to that would be a small disaster
 * in the middle of signing.
 */
function fit() {
    const canvasElement = canvas.value;

    if (!canvasElement) return;

    const ratio = window.devicePixelRatio || 1;
    const { width, height } = canvasElement.getBoundingClientRect();

    if (width === 0 || height === 0) return;

    const previous =
        hasDrawing.value && canvasElement.width > 0 ? canvasElement.toDataURL("image/png") : null;

    canvasElement.width = Math.round(width * ratio);
    canvasElement.height = Math.round(height * ratio);

    context = canvasElement.getContext("2d");
    context.scale(ratio, ratio);
    context.lineWidth = 2;
    context.lineCap = "round";
    context.lineJoin = "round";
    // Read from the computed style so the stroke follows the theme rather than
    // being a hardcoded black that disappears on a dark ground.
    context.strokeStyle = window.getComputedStyle(canvasElement).color;

    if (previous) {
        const image = new Image();
        image.onload = () => context.drawImage(image, 0, 0, width, height);
        image.src = previous;
    }
}

function pointFrom(event) {
    const { left, top } = canvas.value.getBoundingClientRect();

    return { x: event.clientX - left, y: event.clientY - top };
}

function start(event) {
    if (props.disabled) return;

    drawing = true;
    // Captured so a stroke that leaves the canvas keeps being tracked instead
    // of ending mid-signature.
    canvas.value.setPointerCapture(event.pointerId);

    const { x, y } = pointFrom(event);
    context.beginPath();
    context.moveTo(x, y);
}

function move(event) {
    if (!drawing || props.disabled) return;

    const { x, y } = pointFrom(event);
    context.lineTo(x, y);
    context.stroke();

    if (!hasDrawing.value) {
        hasDrawing.value = true;
        emit("drawn", true);
    }
}

function end(event) {
    if (!drawing) return;

    drawing = false;

    if (canvas.value.hasPointerCapture(event.pointerId)) {
        canvas.value.releasePointerCapture(event.pointerId);
    }

    emit("update:modelValue", canvas.value.toDataURL("image/png"));
}

function clear() {
    if (!context || props.disabled) return;

    const { width, height } = canvas.value.getBoundingClientRect();
    context.clearRect(0, 0, width, height);
    hasDrawing.value = false;
    emit("drawn", false);
    emit("update:modelValue", "");
}

onMounted(() => {
    fit();

    if (typeof ResizeObserver !== "undefined") {
        observer = new ResizeObserver(fit);
        observer.observe(canvas.value);
    } else {
        window.addEventListener("resize", fit);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener("resize", fit);
});

defineExpose({ clear });
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-sm text-secondary">{{ label }}</span>
            <button
                v-if="hasDrawing && !disabled"
                type="button"
                class="flex items-center gap-1 text-xs text-muted hover:text-primary"
                v-on:click="clear"
            >
                <Eraser class="w-3 h-3" :stroke-width="2" />
                {{ t("shared.form.signature_pad.clear") }}
            </button>
        </div>

        <!-- `touch-action: none` is what makes drawing possible on a phone at
             all: without it the browser scrolls the page instead of tracking
             the finger. -->
        <canvas
            ref="canvas"
            class="block h-40 w-full rounded-lg border border-dashed border-line bg-surface text-primary"
            :class="{ 'opacity-60': disabled }"
            style="touch-action: none"
            v-on:pointerdown="start"
            v-on:pointermove="move"
            v-on:pointerup="end"
            v-on:pointercancel="end"
        />

        <p v-if="!hasDrawing" class="text-xs text-muted">
            {{ t("shared.form.signature_pad.hint") }}
        </p>
    </div>
</template>
