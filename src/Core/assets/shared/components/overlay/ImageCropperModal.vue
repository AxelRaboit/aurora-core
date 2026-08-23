<script setup>
import { nextTick, ref, watch } from "vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useI18n } from "vue-i18n";
import { Save, X } from "lucide-vue-next";
import Cropper from "cropperjs";
import "cropperjs/dist/cropper.css";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";

/**
 * Generic interactive image cropper. Loads `src` into cropperjs, POSTs the
 * selected rectangle to `cropPath` (with `__id__` resolved from `item.id`),
 * and emits the updated entity read from `data[entityKey]` of the JSON
 * response. Shared by the Media library and the GED documents module.
 */
const { t } = useI18n();
const { loading: saving, request } = useRequest();

const props = defineProps({
    item: { type: Object, default: null },
    src: { type: String, default: "" },
    alt: { type: String, default: "" },
    name: { type: String, default: "" },
    cropPath: { type: String, required: true },
    entityKey: { type: String, default: "media" },
});

const emit = defineEmits(["close", "cropped"]);

const SNAP_THRESHOLD = 12;
const imageEl = ref(null);
let cropper = null;

watch(
    () => props.item,
    async (item) => {
        if (!item) {
            cropper?.destroy();
            cropper = null;
            return;
        }
        await nextTick();
        if (!imageEl.value) return;
        cropper?.destroy();
        cropper = new Cropper(imageEl.value, {
            viewMode: 1,
            autoCropArea: 0.9,
            movable: true,
            zoomable: true,
            background: true,
            cropmove: snapToEdges,
        });
    },
);

function snapToEdges() {
    if (!cropper) return;
    const d = cropper.getData(true);
    const img = cropper.getImageData();
    let { x, y, width, height } = d;
    let changed = false;
    if (x < SNAP_THRESHOLD) { x = 0; changed = true; }
    if (y < SNAP_THRESHOLD) { y = 0; changed = true; }
    if (x + width > img.naturalWidth - SNAP_THRESHOLD) { width = img.naturalWidth - x; changed = true; }
    if (y + height > img.naturalHeight - SNAP_THRESHOLD) { height = img.naturalHeight - y; changed = true; }
    if (changed) cropper.setData({ x, y, width, height });
}

function close() {
    cropper?.destroy();
    cropper = null;
    emit("close");
}

async function save() {
    if (!cropper || !props.item) return;
    const d = cropper.getData(true);
    const url = buildPath(props.cropPath, { id: props.item.id });
    const data = await request(url, { x: d.x, y: d.y, width: d.width, height: d.height });
    if (!data || !data.success) return;
    emit("cropped", data[props.entityKey]);
    toast.success(t("shared.image_cropper.cropped"));
    close();
}
</script>

<template>
    <AppModal :show="!!item" max-width="5xl" :closeable="false" v-on:close="close">
        <h3 class="text-sm font-semibold text-primary mb-3">
            {{ t("shared.image_cropper.title") }}<span v-if="name"> - {{ name }}</span>
        </h3>
        <div style="height: 65vh; width: 100%; overflow: hidden;">
            <img
                v-if="item"
                ref="imageEl"
                :src="src"
                :alt="alt"
                style="display: block; max-width: 100%;"
            >
        </div>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="close"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                    <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.image_cropper.apply") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
