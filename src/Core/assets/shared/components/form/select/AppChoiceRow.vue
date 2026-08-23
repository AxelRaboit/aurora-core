<script setup>
/**
 * A row of named choices, one of which is current.
 *
 * The same job a `<select>` does, laid out so that every option is already on
 * screen and one click away. Worth the space when the options are few and named
 * - a width of "a half" reads as what it does, where a number reads as a
 * coordinate to work out.
 *
 * Built as a radio group rather than a row of buttons, because that is what it
 * is: arrows move through the options and take the selection with them, Home
 * and End reach the ends, and only one option is in the tab order so the group
 * is a single stop rather than N. That last part is the whole reason this is a
 * shared component instead of a handful of buttons at the call site.
 *
 * `modelValue` may sit outside the options - a width set by other means has no
 * choice to light up. The group stays reachable in that case: the first option
 * takes the tab stop, and nothing reads as checked.
 */
import { computed, ref } from "vue";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";

const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    /** `{ value, label }`, and optionally `title` for a longer spoken name. */
    options: { type: Array, required: true },
    label: { type: String, default: "" },
    hint: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

const buttons = ref([]);

const checkedIndex = computed(() =>
    props.options.findIndex((option) => option.value === props.modelValue),
);

/**
 * One tab stop for the group, on the checked option - or on the first, when
 * nothing is checked, so the group can still be reached from the keyboard.
 */
function tabIndexFor(index) {
    const stop = -1 === checkedIndex.value ? 0 : checkedIndex.value;

    return index === stop ? 0 : -1;
}

function select(index) {
    if (props.disabled) {
        return;
    }

    emit("update:modelValue", props.options[index].value);
}

function onKeydown(index, event) {
    const last = props.options.length - 1;

    const asked = {
        ArrowRight: index + 1,
        ArrowDown: index + 1,
        ArrowLeft: index - 1,
        ArrowUp: index - 1,
        Home: 0,
        End: last,
    }[event.key];

    if (undefined === asked) {
        return;
    }

    event.preventDefault();

    // Arrows wrap, which is what a radio group does - Home and End are already
    // in range, so the modulo leaves them alone.
    const target = (asked + props.options.length) % props.options.length;

    select(target);
    buttons.value[target]?.focus();
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" />

        <div
            role="radiogroup"
            :aria-label="label || undefined"
            class="flex flex-wrap gap-1"
        >
            <button
                v-for="(option, index) in options"
                :key="option.value"
                :ref="(el) => (buttons[index] = el)"
                type="button"
                role="radio"
                :aria-checked="option.value === modelValue"
                :title="option.title ?? option.label"
                :disabled="disabled"
                :tabindex="tabIndexFor(index)"
                class="rounded-md border px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
                :class="
                    option.value === modelValue
                        ? 'border-accent bg-accent/10 text-primary'
                        : 'border-line bg-surface text-secondary hover:border-secondary'
                "
                v-on:click="select(index)"
                v-on:keydown="onKeydown(index, $event)"
            >
                {{ option.label }}
            </button>
        </div>

        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
    </div>
</template>
