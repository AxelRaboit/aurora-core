<script setup>
import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import { X } from "lucide-vue-next";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    label: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    error: { type: String, default: "" },
    /** Help text under the control - explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
});
const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();
const draft = ref("");

const tags = computed(() =>
    Array.isArray(props.modelValue) ? props.modelValue : [],
);

function commit() {
    const value = draft.value.trim();
    if (value === "") return;
    if (tags.value.includes(value)) {
        draft.value = "";
        return;
    }
    emit("update:modelValue", [...tags.value, value]);
    draft.value = "";
}

function remove(tag) {
    emit(
        "update:modelValue",
        tags.value.filter((existing) => existing !== tag),
    );
}

function onKeydown(event) {
    if (event.key === "Enter" || event.key === ",") {
        event.preventDefault();
        commit();
    } else if (event.key === "Backspace" && draft.value === "" && tags.value.length > 0) {
        emit("update:modelValue", tags.value.slice(0, -1));
    }
}

const placeholderText = computed(
    () => props.placeholder || t("shared.common.add_tag_hint"),
);
</script>

<template>
    <div class="space-y-1">
        <AppFieldLabel v-if="label" :label="label" />
        <!-- No resting border, for a quieter field. Two things it used to carry
             had to move rather than go: focus is now a ring drawn on
             `focus-within`, since the caret sits in a child input and the
             wrapper is what the reader sees; and the error state, which was a
             red border, is a red ring - an error that only showed as a border
             would have disappeared with it. The padding is untouched, so
             nothing shifts. -->
        <div
            class="flex flex-wrap gap-1.5 items-center min-h-10.5 px-2.5 py-1.5 rounded-md bg-surface transition-shadow focus-within:ring-1 focus-within:ring-accent-500"
            :class="error ? 'ring-1 ring-rose-400' : ''"
        >
            <span
                v-for="tag in tags"
                :key="tag"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-surface-2 text-primary border border-line"
            >
                {{ tag }}
                <button
                    type="button"
                    class="hover:text-rose-500 transition-colors"
                    v-on:click="remove(tag)"
                >
                    <X class="w-3 h-3" :stroke-width="2" />
                </button>
            </span>
            <input
                v-model="draft"
                type="text"
                class="flex-1 min-w-30 bg-transparent outline-none text-sm text-primary placeholder:text-muted"
                :placeholder="placeholderText"
                v-on:keydown="onKeydown"
                v-on:blur="commit"
            >
        </div>
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-rose-500">{{ error }}</p>
    </div>
</template>
