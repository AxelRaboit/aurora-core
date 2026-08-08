<script setup>
/**
 * A colour field for the banner: the swatch you can set, and nothing else.
 *
 * AppColorField on its own cannot express "unset" — it wraps an
 * `<input type="color">`, which always holds a value. The banner needs that
 * distinction: a null title colour inherits the theme, where a set one
 * overrides it. Hence the clear button, shown only once there is something to
 * clear.
 *
 * AppColorPicker would bring the client's configured palette, but it renders
 * an eight-column grid of swatches per field — far too much furniture for a
 * panel that carries five of these.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppColorField from "@/shared/components/form/picker/AppColorField.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { X } from "lucide-vue-next";

const props = defineProps({
    modelValue: { type: String, default: null },
    label: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

// The field wants a string; the model stores null for "inherit".
const value = computed({
    get: () => props.modelValue ?? "",
    set: (next) => emit("update:modelValue", next || null),
});
</script>

<template>
    <div class="flex items-end gap-2">
        <AppColorField v-model="value" :label="label" class="flex-1" />
        <AppIconButton
            v-if="modelValue"
            color="default"
            :title="t('backend.posts.banner.clear_color')"
            v-on:click="emit('update:modelValue', null)"
        >
            <X class="w-4 h-4" :stroke-width="2" />
        </AppIconButton>
    </div>
</template>
