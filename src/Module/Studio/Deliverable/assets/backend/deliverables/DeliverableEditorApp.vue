<script setup>
/**
 * Un livrable, sur sa propre page.
 *
 * Trois onglets : la grille pour composer le document, son apparence, et ses
 * réglages (titre, langue, en-tête, ce que voit le client). Pas de SEO, pas de
 * statut : un livrable n'est jamais sur le site, et ce qui décide que le
 * client le lit est la case « visible par le client ».
 *
 * Le même éditeur pour un livrable d'espace et un livrable de Studio : sans
 * `space`, il n'y a pas de client à qui ouvrir le document, et l'en-tête dit
 * son rayon, perso ou partagé, à la place.
 *
 * La grille est celle des pages du site, avec ses zones et son aperçu : un
 * livrable se compose comme une page, il ne se publie pas comme une page.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, Link2, Lock, Save, Trash2, Users } from "lucide-vue-next";
import AppBackLink from "@/shared/components/nav/AppBackLink.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useTabState } from "@/shared/composables/useTabState.js";
import PostGridPanel from "../../../../../Editorial/assets/backend/posts/components/PostGridPanel.vue";
import DeliverableAppearanceTab from "./components/DeliverableAppearanceTab.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";
import DeliverableSettingsTab from "./components/DeliverableSettingsTab.vue";
import { useDeliverableEditor } from "./composables/useDeliverableEditor.js";

const props = defineProps({
    deliverable: { type: Object, required: true },
    /** L'espace du client ; nul pour un livrable de Studio. */
    space: { type: Object, default: null },
    /** L'auteur d'un livrable de Studio. */
    ownerName: { type: String, default: null },
    locales: { type: Array, default: () => [] },
    hiddenZoneTypes: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    /** Nuls, ils suivent `canEdit` : c'est la règle d'un espace. */
    canShare: { type: Boolean, default: null },
    canDelete: { type: Boolean, default: null },
    canDuplicate: { type: Boolean, default: null },
    canChangeScope: { type: Boolean, default: false },
    deliverablesPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    previewPath: { type: String, required: true },
    gridPreviewPath: { type: String, required: true },
    bannerPreviewPath: { type: String, required: true },
    linksPath: { type: String, required: true },
    duplicatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

const { t } = useI18n();
const { request } = useRequest();

const mayShare = computed(() => props.canShare ?? props.canEdit);
const mayDelete = computed(() => props.canDelete ?? props.canEdit);
const mayDuplicate = computed(() => props.canDuplicate ?? props.canEdit);

const backLabel = computed(() =>
    props.space
        ? t("backend.studio.deliverables.back", { space: props.space.name })
        : t("backend.studio.deliverables.back_to_list"),
);
const { form, saving, errors, dirty, save, markClean } = useDeliverableEditor(props);

/**
 * Le retour rouvre le rayon où le livrable se trouve maintenant : l'auteur
 * peut l'avoir fait passer de l'un à l'autre depuis les réglages.
 */
const backHref = computed(() => {
    if (props.space || !form.value.scope) return props.deliverablesPath;

    const url = new URL(props.deliverablesPath, window.location.origin);
    url.searchParams.set("scope", form.value.scope);

    return url.pathname + url.search;
});

// Les clés vont dans l'adresse : un lien vers l'apparence d'un livrable
// s'envoie tel quel.
const TABS = ["content", "appearance", "settings"];
const { select: selectTab, isActive: isTabActive } = useTabState(TABS, { hash: true });

/** Un onglet qui porte une erreur le dit, même fermé. */
const tabsWithErrors = computed(() => {
    const keys = Object.keys(errors.value);

    return {
        content: keys.some((key) => key.startsWith("grid")),
        appearance: keys.some((key) => key.startsWith("appearance")),
        settings: keys.some((key) => ["title", "locale", "summary"].includes(key)),
    };
});

// ── En-tête ─────────────────────────────────────────────────────────────────

const showLinks = ref(false);
const pendingDelete = ref(false);
const deleting = ref(false);
const duplicating = ref(false);

/** Ouvrir l'aperçu enregistre d'abord : la page montre ce qui est en base. */
async function openPreview() {
    if (dirty.value && !(await save())) return;

    window.open(props.previewPath, "_blank", "noopener");
}

async function duplicate() {
    if (duplicating.value) return;
    if (dirty.value && !(await save())) return;

    duplicating.value = true;
    try {
        const data = await request(props.duplicatePath, {});
        if (data?.success) {
            toast.success(t("backend.studio.deliverables.duplicated"));
            window.location.href = data.editPath;
        }
    } finally {
        duplicating.value = false;
    }
}

async function doDelete() {
    if (deleting.value) return;

    deleting.value = true;
    try {
        const data = await request(props.deletePath, {});
        if (data?.success) {
            toast.success(t("backend.studio.deliverables.deleted"));
            markClean();
            window.location.href = props.deliverablesPath;
        }
    } finally {
        deleting.value = false;
    }
}

const headerActions = computed(() => {
    const actions = [
        {
            key: "preview",
            icon: ExternalLink,
            title: t("backend.studio.deliverables.preview"),
            description: t("backend.studio.deliverables.preview_hint"),
            onSelect: openPreview,
        },
    ];

    if (mayShare.value) {
        actions.push({
            key: "links",
            icon: Link2,
            title: t("backend.studio.deliverables.links.title"),
            description: t("backend.studio.deliverables.links_hint"),
            onSelect: () => (showLinks.value = true),
        });
    }

    if (mayDuplicate.value) {
        actions.push({
            key: "duplicate",
            icon: Copy,
            title: t("backend.studio.deliverables.duplicate"),
            description: t("backend.studio.deliverables.duplicate_hint"),
            disabled: duplicating.value,
            onSelect: duplicate,
        });
    }

    if (mayDelete.value) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.studio.deliverables.delete_hint"),
            onSelect: () => (pendingDelete.value = true),
        });
    }

    return actions;
});
</script>

<template>
    <div class="aurora-stack">
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <AppBackLink :href="backHref" :label="backLabel" />
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <!-- Ce que voit le client, quel que soit l'onglet : on doit
                     savoir qu'on modifie un document qu'il lit déjà. Sans
                     espace, le rayon : perso ou partagé avec l'équipe. -->
                <AppBadge v-if="!space" :color="'shared' === form.scope ? 'sky' : 'gray'">
                    <component :is="'shared' === form.scope ? Users : Lock" class="me-1 inline h-3 w-3 align-[-1px]" :stroke-width="2" />
                    {{ t(`backend.studio.deliverables.scope.${form.scope}`) }}
                </AppBadge>
                <AppBadge v-else :color="form.visibleToClient ? 'emerald' : 'gray'">
                    {{ t(form.visibleToClient
                        ? "backend.studio.deliverables.visible_badge"
                        : "backend.studio.deliverables.hidden_badge") }}
                </AppBadge>
                <AppPageActions :actions="headerActions" icon-only-on-phone />
                <AppButton
                    v-if="canEdit"
                    variant="primary"
                    size="md"
                    :loading="saving"
                    :title="t('shared.common.save')"
                    v-on:click="save"
                >
                    <Save class="h-4 w-4" :stroke-width="2" />
                    <span class="sr-only sm:not-sr-only">{{ t("shared.common.save") }}</span>
                    <span v-if="dirty" class="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-current" :title="t('backend.studio.deliverables.unsaved')" />
                </AppButton>
            </div>
        </div>

        <div class="min-w-0">
            <h1 class="m-0 truncate text-lg font-semibold text-primary">{{ form.title }}</h1>
            <p v-if="space" class="m-0 mt-0.5 text-sm text-secondary">
                {{ t("backend.studio.deliverables.for_customer", { name: space.customerName }) }}
            </p>
            <p v-else-if="ownerName" class="m-0 mt-0.5 text-sm text-secondary">
                {{ t("backend.studio.deliverables.scope.by", { name: ownerName }) }}
            </p>
        </div>


        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('backend.studio.deliverables.editor_guide.title')" storage-key="deliverable-editor">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <!-- Sans espace, les étapes qui parlent du client disent son
                     destinataire et sa visibilité à la place. -->
                <li v-for="step in 5" :key="step">{{ t(`backend.studio.deliverables.editor_guide.step_${step}${!space && [3, 4].includes(step) ? "_studio" : ""}`) }}</li>
            </ol>
        </AppGuide>

        <div class="flex max-w-full gap-1 overflow-x-auto border-b border-line scrollbar-thin">
            <AppTab
                v-for="tab in TABS"
                :key="tab"
                class="shrink-0"
                variant="underline"
                :active="isTabActive(tab)"
                v-on:click="selectTab(tab)"
            >
                {{ t(`backend.studio.deliverables.tabs.${tab}`) }}
                <span v-if="tabsWithErrors[tab]" class="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-rose-500" />
            </AppTab>
        </div>

        <!-- v-show et pas v-if : la grille tient des éditeurs de texte qui
             gardent leur saisie, et changer d'onglet ne doit rien leur faire
             perdre. -->
        <div v-show="isTabActive('content')" class="aurora-card p-3 sm:p-5">
            <PostGridPanel
                :layout="form.gridLayout"
                :content="form.gridContent"
                :locale="form.locale"
                :preview-path="gridPreviewPath"
                :banner-preview-path="bannerPreviewPath"
                :toggleable="false"
                :hidden-types="hiddenZoneTypes"
            />
        </div>

        <DeliverableAppearanceTab
            v-show="isTabActive('appearance')"
            v-model:appearance="form.appearance"
            :title="form.title"
            :summary="form.summary"
        />

        <DeliverableSettingsTab
            v-show="isTabActive('settings')"
            v-model:title="form.title"
            v-model:summary="form.summary"
            v-model:locale="form.locale"
            v-model:reading-header="form.readingHeader"
            v-model:visible-to-client="form.visibleToClient"
            v-model:scope="form.scope"
            :locales="locales"
            :errors="errors"
            :customer-name="space?.customerName ?? ''"
            :with-client="!!space"
            :can-change-scope="canChangeScope"
        />

        <DeliverableLinksModal :show="showLinks" :links-path="linksPath" v-on:close="showLinks = false" />

        <DeliverableDeleteModal
            :show="pendingDelete"
            :title="form.title"
            :deleting="deleting"
            v-on:cancel="pendingDelete = false"
            v-on:confirm="doDelete"
        />
    </div>
</template>
