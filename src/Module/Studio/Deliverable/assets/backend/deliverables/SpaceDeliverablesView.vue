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
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { AlertTriangle, Copy, Eye, EyeOff, ExternalLink, FolderOutput, Link2, Pencil, Plus, Trash2, X } from "lucide-vue-next";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { queueFlash } from "@/shared/utils/flash.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import DeliverableCards from "./components/DeliverableCards.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";
import { useDeliverableRequest } from "./composables/useDeliverableRequest.js";

const props = defineProps({
    deliverables: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    /** Donner une adresse de lecture : le droit de partager l'espace, qui n'est pas celui de le modifier. */
    canShare: { type: Boolean, default: false },
    /** Faux pour une archive : elle ne reçoit plus de livrable, créé ni dupliqué. */
    canAdd: { type: Boolean, default: false },
    /** La route qui rend les lignes à jour, pour une liste devenue périmée. */
    listPath: { type: String, default: "" },
    createPath: { type: String, required: true },
    visibilityPathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    /** Les liens de lecture d'un livrable ; vide, l'action n'est pas proposée. */
    linksPathTemplate: { type: String, default: "" },
    /** Garder une copie dans Studio ; vide sans le droit d'y créer. */
    copyToStudioPathTemplate: { type: String, default: "" },
});

const { t } = useI18n();

const rows = ref([...props.deliverables]);

// La page du parent peut rafraîchir ses lignes : sans cela, celles d'ici
// restaient celles du premier chargement.
watch(
    () => props.deliverables,
    (next) => (rows.value = [...next]),
);

const { send } = useDeliverableRequest({
    listPath: props.listPath,
    onList: (data) => (rows.value = data.deliverables ?? []),
});

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
        const data = await send(props.createPath, { title: title.value });

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

/** Ce qui retient l'ouverture au client, en attendant que l'auteur dise qu'il le sait. */
const pendingShow = ref(null);

async function toggleVisibility(deliverable, confirm = false) {
    busyId.value = deliverable.id;
    try {
        const visible = !deliverable.visibleToClient;
        const data = await send(
            buildPath(props.visibilityPathTemplate, { id: deliverable.id }),
            { visible, ...(confirm ? { confirm: true } : {}) },
            { own: ["confirmation_needed"] },
        );

        // Un modèle pas fini, ou des images pas publiées : le serveur refuse
        // d'ouvrir sans que l'auteur le sache, et dit ce qui reste.
        if ("confirmation_needed" === data?.error) {
            pendingShow.value = {
                deliverable,
                placeholders: data.placeholders ?? 0,
                pictures: data.withheldPictures ?? [],
            };

            return;
        }

        if (data?.success) {
            pendingShow.value = null;
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
        const data = await send(buildPath(props.duplicatePathTemplate, { id: deliverable.id }), {});
        if (data?.success) {
            queueFlash("success", t("backend.studio.deliverables.duplicated"));
            window.location.href = data.editPath;
        }
    } finally {
        busyId.value = null;
    }
}

async function copyToStudio(deliverable) {
    busyId.value = deliverable.id;
    try {
        const data = await send(buildPath(props.copyToStudioPathTemplate, { id: deliverable.id }), {});
        if (data?.success) {
            queueFlash("success", t("backend.studio.deliverables.copy_to_studio.done"));
            window.location.href = data.editPath;
        }
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
        const data = await send(buildPath(props.deletePathTemplate, { id: pendingDelete.value.id }), {});
        if (data?.success) {
            rows.value = data.deliverables;
            toast.success(t("backend.studio.deliverables.deleted"));
        }

        // Réussi ou refusé (le livrable n'existe plus), la fenêtre se ferme :
        // la liste a été redessinée d'un côté ou de l'autre.
        pendingDelete.value = null;
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
    // destinataire ne demande plus d'ouvrir le document d'abord. Seulement
    // avec le droit de partager l'espace : la liste porte les adresses mêmes.
    if (props.linksPathTemplate && props.canShare) {
        actions.push({
            key: "links",
            icon: Link2,
            title: t("backend.studio.deliverables.links.title"),
            description: t("backend.studio.deliverables.links_hint"),
            onSelect: () => (linksFor.value = deliverable),
        });
    }

    // Garder ce livrable comme modèle : il suffit de le lire ici et de
    // pouvoir créer dans Studio.
    if (props.copyToStudioPathTemplate) {
        actions.push({
            key: "copy-to-studio",
            icon: FolderOutput,
            title: t("backend.studio.deliverables.copy_to_studio.action"),
            description: t("backend.studio.deliverables.copy_to_studio.action_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => copyToStudio(deliverable),
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
        ...(props.canAdd
            ? [{
                key: "duplicate",
                icon: Copy,
                title: t("backend.studio.deliverables.duplicate"),
                description: t("backend.studio.deliverables.duplicate_hint"),
                disabled: busyId.value === deliverable.id,
                onSelect: () => duplicate(deliverable),
            }]
            : []),
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("backend.studio.deliverables.trash_action"),
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
                v-if="canEdit && canAdd"
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
                <li v-for="step in 6" :key="step">{{ t(`backend.studio.deliverables.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <p v-if="canEdit && !canAdd" class="m-0 rounded-lg border border-line bg-surface-2 p-3 text-sm text-secondary" role="status">
            {{ t("backend.studio.deliverables.archived_hint") }}
        </p>

        <AppNoData
            v-if="0 === rows.length"
            :message="t('backend.studio.deliverables.empty')"
            :hint="canEdit && canAdd ? t('backend.studio.deliverables.empty_hint') : ''"
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

        <!-- Ouvrir au client un document qui n'est pas fini : on le dit, on
             ne le refuse pas, l'auteur sait ce qu'il fait. -->
        <AppModal
            :show="!!pendingShow"
            max-width="md"
            :title="t('backend.studio.deliverables.show_confirm.title')"
            :icon="AlertTriangle"
            v-on:close="pendingShow = null"
        >
            <div v-if="pendingShow" class="space-y-3 text-sm text-primary">
                <p class="m-0">{{ t("backend.studio.deliverables.show_confirm.intro", { title: pendingShow.deliverable.title }) }}</p>
                <p v-if="pendingShow.placeholders" class="m-0 rounded-md bg-amber-500/10 px-3 py-2 text-amber-700 dark:text-amber-400">
                    {{ t("backend.studio.deliverables.show_confirm.placeholders", { count: pendingShow.placeholders }) }}
                </p>
                <p v-if="pendingShow.pictures.length" class="m-0 rounded-md bg-amber-500/10 px-3 py-2 text-amber-700 dark:text-amber-400">
                    {{ t("backend.studio.deliverables.show_confirm.pictures", { count: pendingShow.pictures.length }) }}
                    <span class="block truncate text-xs text-secondary">{{ pendingShow.pictures.map((picture) => picture.name).join(", ") }}</span>
                </p>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingShow = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.studio.deliverables.show_confirm.keep_hidden") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="busyId === pendingShow?.deliverable.id" v-on:click="toggleVisibility(pendingShow.deliverable, true)">
                        <Eye class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.studio.deliverables.show_confirm.confirm") }}
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
