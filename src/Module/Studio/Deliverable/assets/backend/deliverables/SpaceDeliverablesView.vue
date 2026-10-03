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
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, Eye, EyeOff, ExternalLink, Link2, Pencil, Plus, Trash2, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import DeliverableCards from "./components/DeliverableCards.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";

const props = defineProps({
    deliverables: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    createPath: { type: String, required: true },
    visibilityPathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    /** Les liens de lecture d'un livrable ; vide, l'action n'est pas proposée. */
    linksPathTemplate: { type: String, default: "" },
});

const { t } = useI18n();
const { request } = useRequest();

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
                ? "backend.studio.deliverables.hidden_toast"
                : "backend.studio.deliverables.shown_toast"));
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
            toast.success(t("backend.studio.deliverables.deleted"));
            pendingDelete.value = null;
        }
    } finally {
        deleting.value = false;
    }
}

// ── Liens de lecture ────────────────────────────────────────────────────────

/** Le livrable dont la fenêtre des liens est ouverte. */
const linksFor = ref(null);

function actionsFor(deliverable) {
    const actions = [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("backend.studio.deliverables.open"),
            description: t("backend.studio.deliverables.open_hint"),
            // Une navigation est un lien, comme le veut la feuille d'actions :
            // changer l'adresse depuis `onSelect` ne partait pas au vrai clic.
            href: deliverable.editPath,
        },
        {
            key: "preview",
            icon: ExternalLink,
            title: t("backend.studio.deliverables.preview"),
            description: t("backend.studio.deliverables.preview_hint"),
            onSelect: () => window.open(deliverable.previewPath, "_blank", "noopener"),
        },
    ];

    // Les liens de lecture, comme dans l'éditeur : créer une adresse pour un
    // destinataire ne demande plus d'ouvrir le document d'abord.
    if (props.linksPathTemplate) {
        actions.push({
            key: "links",
            icon: Link2,
            title: t("backend.studio.deliverables.links.title"),
            description: t("backend.studio.deliverables.links_hint"),
            onSelect: () => (linksFor.value = deliverable),
        });
    }

    if (!props.canEdit) return actions;

    actions.push(
        {
            key: "visibility",
            icon: deliverable.visibleToClient ? EyeOff : Eye,
            title: t(deliverable.visibleToClient
                ? "backend.studio.deliverables.hide"
                : "backend.studio.deliverables.show"),
            description: t(deliverable.visibleToClient
                ? "backend.studio.deliverables.hide_hint"
                : "backend.studio.deliverables.show_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => toggleVisibility(deliverable),
        },
        {
            key: "duplicate",
            icon: Copy,
            title: t("backend.studio.deliverables.duplicate"),
            description: t("backend.studio.deliverables.duplicate_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => duplicate(deliverable),
        },
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.studio.deliverables.delete_hint"),
            onSelect: () => (pendingDelete.value = deliverable),
        },
    );

    return actions;
}
</script>

<template>
    <div class="aurora-stack">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="m-0 text-xs text-muted sm:max-w-lg">{{ t("backend.studio.deliverables.intro") }}</p>

            <AppButton
                v-if="canEdit"
                variant="ghost"
                size="sm"
                class="w-full justify-center sm:w-auto"
                v-on:click="openCreate"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.deliverables.add") }}
            </AppButton>
        </div>

        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
     replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('backend.studio.deliverables.guide.title')" storage-key="space-deliverables">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`backend.studio.deliverables.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <AppNoData
            v-if="0 === rows.length"
            :message="t('backend.studio.deliverables.empty')"
            :hint="t('backend.studio.deliverables.empty_hint')"
        />

        <DeliverableCards v-else :deliverables="rows" :actions-for="actionsFor">
            <template #meta="{ deliverable }">
                <AppBadge :color="deliverable.visibleToClient ? 'emerald' : 'gray'">
                    {{ t(deliverable.visibleToClient
                        ? "backend.studio.deliverables.visible_badge"
                        : "backend.studio.deliverables.hidden_badge") }}
                </AppBadge>
            </template>
        </DeliverableCards>

        <DeliverableLinksModal
            :show="null !== linksFor"
            :links-path="linksFor ? buildPath(linksPathTemplate, { id: linksFor.id }) : ''"
            v-on:close="linksFor = null"
        />

        <AppModal
            :show="creating"
            max-width="md"
            :title="t('backend.studio.deliverables.create_title')"
            :icon="Plus"
            v-on:close="creating = false"
        >
            <form class="space-y-4" v-on:submit.prevent="create">
                <AppInput
                    v-model="title"
                    autofocus
                    :label="t('backend.studio.deliverables.title')"
                    :placeholder="t('backend.studio.deliverables.title_placeholder')"
                    :hint="t('backend.studio.deliverables.title_hint')"
                    :error="errors.title ?? ''"
                />
            </form>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="creating = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="create">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.studio.deliverables.create") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <DeliverableDeleteModal
            :show="!!pendingDelete"
            :title="pendingDelete?.title ?? ''"
            :deleting="deleting"
            v-on:cancel="pendingDelete = null"
            v-on:confirm="doDelete"
        />
    </div>
</template>
