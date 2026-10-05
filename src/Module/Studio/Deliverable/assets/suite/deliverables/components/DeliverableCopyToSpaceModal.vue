<script setup>
/**
 * Copier un livrable de Studio dans l'espace d'un client : le modèle d'audit
 * ou de stratégie qu'on remplit pour lui.
 *
 * On choisit l'espace, on garde ou on change le titre, et l'on arrive dans
 * l'éditeur de la copie, dans son espace. L'original reste dans Studio.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { FolderInput, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** Le titre de l'original, repris par défaut. */
    sourceTitle: { type: String, default: "" },
    copyPath: { type: String, default: "" },
    /** Les espaces où la personne peut écrire : `{ id, name, customer }`. */
    targets: { type: Array, default: () => [] },
});

const emit = defineEmits(["close"]);

const { t } = useI18n();
const { request } = useRequest();

const spaceId = ref("");
const title = ref("");
const errors = ref({});
const saving = ref(false);

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        spaceId.value = 1 === props.targets.length ? String(props.targets[0].id) : "";
        title.value = props.sourceTitle;
        errors.value = {};
    },
    { immediate: true },
);

const options = computed(() =>
    props.targets.map((space) => ({
        value: space.id,
        // Le client seulement quand le nom de l'espace ne le dit pas déjà.
        label: space.customer && !space.name.includes(space.customer) ? `${space.name} · ${space.customer}` : space.name,
    })),
);

async function copy() {
    if (saving.value) return;
    if (!spaceId.value) {
        errors.value = { spaceId: "suite.studio.deliverables.copy_to_space.space_required" };

        return;
    }

    saving.value = true;
    try {
        const data = await request(props.copyPath, { spaceId: Number(spaceId.value), title: title.value });
        if (!data?.success) {
            errors.value = data?.errors ?? {};

            return;
        }

        window.location.href = data.editPath;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="md"
        :title="t('suite.studio.deliverables.copy_to_space.title')"
        :icon="FolderInput"
        v-on:close="emit('close')"
    >
        <form class="space-y-4" v-on:submit.prevent="copy">
            <p class="text-sm text-secondary">{{ t("suite.studio.deliverables.copy_to_space.intro") }}</p>
            <AppSelect
                v-model="spaceId"
                :label="t('suite.studio.deliverables.copy_to_space.space')"
                :placeholder="t('suite.studio.deliverables.copy_to_space.space_placeholder')"
                :options="options"
                :error="errors.spaceId ? t(errors.spaceId) : ''"
                required
            />
            <AppInput
                v-model="title"
                :label="t('suite.studio.deliverables.title')"
                :placeholder="t('suite.studio.deliverables.title_placeholder')"
                :hint="t('suite.studio.deliverables.copy_to_space.title_hint')"
                :error="errors.title ?? ''"
            />
        </form>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="copy">
                    <FolderInput class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.studio.deliverables.copy_to_space.submit") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
