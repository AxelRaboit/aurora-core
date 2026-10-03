<script setup>
/**
 * Un livrable, sur sa propre page.
 *
 * Trois onglets : la grille pour composer le document, son apparence, et ses
 * réglages (titre, langue, en-tête, ce que voit le client). Pas de SEO, pas de
 * statut : un livrable n'est jamais sur le site, et ce qui décide que le
 * client le lit est la case « visible par le client ».
 *
 * La grille est celle des pages du site, avec ses zones et son aperçu : un
 * livrable se compose comme une page, il ne se publie pas comme une page.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, Link2, Save, Trash2, X } from "lucide-vue-next";
import AppBackLink from "@/shared/components/nav/AppBackLink.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useTabState } from "@/shared/composables/useTabState.js";
import PostGridPanel from "../../../../../Editorial/assets/backend/posts/components/PostGridPanel.vue";
import DeliverableAppearanceTab from "./components/DeliverableAppearanceTab.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";
import DeliverableSettingsTab from "./components/DeliverableSettingsTab.vue";
import { useDeliverableEditor } from "./composables/useDeliverableEditor.js";

const props = defineProps({
    deliverable: { type: Object, required: true },
    space: { type: Object, required: true },
    locales: { type: Array, default: () => [] },
    hiddenZoneTypes: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
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
const { form, saving, errors, dirty, save, markClean } = useDeliverableEditor(props);

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
        {
            key: "links",
            icon: Link2,
            title: t("backend.studio.deliverables.links.title"),
            description: t("backend.studio.deliverables.links_hint"),
            onSelect: () => (showLinks.value = true),
        },
    ];

    if (props.canEdit) {
        actions.push(
            {
                key: "duplicate",
                icon: Copy,
                title: t("backend.studio.deliverables.duplicate"),
                description: t("backend.studio.deliverables.duplicate_hint"),
                disabled: duplicating.value,
                onSelect: duplicate,
            },
            {
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t("backend.studio.deliverables.delete_hint"),
                onSelect: () => (pendingDelete.value = true),
            },
        );
    }

    return actions;
});
</script>

<template>
    <div class="aurora-stack">
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <AppBackLink :href="deliverablesPath" :label="t('backend.studio.deliverables.back', { space: space.name })" />
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <!-- Ce que voit le client, quel que soit l'onglet : on doit
                     savoir qu'on modifie un document qu'il lit déjà. -->
                <AppBadge :color="form.visibleToClient ? 'emerald' : 'gray'">
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
            <p class="m-0 mt-0.5 text-sm text-secondary">
                {{ t("backend.studio.deliverables.for_customer", { name: space.customerName }) }}
            </p>
        </div>


        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('backend.studio.deliverables.editor_guide.title')" storage-key="deliverable-editor">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`backend.studio.deliverables.editor_guide.step_${step}`) }}</li>
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
            :locales="locales"
            :errors="errors"
            :customer-name="space.customerName"
        />

        <DeliverableLinksModal :show="showLinks" :links-path="linksPath" v-on:close="showLinks = false" />

        <AppModal
            :show="pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = false"
        >
            <p class="m-0 text-sm text-primary">{{ t("backend.studio.deliverables.delete_confirm", { title: form.title }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = false">
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
