<script setup>
/**
 * Les documents d'un client : audits, stratégies, tout ce qu'on écrit avec la
 * grille des publications pour le lui livrer par un lien.
 *
 * **Ce sont des publications, pas un second genre de document.** Cet écran
 * les liste et en commence de nouvelles ; on les écrit dans l'éditeur des
 * publications, qui apporte toutes les zones de la grille, les versions, la
 * corbeille et les trois langues. Un document d'espace ne va jamais sur le
 * site : il est partagé par lien seulement, et le client le retrouve aussi
 * dans son espace dès qu'il est publié.
 *
 * Même gabarit que les ressources voisines : l'intro et le bouton en tête, des
 * cartes en liste, les gestes écrits en toutes lettres sur téléphone.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { ExternalLink, Plus } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";

const props = defineProps({
    publications: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
    createPath: { type: String, required: true },
});

const { t } = useI18n();
const { request } = useRequest();
const { formatDateShort } = useDateFormat();

const rows = ref([...props.publications]);
const creating = ref(false);
const saving = ref(false);
const title = ref("");
const errors = ref({});

// La palette de la liste des publications, pour qu'un document se lise de la
// même façon aux deux endroits.
const STATUS_COLORS = {
    draft: "gray",
    pending_review: "amber",
    scheduled: "sky",
    published: "emerald",
    archived: "zinc",
};

function openCreate() {
    title.value = "";
    errors.value = {};
    creating.value = true;
}

async function create() {
    if (saving.value) return;

    saving.value = true;
    try {
        const data = await request(props.createPath, { title: title.value });

        if (!data?.success) {
            errors.value = data?.errors ?? {};

            return;
        }

        rows.value = data.publications ?? rows.value;
        // Straight into the editor: a document is started to be written, and
        // an empty row in a list is one more click away from that.
        window.location.href = data.editPath;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-muted sm:max-w-lg">{{ t("backend.studio.space_publications.intro") }}</p>

            <AppButton
                v-if="canCreate"
                variant="ghost"
                size="sm"
                class="w-full justify-center sm:w-auto"
                v-on:click="openCreate"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.space_publications.add") }}
            </AppButton>
        </div>

        <AppNoData
            v-if="0 === rows.length"
            :message="t('backend.studio.space_publications.empty')"
            :hint="t('backend.studio.space_publications.empty_hint')"
        />

        <ul v-else class="space-y-2">
            <li
                v-for="publication in rows"
                :key="publication.id"
                class="aurora-card flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:gap-4"
            >
                <div class="min-w-0 flex-1">
                    <p class="m-0 break-words text-sm font-medium text-primary">
                        {{ publication.title || t("backend.studio.space_publications.untitled") }}
                    </p>
                    <p class="m-0 mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                        <AppBadge :color="STATUS_COLORS[publication.status] ?? 'gray'">
                            {{ t(`backend.posts.status.${publication.status}`) }}
                        </AppBadge>
                        <span>{{ t("backend.studio.space_publications.updated_on", { date: formatDateShort(publication.updatedAt) }) }}</span>
                    </p>
                </div>

                <AppButton
                    :href="publication.editPath"
                    size="sm"
                    variant="ghost"
                    class="w-full justify-center sm:w-auto"
                >
                    <ExternalLink class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.space_publications.open") }}
                </AppButton>
            </li>
        </ul>

        <AppModal
            :show="creating"
            max-width="md"
            :title="t('backend.studio.space_publications.create_title')"
            :icon="Plus"
            v-on:close="creating = false"
        >
            <form class="space-y-4" v-on:submit.prevent="create">
                <AppInput
                    v-model="title"
                    autofocus
                    :label="t('backend.studio.space_publications.title')"
                    :placeholder="t('backend.studio.space_publications.title_placeholder')"
                    :hint="t('backend.studio.space_publications.title_hint')"
                    :error="errors.title ? t(errors.title) : ''"
                />
            </form>

            <AppModalFooter>
                <AppButton variant="ghost" v-on:click="creating = false">{{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" :loading="saving" v-on:click="create">
                    {{ t("backend.studio.space_publications.create") }}
                </AppButton>
            </AppModalFooter>
        </AppModal>
    </div>
</template>
