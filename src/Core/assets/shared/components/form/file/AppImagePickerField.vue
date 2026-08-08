<script setup>
/**
 * Picks an image, either from the media the project already holds or from the
 * machine in front of you.
 *
 * Both, because they answer different questions. The picker is for a logo or
 * an illustration that is already filed; browsing is for the picture that only
 * exists on a desktop, and having to leave the page, upload it in the document
 * module and come back is a detour nobody wants mid-edit.
 *
 * The upload path goes through GED like everything else — the file becomes a
 * Document, addressable and reusable — so this adds a way in, not a second
 * kind of storage.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Image as ImageIcon, Upload, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppFilePickerButton from "@/shared/components/action/AppFilePickerButton.vue";
import AppImage from "@/shared/components/display/AppImage.vue";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";
import { useImageUpload } from "@/shared/composables/http/backend/useImageUpload.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";

const props = defineProps({
    label: { type: String, default: "" },
    hint: { type: String, default: "" },
    modelValue: { type: Object, default: () => ({ id: null, url: null }) },
    chooseLabel: { type: String, default: "" },
    changeLabel: { type: String, default: "" },
    removeLabel: { type: String, default: "" },
    size: { type: Number, default: 128 },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();
const { can } = usePrivileges();

// The endpoint behind the upload is GED's, and it is guarded by GED's own
// permission rather than the one that opened this form. Hiding the button is
// what keeps an editor who may write posts but not file documents from
// meeting a 403 with no explanation.
const canUpload = computed(() => can("ged.documents.create"));

const { uploading, inputRef, uploadFromEvent } = useImageUpload({
    onSuccess: ({ file }) => emit("update:modelValue", file),
    onError: () => toast.error(t("shared.media.upload_failed")),
});

async function pick() {
    const item = await openDocumentPicker({ imagesOnly: true });
    if (!item) return;
    // Documents serialize their public URL under `fileUrl` (mirror of
    // file_path); the optional `url` fallback covers any legacy serializer
    // shape still in flight.
    const url = item.fileUrl ?? item.url ?? null;
    emit("update:modelValue", { id: item.id, url });
}

function clear() {
    emit("update:modelValue", { id: null, url: null });
}
</script>

<template>
    <div>
        <p v-if="label" class="text-xs font-medium text-secondary uppercase tracking-wide mb-1.5">{{ label }}</p>
        <div v-if="modelValue?.url" class="flex items-start gap-3">
            <button
                type="button"
                class="group relative rounded-lg border border-line overflow-hidden shrink-0"
                :style="{ width: `${size}px`, height: `${size}px` }"
                v-on:click="pick"
            >
                <AppImage :src="modelValue.url" alt="" object-fit="cover" />
                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all">
                    <span class="text-white text-xs font-medium">{{ changeLabel || t('shared.media.change') }}</span>
                </div>
            </button>
            <div class="flex flex-col gap-2">
                <AppButton variant="secondary" size="sm" type="button" v-on:click="pick">
                    <ImageIcon class="w-4 h-4" :stroke-width="2" />
                    {{ changeLabel || t('shared.media.change') }}
                </AppButton>
                <AppFilePickerButton
                    v-if="canUpload"
                    ref="inputRef"
                    accept="image/*"
                    variant="ghost"
                    size="sm"
                    :loading="uploading"
                    v-on:change="uploadFromEvent"
                >
                    <Upload class="w-4 h-4" :stroke-width="2" />
                    {{ uploading ? t('shared.media.uploading') : t('shared.media.upload') }}
                </AppFilePickerButton>
                <AppButton variant="ghost" size="sm" type="button" v-on:click="clear">
                    <X class="w-4 h-4" :stroke-width="2" />
                    {{ removeLabel || t('shared.media.remove') }}
                </AppButton>
            </div>
        </div>
        <div v-else class="flex flex-wrap gap-2">
            <AppButton variant="secondary" size="sm" type="button" v-on:click="pick">
                <ImageIcon class="w-4 h-4" :stroke-width="2" />
                {{ chooseLabel || t('shared.media.choose') }}
            </AppButton>
            <AppFilePickerButton
                v-if="canUpload"
                ref="inputRef"
                accept="image/*"
                variant="ghost"
                size="sm"
                :loading="uploading"
                v-on:change="uploadFromEvent"
            >
                <Upload class="w-4 h-4" :stroke-width="2" />
                {{ uploading ? t('shared.media.uploading') : t('shared.media.upload') }}
            </AppFilePickerButton>
        </div>
        <p v-if="hint" class="text-xs text-muted mt-2">{{ hint }}</p>
    </div>
</template>
