<script setup>
/**
 * « Importer un texte »: a pasted or typed text that becomes a presentation,
 * a heading per slide, what follows to fill it.
 *
 * The same modal in Studio and in a space's Deliverables tab. What only
 * applies to one of them (Studio's category and shelf) goes through the
 * default slot, between the title and the text.
 *
 * The text lives in the modal and is never saved: what remains is the slides
 * it gives. A draft kept aside would be a second version of the same text.
 */
import { useI18n } from "vue-i18n";
import { FileInput, X } from "lucide-vue-next";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";

const title = defineModel("title", { type: String, default: "" });
const blocks = defineModel("blocks", { type: Array, default: () => [] });

defineProps({
    show: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    /** The server's errors, by field: `title`, `blocks`. */
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["close", "submit"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="show"
        max-width="4xl"
        :closeable="false"
        :title="t('suite.studio.deliverables.import.title')"
        :icon="FileInput"
        v-on:close="emit('close')"
    >
        <form class="space-y-4" v-on:submit.prevent="emit('submit')">
            <p class="m-0 text-sm text-secondary">{{ t("suite.studio.deliverables.import.intro") }}</p>
            <AppInput
                v-model="title"
                :label="t('suite.studio.deliverables.title')"
                :placeholder="t('suite.studio.deliverables.import.title_placeholder')"
                :error="errors.title ?? ''"
            />
            <slot />
            <div class="space-y-1.5">
                <span class="text-xs uppercase tracking-wide text-muted">{{ t("suite.studio.deliverables.import.document") }}</span>
                <div class="max-h-96 overflow-y-auto rounded-lg border border-line bg-surface-2 p-2">
                    <AppBlockEditor v-model="blocks" :placeholder="t('suite.studio.deliverables.import.placeholder')" />
                </div>
                <p class="m-0 text-xs text-muted">{{ t("suite.studio.deliverables.import.hint") }}</p>
                <p v-if="errors.blocks" class="m-0 text-xs text-rose-400">{{ t(errors.blocks) }}</p>
            </div>
        </form>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('submit')">
                    <FileInput class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.studio.deliverables.import.submit") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
