<script setup>
/**
 * The GED's folders, in the side menu - navigation *and* management.
 *
 * This was a `w-72` aside inside the documents page. It is here because the
 * question it answers - which folder am I working in - follows the reader onto
 * the tags page, the categories page and a document's own page, none of which
 * could reach a folder at all before. A surface that only exists on one page is
 * a surface five other pages have to do without.
 *
 * **Rows are real links.** `/backend/ged/documents?folderId=42` is an address
 * the documents page already reads on arrival, so middle-click and "open in a
 * new tab" behave. On a plain click the panel asks the page first, through
 * `modulePanelBridge`: if the documents listing is mounted it takes the click
 * and filters in place, exactly as the aside used to; anywhere else nobody
 * answers and the link navigates. One panel, right on both.
 *
 * **The writes live here too** - create, rename, delete, re-file by dragging.
 * That is the part that makes this a working surface rather than a shortcut,
 * and it is why the aside could be deleted rather than merely duplicated.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
    Folder,
    Home,
    Layers,
    Pencil,
    Plus,
    Save,
    Star,
    Trash2,
    X,
} from "lucide-vue-next";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import FolderTreeRow from "./components/FolderTreeRow.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { askPage } from "@/shared/nav/modulePanelBridge.js";
import { useModulePanelData } from "@/shared/nav/useModulePanelData.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useSidemenuSectionTheme } from "@/backend/sidemenu/composables/useSidemenuSectionTheme.js";
import { useDocumentSidebarTree } from "./composables/useDocumentSidebarTree.js";
import { useFolderPanelActions } from "./composables/useFolderPanelActions.js";

const DOCUMENTS_PATH = "/backend/ged/documents";
const FOLDERS_ENDPOINT = "/backend/ged/documents/folders";

const { t } = useI18n();
const { can } = usePrivileges();
const { itemClasses, iconClasses } = useSidemenuSectionTheme();

const {
    data: folders,
    loading,
    failed,
    reload,
} = useModulePanelData(FOLDERS_ENDPOINT, { key: "folders" });

/**
 * Which folder the page is showing, kept in step with it.
 *
 * Read from the address on arrival, then updated whenever the page answers one
 * of our asks - the documents page rewrites its own URL with
 * `history.replaceState`, which fires nothing we could listen to. Since the
 * click that moved it came from here, we already know the answer.
 */
const currentFolderId = ref(
    Number(new URL(window.location.href).searchParams.get("folderId")) || null,
);
const scope = ref(
    new URL(window.location.href).searchParams.get("rootOnly") === "1"
        ? "root"
        : "all",
);

const {
    flatFolders,
    allFlatFolders,
    collapsedFolderIds,
    toggleCollapse,
    favouriteFolderIds,
    toggleFavourite,
    favouriteFolders,
} = useDocumentSidebarTree(folders, currentFolderId);

const {
    folderModal,
    folderForm,
    deletingFolder,
    folderParentSelectOptions,
    openCreateFolder,
    openEditFolder,
    submitFolder,
    confirmDeleteFolder,
    dropTarget,
    draggingFolderId,
    onFolderDragStart,
    onFolderDragOver,
    onDragLeave,
    onDragEnd,
    onFolderDrop,
} = useFolderPanelActions({
    folders,
    allFlatFolders,
    onChanged: (change = {}) => {
        // A deleted folder cannot stay selected. The page is told where to go
        // instead; if it is not listening, the reader is on another GED page
        // and there is nothing to move.
        if (
            change.deletedFolderId &&
            currentFolderId.value === change.deletedFolderId
        ) {
            select({ folderId: change.parentId, scope: "all" });
        }
        askPage("ged:reload", { folders: folders.value });
        reload();
    },
});

const canManage = computed(() => can("ged.folders.manage"));
const isEmpty = computed(() => 0 === folders.value.length);

function hrefFor({ folderId = null, scope: next = "all" }) {
    if (folderId) return `${DOCUMENTS_PATH}?folderId=${folderId}`;

    return "root" === next ? `${DOCUMENTS_PATH}?rootOnly=1` : DOCUMENTS_PATH;
}

/**
 * Move the reader to a folder, in place when the page can do it.
 *
 * The panel updates its own highlight either way: on a handled ask the page
 * never reloads, so nothing else would.
 */
function select(target) {
    const handled = askPage("ged:select", target);
    if (handled) {
        currentFolderId.value = target.folderId ?? null;
        scope.value = target.scope ?? "all";
    }

    return handled;
}

function onRowClick(event, target) {
    if (select(target)) event.preventDefault();
}

const isCurrent = (folderId) => currentFolderId.value === folderId;

/** Which of the three bands the drag is over, for this row. */
const zoneOn = (folderId) =>
    dropTarget.value?.id === folderId ? dropTarget.value.zone : null;
const rowClasses = (active) => itemClasses("ged", { isActive: active });
</script>

<template>
    <AppModulePanel
        :title="t('backend.ged.documents.folder_tree')"
        :loading="loading"
        :failed="failed"
    >
        <template #action>
            <AppIconButton
                v-if="canManage"
                size="sm"
                variant="ghost"
                :title="t('backend.ged.documents.new_folder')"
                v-on:click="openCreateFolder(currentFolderId)"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
        </template>

        <!-- The shortcut list the aside carried above its tree. A reader who
             starred nine folders out of ninety did so to stop scrolling for
             them, and the tree below is exactly the scrolling they were
             avoiding. -->
        <div v-if="favouriteFolders.length" class="mb-1">
            <h3
                class="flex items-center gap-1.5 px-3 pb-0.5 text-xs font-semibold uppercase tracking-wide text-muted"
            >
                <Star
                    class="h-3 w-3 text-amber-400"
                    :stroke-width="2"
                    fill="currentColor"
                />
                {{ t("backend.ged.documents.favourites") }}
            </h3>
            <div
                v-for="favourite in favouriteFolders"
                :key="`fav-${favourite.id}`"
                v-on:click="
                    onRowClick($event, { folderId: favourite.id, scope: 'all' })
                "
            >
                <AppNavLink
                    :href="hrefFor({ folderId: favourite.id })"
                    :active="isCurrent(favourite.id)"
                    :link-classes-override="rowClasses(isCurrent(favourite.id))"
                >
                    <Folder
                        class="h-4 w-4 shrink-0"
                        :class="
                            iconClasses('ged', {
                                isActive: isCurrent(favourite.id),
                            })
                        "
                        :stroke-width="2"
                    />
                    <span class="min-w-0 flex-1 truncate">{{
                        favourite.name
                    }}</span>
                </AppNavLink>
            </div>
            <div class="my-1 border-t border-line/40" />
        </div>

        <!-- Not folders, but the two scopes the listing can be in. They were in
             the aside and they belong with the tree: "which folder" and "all of
             them" are the same question asked two ways. -->
        <div v-on:click="onRowClick($event, { folderId: null, scope: 'all' })">
            <AppNavLink
                :href="hrefFor({ scope: 'all' })"
                :link-classes-override="
                    rowClasses(!currentFolderId && scope === 'all')
                "
            >
                <Layers
                    class="h-4 w-4 shrink-0"
                    :class="
                        iconClasses('ged', {
                            isActive: !currentFolderId && scope === 'all',
                        })
                    "
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{
                    t("backend.ged.documents.all_documents")
                }}</span>
            </AppNavLink>
        </div>
        <div
            :class="
                zoneOn(0) ? 'rounded-md ring-1 ring-lime-500' : ''
            "
            v-on:dragover="onFolderDragOver($event, { id: 0, parentId: null })"
            v-on:dragend="onDragEnd"
            v-on:dragleave="onDragLeave"
            v-on:drop="onFolderDrop($event, null)"
            v-on:click="onRowClick($event, { folderId: null, scope: 'root' })"
        >
            <AppNavLink
                :href="hrefFor({ scope: 'root' })"
                :link-classes-override="
                    rowClasses(!currentFolderId && scope === 'root')
                "
            >
                <Home
                    class="h-4 w-4 shrink-0"
                    :class="
                        iconClasses('ged', {
                            isActive: !currentFolderId && scope === 'root',
                        })
                    "
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{
                    t("backend.ged.documents.root_folder")
                }}</span>
            </AppNavLink>
        </div>

        <p v-if="isEmpty" class="px-3 py-1 text-xs text-muted">
            {{ t("backend.ged.documents.folder_tree_empty") }}
        </p>

        <FolderTreeRow
            v-for="folder in flatFolders"
            :key="folder.id"
            :folder="folder"
            :href="hrefFor({ folderId: folder.id })"
            :current="isCurrent(folder.id)"
            :zone="zoneOn(folder.id)"
            :dragging="draggingFolderId === folder.id"
            :collapsed="collapsedFolderIds.has(folder.id)"
            :favourite="favouriteFolderIds.has(folder.id)"
            :can-manage="canManage"
            v-on:select="onRowClick($event, { folderId: folder.id, scope: 'all' })"
            v-on:toggle-collapse="toggleCollapse(folder.id)"
            v-on:toggle-favourite="toggleFavourite(folder.id)"
            v-on:edit="openEditFolder(folder)"
            v-on:delete="deletingFolder = folder"
            v-on:dragstart="onFolderDragStart($event, folder)"
            v-on:dragover="onFolderDragOver($event, folder)"
            v-on:dragleave="onDragLeave"
            v-on:dragend="onDragEnd"
            v-on:drop="onFolderDrop($event, folder)"
        />

        <template #overlay>
            <AppModal
                :show="folderModal.open"
                max-width="md"
                :title="
                    folderModal.editing
                        ? t('backend.ged.documents.edit_folder')
                        : t('backend.ged.documents.new_folder')
                "
                :icon="folderModal.editing ? Pencil : Folder"
                :closeable="false"
                v-on:close="folderModal.open = false"
            >
                <form class="space-y-4" v-on:submit.prevent="submitFolder">
                    <AppInput
                        v-model="folderForm.name"
                        :label="t('backend.ged.documents.folder_name')"
                        :placeholder="
                            t('backend.ged.documents.folder_name_placeholder')
                        "
                        :error="folderModal.errors.name ?? ''"
                        required
                    />
                    <AppMultiselect
                        v-model="folderForm.parentId"
                        :options="folderParentSelectOptions"
                        :label="t('backend.ged.documents.parent_folder')"
                        :placeholder="t('backend.ged.documents.root_folder')"
                        :allow-empty="true"
                        track-by="id"
                        option-label="displayLabel"
                    />
                </form>
                <template #footer>
                    <AppModalFooter>
                        <AppButton
                            variant="ghost"
                            size="md"
                            v-on:click="folderModal.open = false"
                        >
                            <X class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("shared.common.cancel") }}
                        </AppButton>
                        <AppButton
                            variant="primary"
                            size="md"
                            :loading="folderModal.saving"
                            v-on:click="submitFolder"
                        >
                            <Save class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("shared.common.save") }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>

            <AppModal
                :show="!!deletingFolder"
                max-width="sm"
                :closeable="false"
                :title="t('shared.common.delete')"
                :icon="Trash2"
                v-on:close="deletingFolder = null"
            >
                <p class="text-sm text-primary">
                    {{
                        t("backend.ged.documents.delete_folder_confirm", {
                            name: deletingFolder?.name,
                        })
                    }}
                </p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton
                            variant="ghost"
                            size="md"
                            v-on:click="deletingFolder = null"
                        >
                            <X class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("shared.common.cancel") }}
                        </AppButton>
                        <AppButton
                            variant="danger"
                            size="md"
                            v-on:click="confirmDeleteFolder"
                        >
                            <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("shared.common.delete") }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>
        </template>
    </AppModulePanel>
</template>
