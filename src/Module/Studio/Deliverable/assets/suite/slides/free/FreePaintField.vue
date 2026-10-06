<script setup>
/**
 * A paint: nothing, a colour, or a gradient of two to six.
 *
 * The gradient is edited as what it is - a list of colours with a position
 * each, and an angle - rather than through a picture of a gradient bar with
 * draggable stops: the list works with a keyboard and on a phone, and the
 * preview above it shows the result at once.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Plus, Trash2 } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";
import FreeColourField from "./FreeColourField.vue";
import { paint } from "./model.js";

const props = defineProps({
    modelValue: { type: Object, default: null },
    label: { type: String, default: "" },
    appearance: { type: Object, default: null },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const kind = computed(() => props.modelValue?.type ?? "none");

const KINDS = ["none", "solid", "linear", "radial"];

const preview = computed(() => paint(props.modelValue));

/** Changing the kind keeps the colours already chosen. */
function pickKind(next) {
    const current = props.modelValue;
    const first = current?.color ?? current?.stops?.[0]?.color ?? "accent";
    const second = current?.stops?.[1]?.color ?? "background";

    if (next === "none") return emit("update:modelValue", null);
    if (next === "solid") return emit("update:modelValue", { type: "solid", color: first });

    const stops = current?.stops ?? [
        { color: first, at: 0 },
        { color: second, at: 100 },
    ];

    emit("update:modelValue", next === "linear" ? { type: "linear", angle: current?.angle ?? 135, stops } : { type: "radial", stops });
}

function writeStop(at, change) {
    const stops = props.modelValue.stops.map((stop, index) => (index === at ? { ...stop, ...change } : stop));

    emit("update:modelValue", { ...props.modelValue, stops });
}

function addStop() {
    const stops = [...props.modelValue.stops];

    if (stops.length >= 6) return;

    stops.push({ color: stops[stops.length - 1].color, at: 100 });
    emit("update:modelValue", { ...props.modelValue, stops });
}

function removeStop(at) {
    if (props.modelValue.stops.length <= 2) return;

    emit("update:modelValue", { ...props.modelValue, stops: props.modelValue.stops.filter((_, index) => index !== at) });
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <span v-if="label" class="text-xs font-medium uppercase tracking-wide text-secondary">{{ label }}</span>

        <div class="inline-flex w-fit rounded-lg border border-line bg-surface-2/40 p-0.5">
            <button
                v-for="option in KINDS"
                :key="option"
                type="button"
                class="cursor-pointer rounded-md border-0 px-2.5 py-1 text-xs"
                :class="kind === option ? 'bg-surface font-medium text-primary shadow-sm' : 'bg-transparent text-muted'"
                v-on:click="pickKind(option)"
            >
                {{ t(`suite.studio.deliverables.slides.free.paints.${option}`) }}
            </button>
        </div>

        <div v-if="preview && kind !== 'solid'" class="h-6 w-full rounded-md border border-line" :style="{ background: preview }" />

        <FreeColourField
            v-if="kind === 'solid'"
            :model-value="modelValue.color"
            :appearance="appearance"
            v-on:update:model-value="(color) => emit('update:modelValue', { type: 'solid', color: color ?? 'accent' })"
        />

        <template v-if="kind === 'linear' || kind === 'radial'">
            <div v-if="kind === 'linear'" class="flex items-center gap-2">
                <span class="w-16 shrink-0 text-xs text-muted">{{ t("suite.studio.deliverables.slides.free.angle") }}</span>
                <AppRange
                    :model-value="modelValue.angle ?? 135"
                    :min="0"
                    :max="360"
                    :step="5"
                    v-on:update:model-value="(angle) => emit('update:modelValue', { ...modelValue, angle })"
                />
                <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted">{{ modelValue.angle ?? 135 }}°</span>
            </div>

            <div v-for="(stop, at) in modelValue.stops" :key="at" class="flex flex-col gap-1 rounded-md border border-line p-2">
                <div class="flex items-start gap-2">
                    <FreeColourField
                        class="flex-1"
                        :model-value="stop.color"
                        :appearance="appearance"
                        v-on:update:model-value="(color) => writeStop(at, { color: color ?? 'accent' })"
                    />
                    <AppIconButton
                        v-if="modelValue.stops.length > 2"
                        size="sm"
                        variant="ghost"
                        :title="t('suite.studio.deliverables.slides.free.remove_stop')"
                        v-on:click="removeStop(at)"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                </div>
                <div class="flex items-center gap-2">
                    <AppRange :model-value="stop.at" :min="0" :max="100" v-on:update:model-value="(value) => writeStop(at, { at: value })" />
                    <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted">{{ stop.at }} %</span>
                </div>
            </div>

            <button
                v-if="modelValue.stops.length < 6"
                type="button"
                class="inline-flex w-fit cursor-pointer items-center gap-1 rounded-md border-0 bg-transparent px-1 py-0.5 text-xs text-muted hover:text-primary"
                v-on:click="addStop"
            >
                <Plus class="h-3 w-3" :stroke-width="2" />
                {{ t("suite.studio.deliverables.slides.free.add_stop") }}
            </button>
        </template>
    </div>
</template>
