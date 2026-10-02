<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Building2, UserRound } from "lucide-vue-next";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import AppTab from "@/shared/components/nav/AppTab.vue";
import SpaceDriveView from "./SpaceDriveView.vue";

/**
 * Deux dossiers Drive dans un espace : celui du client, et celui de l'agence.
 *
 * **Celui du client** se désigne dans les réglages de son espace, et le client
 * le retrouve dans son propre espace. **Celui de l'agence** est le même pour
 * tous les espaces, choisi une fois dans la configuration du Drive : des
 * modèles, une charte, ce que l'équipe consulte en travaillant pour n'importe
 * quel client. Il n'est jamais montré au client - un fichier oublié dedans
 * serait sinon visible chez tous.
 *
 * Les onglets n'apparaissent que si l'agence a un dossier : sans lui, la vue
 * reste ce qu'elle a toujours été. Même vue des deux côtés, même serrure.
 */
const props = defineProps({
    folderId: { type: String, default: null },
    listPath: { type: String, required: true },
    filePath: { type: String, required: true },
    archivePath: { type: String, default: "" },
    importPath: { type: String, default: "" },
    unlockPath: { type: String, default: "" },
    canConfigure: { type: Boolean, default: false },
    agencyFolderId: { type: String, default: null },
    agencyListPath: { type: String, default: "" },
    agencyFilePath: { type: String, default: "" },
    agencyArchivePath: { type: String, default: "" },
    agencyImportPath: { type: String, default: "" },
});

const { t } = useI18n();

const hasAgency = computed(() => null !== props.agencyFolderId && "" !== props.agencyFolderId && "" !== props.agencyListPath);

const { choice: stored } = usePersistedChoice("studio.space_drive.source", "client", ["client", "agency"]);

const source = computed(() => (hasAgency.value ? stored.value : "client"));

const SOURCES = [
    { key: "client", icon: UserRound, labelKey: "backend.studio.drive.space.source_client" },
    { key: "agency", icon: Building2, labelKey: "backend.studio.drive.space.source_agency" },
];
</script>

<template>
    <div class="space-y-4">
        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
     replié une fois, il le reste (`storage-key`). -->
        <AppGuide :title="t('backend.studio.drive.space.guide.title')" storage-key="space-drive">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`backend.studio.drive.space.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <!-- Le même groupe d'onglets que les sections des réglages d'espace. -->
        <div
            v-if="hasAgency"
            class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg border border-line bg-surface-2 p-1"
            role="group"
        >
            <AppTab
                v-for="entry in SOURCES"
                :key="entry.key"
                size="sm"
                :active="source === entry.key"
                active-class="bg-surface text-primary shadow-sm"
                inactive-class="text-secondary hover:text-primary"
                class="whitespace-nowrap"
                v-on:click="stored = entry.key"
            >
                <component :is="entry.icon" class="h-4 w-4" :stroke-width="2" />
                {{ t(entry.labelKey) }}
            </AppTab>
        </div>

        <!-- Une clé par dossier : passer de l'un à l'autre repart d'une vue
             neuve, sans garder l'arborescence ni le fichier ouvert de l'autre. -->
        <SpaceDriveView
            v-if="'agency' === source"
            key="agency"
            :folder-id="agencyFolderId"
            :list-path="agencyListPath"
            :file-path="agencyFilePath"
            :archive-path="agencyArchivePath"
            :import-path="agencyImportPath"
            :unlock-path="unlockPath"
            :can-configure="false"
            empty-key="backend.studio.drive.space.no_agency_folder"
            intro-key="backend.studio.drive.space.agency_intro"
        />
        <SpaceDriveView
            v-else
            key="client"
            :folder-id="folderId"
            :list-path="listPath"
            :file-path="filePath"
            :archive-path="archivePath"
            :import-path="importPath"
            :unlock-path="unlockPath"
            :can-configure="canConfigure"
        />
    </div>
</template>
