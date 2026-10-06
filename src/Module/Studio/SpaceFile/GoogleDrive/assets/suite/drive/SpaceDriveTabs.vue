<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Building2, UserRound } from "lucide-vue-next";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import AppTab from "@/shared/components/nav/AppTab.vue";
import SpaceDriveView from "./SpaceDriveView.vue";

/**
 * Two Drive folders in a space: the client's, and the agency's.
 *
 * **The client's** is designated in their space's settings, and the client
 * finds it in their own space. **The agency's** is the same for every space,
 * chosen once in the Drive configuration: templates, a brand guide, what the
 * team browses while working for any client. It is never shown to the client
 * - otherwise a file forgotten in it would be visible to all of them.
 *
 * The tabs only appear if the agency has a folder: without it, the view stays
 * what it has always been. Same view on both sides, same lock.
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
    { key: "client", icon: UserRound, labelKey: "suite.studio.drive.space.source_client" },
    { key: "agency", icon: Building2, labelKey: "suite.studio.drive.space.source_agency" },
];
</script>

<template>
    <div class="space-y-4">
        <!-- The screen's how-to, next to what it explains;
     collapsed or expanded, the choice applies to every guide box. -->
        <AppGuide :title="t('suite.studio.drive.space.guide.title')" storage-key="space-drive">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.studio.drive.space.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <!-- The same tab group as the space settings sections. -->
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

        <!-- One key per folder: switching from one to the other starts from a
             fresh view, without keeping the other's tree or open file. -->
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
            empty-key="suite.studio.drive.space.no_agency_folder"
            intro-key="suite.studio.drive.space.agency_intro"
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
