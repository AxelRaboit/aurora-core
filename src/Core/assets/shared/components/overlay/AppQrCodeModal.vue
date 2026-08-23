<script setup>
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Download, X } from "lucide-vue-next";
import QRCode from "qrcode";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";

/**
 * Generic QR-code modal: renders a download-ready PNG for an item's public URL.
 *
 * `item` must expose at least one of:
 *   - `permalink`  optional override - if present, used verbatim (e.g. signed)
 *   - `url`        relative path the QR encodes (origin gets prepended)
 *   - `fileUrl`    same as `url`, for items that name it that way (GED Document)
 *
 * The label shown in the title is resolved from the first defined field of
 * `name | originalName | title | fileName`, so the same component works
 * for Media, GED Document and any future list without a wrapper layer.
 */
const { t } = useI18n();

const props = defineProps({
    item: { type: Object, default: null },
});

function label(item) {
    return item.name ?? item.originalName ?? item.title ?? item.fileName ?? "";
}

function itemPath(item) {
    return item.url ?? item.fileUrl ?? "";
}

const emit = defineEmits(["close"]);

const qrDataUrl = ref("");

function permalink(item) {
    return item.permalink ?? window.location.origin + itemPath(item);
}

watch(
    () => props.item,
    async (item) => {
        if (!item) {
            qrDataUrl.value = "";
            return;
        }
        qrDataUrl.value = await QRCode.toDataURL(permalink(item), {
            width: 256,
            margin: 2,
        });
    },
    { immediate: true },
);
</script>

<template>
    <AppModal :show="!!item" max-width="sm" :closeable="false" v-on:close="emit('close')">
        <h3 class="text-sm font-medium text-primary mb-4">{{ t("shared.common.qr_code") }} - {{ item ? label(item) : '' }}</h3>
        <div class="flex flex-col items-center gap-4">
            <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR Code" class="w-48 h-48 rounded-xl border border-line/60">
            <p class="text-xs text-muted text-center break-all">{{ item ? permalink(item) : '' }}</p>
            <a v-if="qrDataUrl" :href="qrDataUrl" download="qrcode.png">
                <AppButton size="sm" variant="ghost"><Download class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.download_qr") }}</AppButton>
            </a>
        </div>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.close") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
