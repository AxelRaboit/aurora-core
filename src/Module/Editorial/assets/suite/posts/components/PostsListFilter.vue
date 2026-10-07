<script setup>
import { computed, ref } from "vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";

/**
 * One filter of the publications list: several values, one line high.
 *
 * `AppMultiselect` in `multiple` mode writes each chosen value as a tag inside
 * the field, which grows by a line every two or three values and pushes the
 * whole toolbar down. Here the tags are hidden and the closed field says what
 * it holds in one line: the value itself, or « 3 statuts ». The chips under
 * the toolbar list every value with its own way out.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    /** `{ value, label }` pairs, the shape `AppMultiselect` reads. */
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: "" },
    /** What the field reads with two values or more, given their number. */
    countLabel: { type: Function, required: true },
});

const emit = defineEmits(["update:modelValue"]);

const isOpen = ref(false);

const summary = computed(() => {
    if (0 === props.modelValue.length) {
        return "";
    }

    if (1 === props.modelValue.length) {
        return props.options.find((option) => option.value === props.modelValue[0])?.label ?? "";
    }

    return props.countLabel(props.modelValue.length);
});
</script>

<template>
    <div class="posts-list-filter relative">
        <AppMultiselect
            :model-value="modelValue"
            :options="options"
            :placeholder="placeholder"
            :multiple="true"
            :allow-empty="true"
            v-on:update:model-value="emit('update:modelValue', $event)"
            v-on:open="isOpen = true"
            v-on:close="isOpen = false"
        />
        <!-- Above the field and transparent to the pointer: a click on it
             still opens the list underneath. Gone while the list is open,
             where the search field takes the line. -->
        <span
            v-if="summary && !isOpen"
            class="pointer-events-none absolute inset-y-0 left-0 right-10 flex items-center pl-3 text-sm text-primary"
        >
            <span class="truncate">{{ summary }}</span>
        </span>
    </div>
</template>

<style scoped>
.posts-list-filter :deep(.multiselect__tags-wrap) {
    display: none;
}
</style>
