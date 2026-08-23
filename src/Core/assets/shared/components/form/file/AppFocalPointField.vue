<script setup>
/**
 * Picks the point a picture is cropped around, by clicking on the picture.
 *
 * Two numbers between 0 and 1 - the fraction across and the fraction down.
 * They become `object-position` at render, which is what decides what survives
 * when a wide photo has to fill a narrow frame.
 *
 * Clicking beats two number fields because the question is "which part of
 * *this* picture matters", and that is a question about the picture: you point
 * at the face, you do not compute where the face is.
 *
 * `null` on both axes means "no override" - the point stored on the document
 * itself is used instead. Half an override is not a position, so clearing
 * clears both, and the crosshair falls back to showing the inherited point
 * rather than disappearing.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import { RotateCcw } from "lucide-vue-next";

const props = defineProps({
    /** The picture to aim at. Nothing renders without one. */
    src: { type: String, default: "" },
    label: { type: String, default: "" },
    hint: { type: String, default: "" },
    /** Fraction across, 0..1, or null to inherit the document's. */
    x: { type: Number, default: null },
    /** Fraction down, 0..1, or null to inherit the document's. */
    y: { type: Number, default: null },
    /**
     * Where the document itself says to crop, as an `object-position` string.
     * Shown as the starting point so clearing does not look like a reset to
     * the centre when the document says otherwise.
     */
    inherited: { type: String, default: "50% 50%" },
    /** How the picture fills its own preview - mirrors what a card will do. */
    fitClass: { type: String, default: "object-cover" },
});

const emit = defineEmits(["update:x", "update:y"]);

const { t } = useI18n();

const overridden = computed(() => null !== props.x && null !== props.y);

/** Where to draw the crosshair: the override, or the inherited point. */
const marker = computed(() => {
    if (overridden.value) {
        return { left: `${props.x * 100}%`, top: `${props.y * 100}%` };
    }

    const [left = "50%", top = "50%"] = props.inherited.split(/\s+/);

    return { left, top };
});

const position = computed(() => (overridden.value
    ? `${Math.round(props.x * 100)}% ${Math.round(props.y * 100)}%`
    : props.inherited));

function pick(event) {
    const box = event.currentTarget.getBoundingClientRect();

    if (0 === box.width || 0 === box.height) {
        return;
    }

    // Clamped rather than trusted: a pointer can land a fraction outside the
    // box on a fast drag, and a coordinate outside the picture is not a point
    // on it.
    const clamp = (value) => Math.min(1, Math.max(0, value));

    emit("update:x", Number(clamp((event.clientX - box.left) / box.width).toFixed(4)));
    emit("update:y", Number(clamp((event.clientY - box.top) / box.height).toFixed(4)));
}

function reset() {
    emit("update:x", null);
    emit("update:y", null);
}
</script>

<template>
    <div v-if="src">
        <p v-if="label" class="text-xs font-medium text-secondary uppercase tracking-wide mb-1.5">{{ label }}</p>

        <div class="flex items-start gap-3">
            <button
                type="button"
                class="relative block h-32 w-48 shrink-0 overflow-hidden rounded-lg border border-line cursor-crosshair"
                :title="t('shared.media.focal_pick')"
                v-on:click="pick"
            >
                <img :src="src" alt="" class="h-full w-full pointer-events-none" :class="fitClass">
                <!-- Two rings so the marker stays visible on a light picture
                     and on a dark one, without a backdrop hiding what is under
                     it - which is the thing being aimed at. -->
                <span
                    class="pointer-events-none absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white ring-2 ring-black/50"
                    :style="marker"
                />
            </button>

            <div class="flex flex-col gap-2">
                <p class="text-xs text-muted tabular-nums">{{ position }}</p>
                <p v-if="!overridden" class="text-xs text-muted">{{ t("shared.media.focal_inherited") }}</p>
                <AppButton
                    v-else
                    variant="ghost"
                    size="sm"
                    type="button"
                    v-on:click="reset"
                >
                    <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("shared.media.focal_reset") }}
                </AppButton>
            </div>
        </div>

        <p v-if="hint" class="text-xs text-muted mt-2">{{ hint }}</p>
    </div>
</template>
