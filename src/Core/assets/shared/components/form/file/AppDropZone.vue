<script setup>
import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import { Upload } from "lucide-vue-next";
import AppFileInput from "@/shared/components/form/file/AppFileInput.vue";

const { t } = useI18n();

const props = defineProps({
    accept: { type: String, default: "" },
    multiple: { type: Boolean, default: false },
    uploading: { type: Boolean, default: false },
    hint: { type: String, default: null },
    label: { type: String, default: null },
    dropLabel: { type: String, default: null },
    uploadingLabel: { type: String, default: null },
});

const emit = defineEmits(["change"]);

const dragOver = ref(false);

function onDragOver(event) {
    if (!event.dataTransfer.types.includes("Files")) return;
    event.preventDefault();
    dragOver.value = true;
}

function onDragLeave(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
        dragOver.value = false;
    }
}

function onDrop(event) {
    if (!event.dataTransfer.types.includes("Files")) return;
    event.preventDefault();
    dragOver.value = false;
    const files = Array.from(event.dataTransfer.files ?? []);
    if (!files.length) return;
    emit("change", props.multiple ? files : files[0]);
}

const displayLabel = computed(() => {
    if (dragOver.value) return props.dropLabel ?? t("shared.drop_zone.drop");
    if (props.uploading) return props.uploadingLabel ?? t("shared.drop_zone.uploading");
    return props.label ?? t("shared.drop_zone.cta");
});
</script>

<template>
    <AppFileInput v-slot="{ trigger }" :accept="accept" :multiple="multiple" v-on:change="$emit('change', $event)">
        <div
            class="border-2 border-dashed rounded-xl p-8 flex flex-col items-center justify-center gap-3 transition-colors cursor-pointer"
            :class="dragOver ? 'border-accent-500 bg-accent-500/10' : 'border-line hover:border-accent-500/60 hover:bg-surface-2/50'"
            v-on:click="!uploading && trigger()"
            v-on:dragover="onDragOver"
            v-on:dragleave="onDragLeave"
            v-on:drop="onDrop"
        >
            <Upload
                class="w-8 h-8 text-secondary transition-colors"
                :class="{ 'text-accent-400': dragOver }"
                :stroke-width="1.5"
            />
            <div class="text-center">
                <p class="text-sm font-medium text-primary">{{ displayLabel }}</p>
                <p v-if="hint" class="text-xs text-muted mt-0.5">{{ hint }}</p>
            </div>
        </div>
    </AppFileInput>
</template>
