<script setup>
import { computed, toRef } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useImageUpload } from "@/shared/composables/http/backend/useImageUpload.js";
import { usePostReferenceField } from "@editorial/backend/posts/composables/usePostReferenceField.js";
import { X, ImagePlus, Upload } from "lucide-vue-next";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppFilePickerButton from "@/shared/components/action/AppFilePickerButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppListItemButton from "@/shared/components/action/AppListItemButton.vue";
import { statusBadge } from "@/shared/utils/format/statusStyles.js";
import { PostFieldType } from "@editorial/shared/enums/postFieldType.js";

const { t } = useI18n();

const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: [String, Number, Boolean, Array, Object], default: null },
});

const emit = defineEmits(["update:modelValue"]);

const label = computed(() => props.field.label);

function update(value) {
    emit("update:modelValue", value);
}

const { isReference, isMultiple, resolved, search, results, open, loading, runSearch, addReference, removeReference } =
    usePostReferenceField({
        field: toRef(props, "field"),
        modelValue: toRef(props, "modelValue"),
        update,
    });

const { uploading, inputRef: mediaInput, uploadFromEvent: uploadMedia } = useImageUpload({
    onSuccess: ({ file }) => update(file?.id ?? null),
    onError: () => toast.error(t("shared.common.error")),
});
</script>

<template>
    <div>
        <AppInput
            v-if="field.type === PostFieldType.Text"
            :model-value="modelValue ?? ''"
            :label="label"
            :required="field.required"
            v-on:update:model-value="update"
        />

        <AppTextarea
            v-else-if="field.type === PostFieldType.Textarea"
            :model-value="modelValue ?? ''"
            :label="label"
            :required="field.required"
            :rows="4"
            v-on:update:model-value="update"
        />

        <AppInput
            v-else-if="field.type === PostFieldType.Number"
            type="number"
            :model-value="modelValue === null || modelValue === undefined ? '' : String(modelValue)"
            :label="label"
            :required="field.required"
            v-on:update:model-value="(v) => update(v === '' ? null : Number(v))"
        />

        <AppDatePicker
            v-else-if="field.type === PostFieldType.Date"
            :model-value="modelValue ?? ''"
            :label="label"
            :required="field.required"
            v-on:update:model-value="update"
        />

        <AppInput
            v-else-if="field.type === PostFieldType.Url"
            type="url"
            :model-value="modelValue ?? ''"
            :label="label"
            :required="field.required"
            v-on:update:model-value="update"
        />

        <AppInput
            v-else-if="field.type === PostFieldType.Email"
            type="email"
            :model-value="modelValue ?? ''"
            :label="label"
            :required="field.required"
            v-on:update:model-value="update"
        />

        <AppSelect
            v-else-if="field.type === PostFieldType.Select"
            :model-value="modelValue ?? ''"
            :label="label"
            :required="field.required"
            v-on:update:model-value="update"
        >
            <option value="">—</option>
            <option v-for="choice in field.options?.choices ?? []" :key="choice.value" :value="choice.value">
                {{ choice.label }}
            </option>
        </AppSelect>

        <AppCheckbox
            v-else-if="field.type === PostFieldType.Checkbox"
            :model-value="!!modelValue"
            :label="label"
            v-on:update:model-value="update"
        />

        <div v-else-if="field.type === PostFieldType.Media" class="flex flex-col gap-1.5">
            <label class="block text-xs text-secondary uppercase tracking-wide">{{ label }}</label>
            <div class="flex items-center gap-2">
                <div class="w-16 h-12 rounded-md border border-line bg-surface-2 overflow-hidden shrink-0 flex items-center justify-center">
                    <span v-if="modelValue" class="text-xs text-muted font-mono">#{{ modelValue }}</span>
                    <ImagePlus v-else class="w-4 h-4 text-muted" :stroke-width="2" />
                </div>
                <AppFilePickerButton
                    ref="mediaInput"
                    accept="image/*"
                    variant="secondary"
                    size="sm"
                    :loading="uploading"
                    v-on:change="uploadMedia"
                >
                    <Upload class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.custom_field.upload") }}
                </AppFilePickerButton>
                <AppButton v-if="modelValue" variant="ghost" size="sm" v-on:click="update(null)">
                    <X class="w-3.5 h-3.5" :stroke-width="2" />
                </AppButton>
            </div>
        </div>

        <div v-else-if="isReference" class="flex flex-col gap-1.5">
            <label class="block text-xs text-secondary uppercase tracking-wide">{{ label }}</label>

            <div v-if="resolved.length" class="flex flex-col gap-1">
                <div
                    v-for="result in resolved"
                    :key="result.id"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-md bg-surface border border-line/60"
                >
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium" :class="statusBadge(result.status)">
                        {{ t("backend.posts.status_options." + result.status) }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm text-primary truncate">{{ result.title ?? "(—)" }}</div>
                        <div class="text-xs text-muted truncate">{{ result.postType }}</div>
                    </div>
                    <AppIconButton color="rose" v-on:click="removeReference(result.id)">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </div>

            <div v-if="isMultiple || resolved.length === 0" class="relative">
                <AppInput
                    v-model="search"
                    :placeholder="t('backend.posts.related_posts.search_placeholder')"
                    v-on:focus="open = true; runSearch()"
                    v-on:blur="setTimeout(() => { open = false; }, 150)"
                />
                <div
                    v-if="open && (results.length || loading)"
                    class="absolute z-10 mt-1 w-full max-h-64 overflow-y-auto rounded-md border border-line bg-surface shadow-lg"
                >
                    <div v-if="loading" class="px-3 py-2 text-xs text-muted">{{ t("shared.common.loading") }}</div>
                    <AppListItemButton
                        v-for="result in results"
                        :key="result.id"
                        v-on:mousedown.prevent="addReference(result)"
                    >
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium" :class="statusBadge(result.status)">
                            {{ t("backend.posts.status_options." + result.status) }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm text-primary truncate">{{ result.title ?? "(—)" }}</div>
                            <div class="text-xs text-muted truncate">{{ result.postType }}</div>
                        </div>
                    </AppListItemButton>
                </div>
            </div>
        </div>
    </div>
</template>
