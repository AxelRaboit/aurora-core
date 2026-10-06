<script setup>
/**
 * Un livrable au format diaporama : l'éditeur de diapositives des
 * présentations, et ce qu'un livrable porte autour.
 *
 * L'éditeur est celui des présentations, tel quel : mêmes diapositives, même
 * panneau d'apparence, même vue présentateur, même impression. Ce composant
 * lui donne les droits du livrable (son auteur, son rayon), ouvre les liens
 * de lecture du livrable quand on veut partager, et ajoute
 * la fenêtre des réglages (titre, résumé, image, catégorie, client, modèle,
 * rayon) qu'une page règle dans son onglet.
 */
import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Save, Settings2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import DeckEditorApp from "../slides/DeckEditorApp.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";
import DeliverableSettingsTab from "./components/DeliverableSettingsTab.vue";
import { useDeliverableSlidesSettings } from "./composables/useDeliverableSlidesSettings.js";

const props = defineProps({
    /** Le livrable sous la forme d'une présentation : ce que lit l'éditeur. */
    deck: { type: Object, required: true },
    /** Le livrable tel que l'enregistre la route des livrables. */
    deliverable: { type: Object, required: true },
    backPath: { type: String, default: null },
    canEdit: { type: Boolean, default: false },
    canShare: { type: Boolean, default: false },
    canChangeScope: { type: Boolean, default: false },
    locales: { type: Array, default: () => [] },
    updatePath: { type: String, required: true },
    linksPath: { type: String, required: true },
    categories: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    canPickCustomer: { type: Boolean, default: false },
    layouts: { type: Array, default: () => [] },
    commonSlots: { type: Array, default: () => [] },
    listSlots: { type: Array, default: () => [] },
    freeOptions: { type: Object, default: () => ({}) },
    uploadedFonts: { type: Array, default: () => [] },
    fontUploadPath: { type: String, default: "" },
    themes: { type: Array, default: () => [] },
    fontPairs: { type: Array, default: () => [] },
    logoPlacements: { type: Array, default: () => [] },
    looks: { type: Array, default: () => [] },
    gradients: { type: Array, default: () => [] },
    patterns: { type: Array, default: () => [] },
    margins: { type: Array, default: () => [] },
    titleCases: { type: Array, default: () => [] },
    bulletShapes: { type: Array, default: () => [] },
    transitions: { type: Array, default: () => [] },
    appearancePath: { type: String, required: true },
    slideCreatePath: { type: String, required: true },
    slideUpdatePath: { type: String, required: true },
    slideDeletePath: { type: String, required: true },
    slideDuplicatePath: { type: String, required: true },
    slideReorderPath: { type: String, required: true },
    printPath: { type: String, required: true },
    presenterPath: { type: String, required: true },
});

const { t } = useI18n();

/**
 * Le titre et le résumé suivent les réglages : la barre de la page les lit
 * dans le « deck », qui ne se recharge pas.
 */
const deck = reactive({ ...props.deck });

const { form, open, saving, errors, openSettings, save } = useDeliverableSlidesSettings(props, {
    onSaved: (saved) => {
        deck.title = saved.title;
        deck.description = saved.summary || null;
        deck.isTemplate = !!saved.template;
    },
});

const showLinks = ref(false);

/** Ce que l'éditeur reçoit tel quel : tout sauf ce qui n'est qu'au livrable. */
const OWN = ["deck", "deliverable", "updatePath", "linksPath", "locales", "categories", "customers", "canPickCustomer", "canChangeScope"];

const editorProps = computed(() => Object.fromEntries(Object.entries(props).filter(([key]) => !OWN.includes(key))));
</script>

<template>
    <div class="contents">
        <DeckEditorApp
            v-bind="editorProps"
            :deck="deck"
            :with-settings="canEdit"
            v-on:share="showLinks = true"
            v-on:settings="openSettings"
        />

        <DeliverableLinksModal :show="showLinks" :links-path="linksPath" v-on:close="showLinks = false" />

        <AppModal
            :show="open"
            max-width="lg"
            :title="t('suite.studio.deliverables.slides.settings')"
            :icon="Settings2"
            v-on:close="open = false"
        >
            <DeliverableSettingsTab
                v-model:title="form.title"
                v-model:summary="form.summary"
                v-model:locale="form.locale"
                v-model:reading-header="form.readingHeader"
                v-model:scope="form.scope"
                v-model:category-id="form.categoryId"
                v-model:template="form.template"
                v-model:customer-id="form.customerId"
                v-model:thumbnail="form.thumbnail"
                :locales="locales"
                :errors="errors"
                :with-client="false"
                :with-reading-header="false"
                :can-change-scope="canChangeScope"
                :categories="categories"
                :customers="customers"
                :can-pick-customer="canPickCustomer"
            />

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="open = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                        <Save class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
