<script setup>
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { FileText, Download } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Choisir un document Craft, et le déposer dans l'espace.
 *
 * **La liste est courte, et c'est le réglage qui le veut.** La connexion ne
 * porte que les documents désignés dans Craft : ce qui n'y est pas n'apparaît
 * pas ici, et la façon de l'ajouter est de retourner dans Craft. L'écran le
 * dit plutôt que de laisser croire à une panne.
 *
 * **Chargée à l'ouverture, pas au montage.** Un appel à Craft coûte une
 * seconde et la plupart des visites de l'écran des notes ne l'ouvrent jamais.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    documentsPath: { type: String, required: true },
    importPath: { type: String, required: true },
});

const emit = defineEmits(["close", "imported"]);

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(false);
const importing = ref(false);
const configured = ref(true);
const documents = ref([]);
const picked = ref(null);

const chosen = computed(
    () => documents.value.find((document) => document.id === picked.value) ?? null,
);

watch(
    () => props.show,
    async (open) => {
        if (!open) return;

        picked.value = null;
        loading.value = true;

        try {
            const data = await request(props.documentsPath, null, {
                method: HttpMethod.Get,
                noGuard: true,
            });

            configured.value = true === data?.configured;
            documents.value = Array.isArray(data?.documents) ? data.documents : [];
        } finally {
            loading.value = false;
        }
    },
);

async function submit() {
    if (importing.value || null === chosen.value) return;

    importing.value = true;

    try {
        const data = await request(props.importPath, {
            documentId: chosen.value.id,
            title: chosen.value.title,
        });

        if (data) {
            toast.success(t("backend.studio.craft.import.imported"));
            emit("imported", data);
            emit("close");
        }
    } finally {
        importing.value = false;
    }
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :title="t('backend.studio.craft.import.title')"
        :icon="FileText"
        v-on:close="emit('close')"
    >
        <div class="relative min-h-32 space-y-3">
            <AppLoader :active="loading" />

            <p class="text-sm text-secondary">{{ t("backend.studio.craft.import.intro") }}</p>

            <template v-if="!loading">
                <div v-if="!configured" class="rounded-lg border border-line bg-surface-2 p-4">
                    <p class="text-sm text-primary">{{ t("backend.studio.craft.import.disabled") }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t("backend.studio.craft.import.disabled_hint") }}</p>
                </div>

                <div v-else-if="!documents.length" class="rounded-lg border border-line bg-surface-2 p-4">
                    <p class="text-sm text-primary">{{ t("backend.studio.craft.import.empty") }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t("backend.studio.craft.import.empty_hint") }}</p>
                </div>

                <!-- Des boutons radio, et non une liste déroulante : ils sont
                     une poignée, et voir les titres côte à côte est le geste
                     qu'on vient faire. -->
                <ul v-else class="divide-y divide-line/60 overflow-hidden rounded-lg border border-line">
                    <li v-for="document in documents" :key="document.id">
                        <label
                            class="flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors"
                            :class="picked === document.id ? 'bg-surface-2' : 'hover:bg-surface-2/60'"
                        >
                            <input
                                v-model="picked"
                                type="radio"
                                name="craft-document"
                                :value="document.id"
                                class="shrink-0 text-accent focus:ring-accent"
                            >
                            <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ document.title }}</span>
                        </label>
                    </li>
                </ul>
            </template>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton class="w-full sm:w-auto" variant="ghost" size="sm" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    class="w-full sm:w-auto"
                    variant="primary"
                    size="sm"
                    :disabled="null === chosen"
                    :loading="importing"
                    v-on:click="submit"
                >
                    <Download class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.craft.import.submit") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
