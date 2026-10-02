<script setup>
/**
 * Les livrables d'un client : audits, stratégies, bilans, tout ce qu'on écrit
 * pour le lui remettre.
 *
 * Un module à lui, et plus des publications du site : la grille des pages
 * pour composer, l'apparence du document, et une case pour l'ouvrir au client.
 * Ni brouillon ni publication : ce qui est fermé est en cours, ce qui est
 * ouvert, le client le lit dans son espace.
 *
 * Même gabarit que les ressources voisines : l'intro et le bouton en tête, des
 * cartes en liste, les gestes écrits en toutes lettres sur téléphone.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, Eye, EyeOff, ExternalLink, Pencil, Plus, Trash2, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCardActions from "@/shared/components/action/AppCardActions.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";

const props = defineProps({
    deliverables: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    createPath: { type: String, required: true },
    visibilityPathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
});

const { t } = useI18n();
const { request } = useRequest();
const { formatDateShort } = useDateFormat();

const rows = ref([...props.deliverables]);

// ── Création ────────────────────────────────────────────────────────────────

const creating = ref(false);
const saving = ref(false);
const title = ref("");
const errors = ref({});

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

        // Droit dans l'éditeur : un livrable se commence pour s'écrire.
        window.location.href = data.editPath;
    } finally {
        saving.value = false;
    }
}

// ── Gestes d'une ligne ──────────────────────────────────────────────────────

const busyId = ref(null);

async function toggleVisibility(deliverable) {
    busyId.value = deliverable.id;
    try {
        const data = await request(buildPath(props.visibilityPathTemplate, { id: deliverable.id }), {
            visible: !deliverable.visibleToClient,
        });
        if (data?.success) {
            rows.value = data.deliverables;
            toast.success(t(deliverable.visibleToClient
                ? "backend.studio.space_deliverables.hidden_toast"
                : "backend.studio.space_deliverables.shown_toast"));
        }
    } finally {
        busyId.value = null;
    }
}

async function duplicate(deliverable) {
    busyId.value = deliverable.id;
    try {
        const data = await request(buildPath(props.duplicatePathTemplate, { id: deliverable.id }), {});
        if (data?.success) window.location.href = data.editPath;
    } finally {
        busyId.value = null;
    }
}

const pendingDelete = ref(null);
const deleting = ref(false);

async function doDelete() {
    if (deleting.value || !pendingDelete.value) return;

    deleting.value = true;
    try {
        const data = await request(buildPath(props.deletePathTemplate, { id: pendingDelete.value.id }), {});
        if (data?.success) {
            rows.value = data.deliverables;
            toast.success(t("backend.studio.space_deliverables.deleted"));
            pendingDelete.value = null;
        }
    } finally {
        deleting.value = false;
    }
}

function actionsFor(deliverable) {
    const actions = [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("backend.studio.space_deliverables.open"),
            description: t("backend.studio.space_deliverables.open_hint"),
            // Une navigation est un lien, comme le veut la feuille d'actions :
            // changer l'adresse depuis `onSelect` ne partait pas au vrai clic.
            href: deliverable.editPath,
        },
        {
            key: "preview",
            icon: ExternalLink,
            title: t("backend.studio.space_deliverables.preview"),
            description: t("backend.studio.space_deliverables.preview_hint"),
            onSelect: () => window.open(deliverable.previewPath, "_blank", "noopener"),
        },
    ];

    if (!props.canEdit) return actions;

    actions.push(
        {
            key: "visibility",
            icon: deliverable.visibleToClient ? EyeOff : Eye,
            title: t(deliverable.visibleToClient
                ? "backend.studio.space_deliverables.hide"
                : "backend.studio.space_deliverables.show"),
            description: t(deliverable.visibleToClient
                ? "backend.studio.space_deliverables.hide_hint"
                : "backend.studio.space_deliverables.show_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => toggleVisibility(deliverable),
        },
        {
            key: "duplicate",
            icon: Copy,
            title: t("backend.studio.space_deliverables.duplicate"),
            description: t("backend.studio.space_deliverables.duplicate_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => duplicate(deliverable),
        },
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.studio.space_deliverables.delete_hint"),
            onSelect: () => (pendingDelete.value = deliverable),
        },
    );

    return actions;
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="m-0 text-xs text-muted sm:max-w-lg">{{ t("backend.studio.space_deliverables.intro") }}</p>

            <AppButton
                v-if="canEdit"
                variant="ghost"
                size="sm"
                class="w-full justify-center sm:w-auto"
                v-on:click="openCreate"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.space_deliverables.add") }}
            </AppButton>
        </div>

        <AppNoData
            v-if="0 === rows.length"
            :message="t('backend.studio.space_deliverables.empty')"
            :hint="t('backend.studio.space_deliverables.empty_hint')"
        />

        <ul v-else class="m-0 list-none space-y-2 p-0">
            <li
                v-for="deliverable in rows"
                :key="deliverable.id"
                class="aurora-card space-y-2.5 p-3 sm:flex sm:items-center sm:gap-4 sm:space-y-0"
            >
                <div class="min-w-0 flex-1">
                    <a class="block break-words text-sm font-medium text-primary no-underline hover:text-accent" :href="deliverable.editPath">
                        {{ deliverable.title }}
                    </a>
                    <p v-if="deliverable.summary" class="m-0 mt-0.5 text-xs text-secondary line-clamp-2">{{ deliverable.summary }}</p>
                    <p class="m-0 mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                        <AppBadge :color="deliverable.visibleToClient ? 'emerald' : 'gray'">
                            {{ t(deliverable.visibleToClient
                                ? "backend.studio.space_deliverables.visible_badge"
                                : "backend.studio.space_deliverables.hidden_badge") }}
                        </AppBadge>
                        <span>{{ t("backend.studio.space_deliverables.updated_on", { date: formatDateShort(deliverable.updatedAt) }) }}</span>
                    </p>
                </div>

                <!-- Le menu sur ordinateur ; sur téléphone les gestes sont
                     écrits en toutes lettres sous la carte. -->
                <AppRowActions class="hidden shrink-0 sm:block" :actions="actionsFor(deliverable)" :label="deliverable.title" />
                <div class="border-t border-line/40 pt-2 sm:hidden">
                    <AppCardActions :actions="actionsFor(deliverable)" />
                </div>
            </li>
        </ul>

        <AppModal
            :show="creating"
            max-width="md"
            :title="t('backend.studio.space_deliverables.create_title')"
            :icon="Plus"
            v-on:close="creating = false"
        >
            <form class="space-y-4" v-on:submit.prevent="create">
                <AppInput
                    v-model="title"
                    autofocus
                    :label="t('backend.studio.space_deliverables.title')"
                    :placeholder="t('backend.studio.space_deliverables.title_placeholder')"
                    :hint="t('backend.studio.space_deliverables.title_hint')"
                    :error="errors.title ?? ''"
                />
            </form>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="creating = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="create">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.studio.space_deliverables.create") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="m-0 text-sm text-primary">
                {{ t("backend.studio.space_deliverables.delete_confirm", { title: pendingDelete?.title ?? "" }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="deleting" v-on:click="doDelete">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
