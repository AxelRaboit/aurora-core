<script setup>
/**
 * Naming zones to keep as a section of one's own: « mon bloc SWOT ».
 *
 * The zones arrive already copied (`payload`, from `snapshotZones`); this
 * window only asks for a name and sends both. The server runs them through
 * the grid normaliser and keeps them for this person alone.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { BookmarkPlus, X } from "lucide-vue-next";
import { toast } from "vue-sonner";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** `{zones, content}` to keep, or null. */
    payload: { type: Object, default: null },
    /** A name to start from: the section's heading, when there is one. */
    suggestedName: { type: String, default: "" },
});

const emit = defineEmits(["close"]);

const { t } = useI18n();
const { request } = useRequest();

const name = ref("");
const error = ref("");
const saving = ref(false);

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        name.value = props.suggestedName;
        error.value = "";
    },
);

async function save() {
    if (saving.value || !props.payload) return;
    if (!name.value.trim()) {
        error.value = t("suite.posts.grid.sections.name_required");

        return;
    }

    saving.value = true;
    try {
        const data = await request("/suite/grid-sections/create", { name: name.value, ...props.payload });
        if (!data?.success) {
            error.value = data?.errors?.name ? t(data.errors.name) : t("suite.posts.grid.sections.save_failed");

            return;
        }

        toast.success(t("suite.posts.grid.sections.saved"));
        emit("close");
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="md"
        :title="t('suite.posts.grid.sections.save')"
        :icon="BookmarkPlus"
        v-on:close="emit('close')"
    >
        <form class="space-y-3" v-on:submit.prevent="save">
            <p class="text-sm text-secondary">
                {{ t("suite.posts.grid.sections.save_intro", { count: payload?.zones?.length ?? 0 }) }}
            </p>
            <AppInput
                v-model="name"
                :label="t('suite.posts.grid.sections.name')"
                :placeholder="t('suite.posts.grid.sections.name_placeholder')"
                :error="error"
                required
            />
        </form>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                    <BookmarkPlus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.posts.grid.sections.save_submit") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
