<script setup>
import { computed, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Palette, Plus, Trash2, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";

/**
 * Declines a visual in another colour, as a new alternate of its family.
 *
 * The server does the pixel work - every pixel of the visual's hue takes the
 * new one and keeps its lightness - so this is only the questions: which
 * colour, under which label, and what to leave alone. Asked from an
 * alternate, the original is what gets declined.
 */
const props = defineProps({
    // The document the gesture started from, or null when closed.
    doc: { type: Object, default: null },
    recolorPath: { type: String, required: true },
    // The theme's main colour, offered in one click.
    themeColor: { type: String, default: "" },
    labelSuggestions: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "created"]);

const { t } = useI18n();
const { request } = useRequest();

const MAX_SPARED = 8;

const form = reactive({
    color: null,
    label: "",
    sourceMode: "auto",
    sourceColor: null,
    spare: [],
    protectDetail: true,
});
const errors = ref({});
const saving = ref(false);

watch(
    () => props.doc?.id,
    (id) => {
        if (!id) return;
        Object.assign(form, {
            color: null,
            label: "",
            sourceMode: "auto",
            sourceColor: null,
            spare: [],
            protectDetail: true,
        });
        errors.value = {};
    },
);

const sourceOptions = computed(() => [
    { value: "auto", label: t("backend.ged.documents.recolor.source_auto") },
    { value: "pick", label: t("backend.ged.documents.recolor.source_pick") },
]);

const originalTitle = computed(() => props.doc?.originalTitle ?? props.doc?.title ?? "");

function addSpare() {
    if (form.spare.length < MAX_SPARED) form.spare.push("#ffffff");
}

async function submit() {
    if (!props.doc || saving.value) return;

    saving.value = true;
    errors.value = {};
    try {
        const data = await request(buildPath(props.recolorPath, { id: props.doc.id }), {
            color: form.color,
            label: form.label,
            sourceColor: "pick" === form.sourceMode ? form.sourceColor : null,
            spare: form.spare.filter(Boolean),
            protectDetail: form.protectDetail,
        });
        if (!data) return;
        if (!data.success) {
            errors.value = data.errors ?? {};

            return;
        }

        toast.success(t("backend.ged.documents.recolor.created", { label: form.label }));
        emit("created", data.document);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppModal
        :show="!!doc"
        max-width="md"
        :title="t('backend.ged.documents.recolor.title')"
        :icon="Palette"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <p class="text-sm text-secondary">
                {{ t("backend.ged.documents.recolor.intro", { title: originalTitle }) }}
            </p>

            <div class="space-y-2">
                <AppColorPicker
                    v-model="form.color"
                    :label="t('backend.ged.documents.recolor.color')"
                    required
                    :error="errors.color ? t(errors.color) : ''"
                />
                <button
                    v-if="themeColor"
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs px-2 py-1 rounded border border-line text-secondary hover:text-primary hover:border-accent-400 transition-colors"
                    v-on:click="form.color = themeColor"
                >
                    <span class="h-3 w-3 rounded-full border border-line" :style="{ backgroundColor: themeColor }" aria-hidden="true" />
                    {{ t("backend.ged.documents.recolor.theme_color") }}
                </button>
            </div>

            <div class="space-y-1">
                <AppInput
                    v-model="form.label"
                    :label="t('backend.ged.documents.family.label')"
                    :placeholder="t('backend.ged.documents.family.label_placeholder')"
                    :error="errors.label ? t(errors.label) : ''"
                    maxlength="40"
                    required
                />
                <div v-if="labelSuggestions.length" class="flex flex-wrap items-center gap-1">
                    <span class="text-xs text-muted">{{ t("backend.ged.documents.family.label_suggestions") }}</span>
                    <button
                        v-for="suggestion in labelSuggestions"
                        :key="suggestion"
                        type="button"
                        class="text-xs px-1.5 py-0.5 rounded border transition-colors"
                        :class="form.label === suggestion ? 'border-violet-500 text-violet-600 dark:text-violet-400' : 'border-line text-secondary hover:text-primary'"
                        v-on:click="form.label = suggestion"
                    >
                        {{ suggestion }}
                    </button>
                </div>
            </div>

            <div class="space-y-2">
                <AppChoiceRow
                    v-model="form.sourceMode"
                    :options="sourceOptions"
                    :label="t('backend.ged.documents.recolor.source')"
                    :hint="t('backend.ged.documents.recolor.source_hint')"
                />
                <AppColorPicker
                    v-if="'pick' === form.sourceMode"
                    v-model="form.sourceColor"
                    :error="errors.sourceColor ? t(errors.sourceColor) : ''"
                />
            </div>

            <div class="space-y-2">
                <p class="text-sm font-medium text-primary">{{ t("backend.ged.documents.recolor.spare") }}</p>
                <p class="text-xs text-muted">{{ t("backend.ged.documents.recolor.spare_hint") }}</p>
                <div v-for="(colour, index) in form.spare" :key="index" class="flex items-end gap-2">
                    <AppColorPicker v-model="form.spare[index]" class="flex-1" />
                    <AppIconButton
                        :title="t('shared.common.delete')"
                        v-on:click="form.spare.splice(index, 1)"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                </div>
                <AppButton
                    v-if="form.spare.length < MAX_SPARED"
                    variant="ghost"
                    size="sm"
                    type="button"
                    v-on:click="addSpare"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.ged.documents.recolor.spare_add") }}
                </AppButton>
            </div>

            <AppToggle
                v-model="form.protectDetail"
                :label="t('backend.ged.documents.recolor.protect_detail')"
                :hint="t('backend.ged.documents.recolor.protect_detail_hint')"
            />

            <p v-if="saving" class="text-xs text-muted">{{ t("backend.ged.documents.recolor.working") }}</p>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" :disabled="saving" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    :disabled="saving || !form.color || !form.label.trim()"
                    :loading="saving"
                    v-on:click="submit"
                >
                    <Palette class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.ged.documents.recolor.submit") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
