<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useListPage } from "@/shared/composables/list/useListPage.js";
import { useQrCode } from "@/shared/composables/overlay/useQrCode.js";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import { useDocumentRowActions } from "./composables/useDocumentRowActions.js";
import { useDocumentsForm, DOCUMENT_STATUS_BADGE } from "./composables/useDocumentsForm.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppQrCodeModal from "@/shared/components/overlay/AppQrCodeModal.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppFileInput from "@/shared/components/form/file/AppFileInput.vue";
import AppNavListItem from "@/shared/components/nav/AppNavListItem.vue";
import AppTextLinkButton from "@/shared/components/action/AppTextLinkButton.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { NONE, SEARCH_FIELDS, useDocumentFilters } from "./composables/useDocumentFilters.js";
import { useDocumentDetail } from "./composables/useDocumentDetail.js";
import { useDocumentsDisplay, DOCUMENT_SORT_FIELDS } from "./composables/useDocumentsDisplay.js";
import { useDocumentNavigation } from "./composables/useDocumentNavigation.js";
import { useDocumentSidebarTree } from "./composables/useDocumentSidebarTree.js";
import { useDocumentDragSource } from "./composables/useDocumentDragSource.js";
import { onPanelRequest } from "@/shared/nav/modulePanelBridge.js";
import { useDocumentBulkActions } from "./composables/useDocumentBulkActions.js";
import { useDocumentRelocation } from "./composables/useDocumentRelocation.js";
import { useDocumentRelocateAll } from "./composables/useDocumentRelocateAll.js";
import { useDocumentCrop } from "./composables/useDocumentCrop.js";
import { useMultiSelection } from "@/shared/composables/list/useMultiSelection.js";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { Plus, Eye, Pencil, Trash2, Save, FileText, Paperclip, Upload, X, Folder, Download, QrCode, LayoutGrid, List, SortAsc, SortDesc, CheckSquare, Square, Copy, Crop, ExternalLink, Home, Layers, Star, ChevronRight, ChevronDown, Move, CloudUpload, HardDriveDownload, RotateCcw, Palette, SlidersHorizontal, Bookmark } from "lucide-vue-next";
import ImageCropperModal from "@/shared/components/overlay/ImageCropperModal.vue";
import AppImagePreview from "@/shared/components/display/AppImagePreview.vue";
import AppImage from "@/shared/components/display/AppImage.vue";
import AppThumbnail from "@/shared/components/display/AppThumbnail.vue";
import AppFilePreview from "@/shared/components/display/AppFilePreview.vue";
import AppOverlayIconButton from "@/shared/components/action/AppOverlayIconButton.vue";
import AppSelectionCheck from "@/shared/components/feedback/AppSelectionCheck.vue";
import DocumentTagChip from "@ged/suite/documents/components/DocumentTagChip.vue";
import DocumentStorageChip from "@ged/suite/documents/components/DocumentStorageChip.vue";
import DocumentStateBadges from "@ged/suite/documents/components/DocumentStateBadges.vue";
import DocumentFamilyFields from "@ged/suite/documents/components/DocumentFamilyFields.vue";
import DocumentFamilyChips from "@ged/suite/documents/components/DocumentFamilyChips.vue";
import DocumentFamilyUsage from "@ged/suite/documents/components/DocumentFamilyUsage.vue";
import DocumentRecolorModal from "@ged/suite/documents/components/DocumentRecolorModal.vue";
import DocumentFamilyStrip from "@ged/suite/documents/components/DocumentFamilyStrip.vue";
import { familyMembers } from "@ged/suite/documents/utils/familyLabels.js";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";

const { t } = useI18n();
const { can } = usePrivileges();
const { formatDate } = useDateFormat();
const { formatSize } = useFileSize();
const props = defineProps({
    documents: { type: Object, default: () => ({}) },
    /** Opens the page on its trash, for a link that points at it. */
    categories: { type: Array, default: () => [] },
    tags: { type: Array, default: () => [] },
    folders: { type: Array, default: () => [] },
    search: { type: String, default: "" },
    showPath: { type: String, default: "" },
    versionsPath: { type: String, default: "" },
    usagePath: { type: String, default: "" },
    alternatesPath: { type: String, default: "" },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    bulkDeletePath: { type: String, default: "" },
    listPath: { type: String, required: true },
    uploadPath: { type: String, required: true },
    cropPath: { type: String, default: "" },
    recolorPath: { type: String, default: "" },
    themeColor: { type: String, default: "" },
    movePath: { type: String, default: "" },
    /** The alternate labels already used in the library, offered as suggestions. */
    alternateLabels: { type: Array, default: () => [] },
    bulkMovePath: { type: String, default: "" },
    bulkCategoryPath: { type: String, default: "" },
    storagePath: { type: String, default: "" },
    bulkStoragePath: { type: String, default: "" },
    relocateAllPath: { type: String, default: "" },
    storageRelocationAvailable: { type: Boolean, default: false },
    folderCreatePath: { type: String, default: "" },
    folderEditPath: { type: String, default: "" },
    folderDeletePath: { type: String, default: "" },
    folderMovePath: { type: String, default: "" },
    restorePath: { type: String, default: "" },
    forceDeletePath: { type: String, default: "" },
    bulkRestorePath: { type: String, default: "" },
    emptyTrashPath: { type: String, default: "" },
});

const categoryOptions = props.categories.map((category) => ({ value: category.id, label: category.name }));
const tagOptions = props.tags.map((tag) => ({ value: tag.id, label: tag.name }));
// The filters also find what was never filed: « Sans catégorie », « Sans
// étiquette ». Kept out of the options above, which the edit forms share.
const filterCategoryOptions = [{ value: NONE, label: t("suite.ged.documents.no_category") }, ...categoryOptions];
const filterTagOptions = [{ value: NONE, label: t("suite.ged.documents.filter_no_tag") }, ...tagOptions];

const { viewingDocument, viewingDocumentVersions, viewingDocumentUsage, viewDocument, closeDetail } = useDocumentDetail(props.versionsPath, props.usagePath);

const { qrItem: qrDocument, openQr, closeQr } = useQrCode();
const { copy } = useClipboard();

function permalinkFor(gedDocument) {
    return gedDocument.permalink ?? (gedDocument.fileUrl ? window.location.origin + gedDocument.fileUrl : "");
}

const {
    filterCategoryId, filterTagId, filterStatus, filterMimeGroup, filterOriginalsOnly,
    searchIn, filterAddedFrom, filterAddedTo, filterOrientation, filterWeight, moreFiltersCount,
    hasActiveFilter, extraParameters: filterExtraParameters, applyFilter, resetFilters,
    // The arrow defers the read: `reset` comes from useListPage, which needs
    // these refs to exist before it is called.
    // eslint-disable-next-line no-use-before-define
} = useDocumentFilters(() => reset());

// Opened by hand, and open from the start when one of its filters is set:
// a filter hidden behind a closed panel is a listing nobody understands.
const showMoreFilters = ref(false);
const searchInOptions = SEARCH_FIELDS.map((field) => ({ value: field, label: t(`suite.ged.documents.search_in_${field}`) }));
const orientationOptions = ["landscape", "portrait", "square"].map((value) => ({
    value,
    label: t(`suite.ged.documents.orientation_${value}`),
}));
const weightOptions = ["light", "medium", "heavy"].map((value) => ({ value, label: t(`suite.ged.documents.weight_${value}`) }));

const mimeGroupOptions = [
    { value: "image", label: t("suite.ged.documents.type_image") },
    { value: "video", label: t("suite.ged.documents.type_video") },
    { value: "audio", label: t("suite.ged.documents.type_audio") },
    { value: "pdf", label: t("suite.ged.documents.type_pdf") },
    { value: "other", label: t("suite.ged.documents.type_other") },
];

const { selectedIds, isSelecting, toggle: toggleSelect, clear: clearSelection } = useMultiSelection();

// ── Sidebar nav state (current folder, all-view, history) ────────────────────
// Defined before useListPage so its extraParams() can read the navigation refs.
const {
    folders, currentFolderId, allDocumentsView, rootOnly,
    navigateTo, navigateToRoot, navigateToAll,
    onListResponse, extraParams: navExtraParameters,
    // eslint-disable-next-line no-use-before-define -- same cycle as above.
} = useDocumentNavigation(props, () => reset(), clearSelection);

// Sidebar (folderId / rootOnly) drives the folder filter - strip the legacy
// chip's folderId from the existing useDocumentFilters payload to avoid
// double-writing the same query param.
// The sort is asked of the server; its state is created with the display
// below, once `items` exists, and plugged in here at request time.
let sortExtraParameters = () => ({});

function combinedExtraParams() {
    const base = filterExtraParameters();
    delete base.folderId;
    return { ...base, ...navExtraParameters(), ...sortExtraParameters() };
}

const { items, loading, page, totalPages, search: searchInput, onSearch, goToPage, reload: reset } = useListPage(
    props.listPath,
    {
        initialSearch: props.search,
        initialData: props.documents,
        extraParams: combinedExtraParams,
        onData: onListResponse,
    },
);

/** A search already typed is run again in the new fields; otherwise nothing to redo. */
function onSearchInChange() {
    if (searchInput.value) applyFilter();
}

const {
    statusOptions,
    showCreate, newDocument, uploadingCreate, createErrors, createLoading, openCreate, onLocalFileCreate, submitCreate,
    showEdit, editingDocument, editForm, uploadingEdit, editErrors, editLoading, openEdit, onLocalFileEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
} = useDocumentsForm(props.createPath, props.updatePath, props.deletePath, reset, props.uploadPath);

const {
    viewMode,
    setViewMode,
    storedViewMode,
    container,
    isNarrow,
    sortBy,
    sortDirection,
    setSort,
    sortParameters,
    displayedItems,
} = useDocumentsDisplay(items);
sortExtraParameters = sortParameters;
// A new order is a new listing, from its first page.
watch([sortBy, sortDirection], () => reset());

// ── Families ─────────────────────────────────────────────────────────────────
// Which member a family card shows, chosen with its chips. Kept by original
// id for the page on screen; a reload shows the originals again.
const previewedMember = ref({});

function familyOf(gedDocument) {
    return familyMembers(gedDocument);
}

function thumbnailShown(gedDocument) {
    const id = previewedMember.value[gedDocument.id];
    if (!id || id === gedDocument.id) return gedDocument.thumbnailUrl;

    return gedDocument.alternates?.find((member) => member.id === id)?.thumbnailUrl ?? gedDocument.thumbnailUrl;
}

// "With its alternates" is the default answer when an original is deleted or
// moved: a variant left behind is one nobody finds again.
const deleteWithAlternates = ref(true);

function submitDelete() {
    doDelete(pendingDelete.value?.alternateCount > 0 && deleteWithAlternates.value ? { withAlternates: true } : null);
}

// Adding a variant from the family strip: the create form, already declared
// as a variant of this original, with the label left to fill in.
function startVariant(original) {
    viewingDocument.value = null;
    openCreate();
    newDocument.value.originalId = original.id;
    newDocument.value.originalTitle = original.title;
    newDocument.value.folderId = original.folderId ?? null;
    newDocument.value.kept = true;
}

const { currentFolder, breadcrumbs, folderEditOptions } = useDocumentSidebarTree(folders, currentFolderId);

const { onDocumentDragStart } = useDocumentDragSource();

/**
 * The folder tree left this page for the side menu, and talks back through
 * `modulePanelBridge`.
 *
 * Two messages, which is the whole contract. A row was clicked: filter in
 * place, exactly as the aside's own handler used to, and the bridge cancels
 * the link so the browser stays put. Folders changed: adopt the new list and
 * reload the listing, because a rename shows in the breadcrumb and a move
 * changes what belongs in the folder on screen.
 *
 * Dropped on unmount: `window` outlives this component, and a listing mounted
 * later would otherwise be answered by a corpse.
 */
const stopListening = [];

onMounted(() => {
    stopListening.push(
        onPanelRequest("ged:select", ({ folderId = null, scope = "all" }) => {
            if (folderId) return navigateTo(folderId);

            return "root" === scope ? navigateToRoot() : navigateToAll();
        }),
        onPanelRequest("ged:reload", (detail) => {
            if (Array.isArray(detail?.folders)) folders.value = detail.folders;
            reset();
        }),
    );
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});

// Two sets on this screen: what a document offers, and what a folder in the
// tree does. Both were written twice - once for the cards, once for the table -
// so the two copies could already disagree.
const { relocate } = useDocumentRelocation(props, items);

const documentActions = useDocumentRowActions({
    can,
    viewDocument,
    openQr,
    openEdit,
    confirmDelete,
    relocate,
    relocationAvailable: props.storageRelocationAvailable,
});

const {
    doBulkDelete, bulkMoveTargetId, openBulkMove, bulkMove, bulkMoveWithAlternates, bulkRelocate, bulkRelocating,
    bulkCategoryTargetId, openBulkCategory, bulkCategoryWithAlternates, bulkCategorize,
} = useDocumentBulkActions(
    props, items, selectedIds, isSelecting, clearSelection, currentFolderId, reset,
);

/**
 * What can be done to a handful of ticked rows.
 *
 * The bar used to carry five buttons after the count, and on a phone it took
 * two full rows of its own above a list the reader was still trying to read.
 * The count and the way out stay - they are what the bar is *for*, saying how
 * many and letting go of them - and the verbs move behind one button.
 */
const bulkActions = computed(() => {
    const actions = [
        {
            key: "move",
            icon: Move,
            title: t("suite.ged.documents.move"),
            onSelect: () => {
                bulkMoveTargetId.value = null;
                openBulkMove.value = true;
            },
        },
        {
            key: "category",
            icon: Bookmark,
            title: t("suite.ged.documents.change_category"),
            onSelect: () => {
                bulkCategoryTargetId.value = null;
                openBulkCategory.value = true;
            },
        },
    ];

    // Absent until a second backend has actually been configured, exactly as on
    // a single row.
    if (props.storageRelocationAvailable && can("ged.documents.relocate")) {
        actions.push({
            key: "relocate-remote",
            icon: CloudUpload,
            title: t("suite.ged.documents.row_actions.relocate_to_remote"),
            loading: bulkRelocating.value,
            onSelect: () => bulkRelocate("r2"),
        });
        actions.push({
            key: "relocate-local",
            icon: HardDriveDownload,
            title: t("suite.ged.documents.row_actions.relocate_to_local"),
            loading: bulkRelocating.value,
            onSelect: () => bulkRelocate("local"),
        });
    }

    if (can("ged.documents.delete")) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            onSelect: doBulkDelete,
        });
    }

    return actions;
});

const { cropTarget, onCropped } = useDocumentCrop(viewingDocument, reset);

// The document a colour alternate is being made from. The new copy is opened
// once made, so it can be checked straight away, and the listing reloaded.
const recolorTarget = ref(null);
const canRecolor = (gedDocument) => !!props.recolorPath && can("ged.documents.create") && /^image\/(png|jpe?g|webp)$/.test(gedDocument?.fileMime ?? "");

function onRecolored(created) {
    recolorTarget.value = null;
    reset();
    if (created) viewDocument(created);
}

// Whether the selection holds an original with alternates: only then is
// "with its alternates" a question worth asking.
const selectedHaveAlternates = computed(() => items.value.some((gedDocument) => selectedIds.value.has(gedDocument.id) && gedDocument.alternateCount > 0));

const {
    pendingDisk: relocateAllDisk,
    confirmLabel: relocateAllLabel,
    running: relocatingAll,
    askRelocateAll,
    cancelRelocateAll,
    confirmRelocateAll,
} = useDocumentRelocateAll(props, reset);

// The create verb is marked `primary`: AppPageActions sets it beside the
// sheet as the page's main button, and the sheet keeps whatever else there is.
const pageActions = computed(() => {
    const actions = [];

    if (can("ged.documents.create")) {
        actions.push({
            key: "create",
            primary: true,
            color: "accent",
            icon: Plus,
            title: t("suite.ged.documents.add"),
            onSelect: openCreate,
        });
    }

    // Up here rather than in the selection bar, because it is not an answer to
    // a selection: the bar only exists once rows are ticked, and "move all of
    // them" is precisely the instruction somebody gives instead of ticking.
    // Absent until a second backend is configured, exactly as on a single row.
    if (props.storageRelocationAvailable && can("ged.documents.relocate")) {
        actions.push({
            key: "relocate-all-remote",
            icon: CloudUpload,
            title: t("suite.ged.documents.relocation.all_to_remote"),
            loading: relocatingAll.value,
            onSelect: () => askRelocateAll("r2"),
        });
        actions.push({
            key: "relocate-all-local",
            icon: HardDriveDownload,
            title: t("suite.ged.documents.relocation.all_to_local"),
            loading: relocatingAll.value,
            onSelect: () => askRelocateAll("local"),
        });
    }

    return actions;
});
</script>

<template>
    <div ref="container" class="aurora-stack">
        <!-- Header: breadcrumb + search + add -->
        <div class="aurora-card flex flex-col sm:flex-row sm:items-center gap-3 px-2 py-2 sm:px-4 sm:py-3">
            <nav class="flex items-center gap-1 text-sm text-muted min-w-0 flex-1 flex-wrap">
                <template v-if="allDocumentsView">
                    <span class="flex items-center gap-1.5 text-primary shrink-0">
                        <Layers class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("suite.ged.documents.all_documents") }}
                    </span>
                </template>
                <template v-else>
                    <AppTextLinkButton color="muted" size="sm" class="shrink-0 no-underline hover:no-underline" v-on:click="navigateToRoot">
                        <Home class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("suite.ged.documents.root_folder") }}
                    </AppTextLinkButton>
                    <template v-for="crumb in breadcrumbs" :key="crumb.id">
                        <ChevronRight class="w-3 h-3 shrink-0" :stroke-width="2" />
                        <AppTextLinkButton color="muted" size="sm" class="truncate no-underline hover:no-underline" v-on:click="navigateTo(crumb.id)">
                            {{ crumb.name }}
                        </AppTextLinkButton>
                    </template>
                </template>
            </nav>

            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:shrink-0">
                <div class="w-full sm:w-64">
                    <AppSearchInput v-model="searchInput" :placeholder="t('suite.ged.documents.search_placeholder')" v-on:search="onSearch" />
                </div>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </div>
        </div>

        <!-- The screen's how-to guide, next to what it explains;
     folded or unfolded, the choice applies to every panel. -->
        <AppGuide :title="t('suite.ged.documents.guide.title')" storage-key="ged-documents">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.ged.documents.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <div class="flex flex-col lg:flex-row gap-4">
            <!-- Sidebar -->

            <main class="flex-1 min-w-0 space-y-4">
                <!-- Filters -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2">
                    <AppMultiselect
                        v-model="filterCategoryId"
                        :options="filterCategoryOptions"
                        :allow-empty="true"
                        :placeholder="t('suite.ged.documents.filter_by_category')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-model="filterTagId"
                        :options="filterTagOptions"
                        :allow-empty="true"
                        :placeholder="t('suite.ged.documents.filter_by_tag')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-model="filterStatus"
                        :options="statusOptions"
                        :allow-empty="true"
                        :searchable="false"
                        :placeholder="t('suite.ged.documents.filter_by_status')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-model="filterMimeGroup"
                        :options="mimeGroupOptions"
                        :allow-empty="true"
                        :searchable="false"
                        :placeholder="t('suite.ged.documents.filter_by_type')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppCheckbox
                        v-model="filterOriginalsOnly"
                        :label="t('suite.ged.documents.originals_only')"
                        :title="t('suite.ged.documents.originals_only_hint')"
                        class="self-center"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppButton
                        variant="secondary"
                        size="sm"
                        class="w-full sm:w-auto"
                        :aria-expanded="showMoreFilters || 0 < moreFiltersCount"
                        v-on:click="showMoreFilters = !showMoreFilters"
                    >
                        <SlidersHorizontal class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("suite.ged.documents.more_filters") }}
                        <span v-if="moreFiltersCount" class="rounded-full bg-accent-500/15 px-1.5 text-xs font-semibold text-accent-400 tabular-nums">
                            {{ moreFiltersCount }}
                        </span>
                    </AppButton>
                    <AppButton
                        v-if="hasActiveFilter"
                        variant="ghost"
                        size="sm"
                        class="w-full sm:w-auto"
                        v-on:click="resetFilters"
                    >
                        <X class="w-3 h-3" :stroke-width="2" /> {{ t("shared.common.reset") }}
                    </AppButton>
                </div>

                <!-- The wider search: where the box looks, when a document was
                     added, its shape and its weight. Behind a button because
                     most visits never need it; open whenever one is set. -->
                <div
                    v-if="showMoreFilters || 0 < moreFiltersCount"
                    class="aurora-card grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-5 sm:p-4"
                >
                    <AppMultiselect
                        v-model="searchIn"
                        :options="searchInOptions"
                        :allow-empty="false"
                        :searchable="false"
                        :label="t('suite.ged.documents.search_in')"
                        v-on:update:model-value="onSearchInChange"
                    />
                    <AppDatePicker
                        v-model="filterAddedFrom"
                        :label="t('suite.ged.documents.added_from')"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppDatePicker
                        v-model="filterAddedTo"
                        :label="t('suite.ged.documents.added_to')"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-model="filterOrientation"
                        :options="orientationOptions"
                        :allow-empty="true"
                        :searchable="false"
                        :label="t('suite.ged.documents.orientation')"
                        :placeholder="t('suite.ged.documents.any')"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-model="filterWeight"
                        :options="weightOptions"
                        :allow-empty="true"
                        :searchable="false"
                        :label="t('suite.ged.documents.weight')"
                        :placeholder="t('suite.ged.documents.any')"
                        v-on:update:model-value="applyFilter"
                    />
                </div>

                <!-- Selection bar -->
                <div v-if="selectedIds.size" class="flex flex-wrap items-center gap-2 bg-accent-500/10 border border-accent-400/30 rounded-xl px-2 py-2 sm:px-4 sm:py-2.5">
                    <span class="text-sm font-medium text-accent-400">{{ selectedIds.size }} {{ t("shared.common.selected") }}</span>
                    <div class="flex gap-2 ml-auto flex-wrap">
                        <!-- The same bar as the post selection: two real
                             buttons of the same size, and not a ghost next
                             to a bare cross (02/10/2026). -->
                        <AppPageActions :actions="bulkActions" variant="secondary" size="sm" :busy="bulkRelocating" />
                        <AppButton
                            variant="ghost"
                            size="sm"
                            :label="t('shared.common.cancel')"
                            icon-only-on-phone
                            v-on:click="clearSelection"
                        >
                            <X class="w-4 h-4" :stroke-width="2" />
                        </AppButton>
                    </div>
                </div>

                <!-- View toolbar: sort + view mode + multiselect -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <div class="flex gap-1 border border-line rounded-lg p-0.5">
                        <AppTab
                            v-for="sortField in DOCUMENT_SORT_FIELDS"
                            :key="sortField.key"
                            size="xs"
                            :active="sortBy === sortField.key"
                            :title="sortField.labelKey ? t(sortField.labelKey) : sortField.label"
                            v-on:click="setSort(sortField.key)"
                        >
                            {{ sortField.labelKey ? t(sortField.labelKey) : sortField.label }}
                            <SortAsc v-if="sortBy === sortField.key && sortDirection === 'asc'" class="w-3 h-3" :stroke-width="2" />
                            <SortDesc v-else-if="sortBy === sortField.key" class="w-3 h-3" :stroke-width="2" />
                        </AppTab>
                    </div>
                    <!-- Absent where it is already refused: a narrow container
                         forces the thumbnails, so the switch changed nothing
                         on screen, and a button that does nothing reads as a
                         broken button. The choice is kept and comes back
                         with the room. -->
                    <div v-if="!isNarrow" class="flex border border-line rounded-lg p-0.5">
                        <AppIconButton
                            :class="storedViewMode === 'grid' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                            :title="t('shared.common.grid_view')"
                            :aria-label="t('shared.common.grid_view')"
                            :aria-pressed="storedViewMode === 'grid'"
                            v-on:click="setViewMode('grid')"
                        >
                            <LayoutGrid class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            :class="storedViewMode === 'list' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                            :title="t('shared.common.list_view')"
                            :aria-label="t('shared.common.list_view')"
                            :aria-pressed="storedViewMode === 'list'"
                            v-on:click="setViewMode('list')"
                        >
                            <List class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                    <AppIconButton
                        v-if="can('ged.documents.delete') || can('ged.documents.edit')"
                        class="border border-line"
                        :class="isSelecting ? 'bg-accent-500/15 text-accent-400' : 'text-muted hover:text-primary'"
                        :title="isSelecting ? t('suite.ged.documents.stop_selecting') : t('suite.ged.documents.select_mode')"
                        :aria-label="isSelecting ? t('suite.ged.documents.stop_selecting') : t('suite.ged.documents.select_mode')"
                        :aria-pressed="isSelecting"
                        v-on:click="isSelecting = !isSelecting; if (!isSelecting) clearSelection()"
                    >
                        <CheckSquare class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="relative space-y-4">
                    <AppNoData v-if="!displayedItems?.length" :message="t('suite.ged.documents.empty')" />

                    <!-- Grid view -->
                    <div v-if="viewMode === 'grid' && displayedItems?.length" class="grid grid-cols-1 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                        <div
                            v-for="gedDocument in displayedItems"
                            :key="gedDocument.id"
                            class="group relative bg-surface border rounded-lg overflow-hidden transition-colors cursor-pointer"
                            :class="[
                                selectedIds.has(gedDocument.id) ? 'border-accent-400 ring-2 ring-accent-500' : 'border-line hover:border-accent-400',
                                // A family reads as a stack: two edges behind the card.
                                gedDocument.alternates?.length ? 'shadow-[3px_-3px_0_-1px_var(--color-surface-3),6px_-6px_0_-2px_var(--color-surface-2)] mt-1.5 mr-1.5' : '',
                            ]"
                            draggable="true"
                            v-on:click="isSelecting ? toggleSelect(gedDocument.id) : viewDocument(gedDocument)"
                            v-on:dragstart="onDocumentDragStart($event, gedDocument)"
                        >
                            <div v-if="isSelecting" class="absolute top-1.5 left-1.5 z-10" v-on:click.stop="toggleSelect(gedDocument.id)">
                                <AppSelectionCheck :active="selectedIds.has(gedDocument.id)" />
                            </div>
                            <div class="relative aspect-square bg-surface-2 flex items-center justify-center overflow-hidden">
                                <AppImage
                                    v-if="thumbnailShown(gedDocument)"
                                    :key="thumbnailShown(gedDocument)"
                                    :src="thumbnailShown(gedDocument)"
                                    :alt="gedDocument.fileName ?? gedDocument.title"
                                    object-fit="cover"
                                />
                                <FileText v-else-if="gedDocument.fileMime === 'application/pdf'" class="w-12 h-12 text-rose-400" :stroke-width="1.5" />
                                <Paperclip v-else-if="gedDocument.fileUrl" class="w-10 h-10 text-muted" :stroke-width="1.5" />
                                <FileText v-else class="w-10 h-10 text-muted" :stroke-width="1.5" />
                                <AppBadge v-if="gedDocument.status" :color="DOCUMENT_STATUS_BADGE[gedDocument.status]" class="absolute top-1 right-1">{{ gedDocument.statusLabel }}</AppBadge>
                                <!-- **By finger, a button; by mouse, the
                                     hover.** The three actions of this
                                     thumbnail lived in an overlay that only
                                     appears on hover: on a phone, where hover
                                     does not exist, editing a document from
                                     the media library was impossible. Below
                                     `sm`, the six actions therefore open in
                                     the sheet, through a visible button. -->
                                <div
                                    v-if="!isSelecting"
                                    class="absolute bottom-1 right-1 sm:hidden"
                                    v-on:click.stop
                                >
                                    <AppRowActions :actions="documentActions(gedDocument)" :label="gedDocument.title ?? ''" />
                                </div>

                                <div v-if="!isSelecting" class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity hidden sm:flex items-center justify-center gap-1.5">
                                    <AppOverlayIconButton size="sm" variant="light" :title="t('shared.common.view')" v-on:click.stop="viewDocument(gedDocument)">
                                        <Eye class="w-4 h-4" :stroke-width="2" />
                                    </AppOverlayIconButton>
                                    <AppOverlayIconButton
                                        v-if="can('ged.documents.edit')"
                                        size="sm"
                                        variant="light"
                                        :title="t('shared.common.edit')"
                                        v-on:click.stop="openEdit(gedDocument)"
                                    >
                                        <Pencil class="w-4 h-4" :stroke-width="2" />
                                    </AppOverlayIconButton>
                                    <AppOverlayIconButton
                                        v-if="gedDocument.fileUrl"
                                        size="sm"
                                        variant="light"
                                        :title="t('shared.common.qr_code')"
                                        v-on:click.stop="openQr(gedDocument)"
                                    >
                                        <QrCode class="w-4 h-4" :stroke-width="2" />
                                    </AppOverlayIconButton>
                                </div>
                            </div>
                            <div class="p-2 space-y-1">
                                <div class="text-xs font-medium text-primary truncate" :title="gedDocument.title">{{ gedDocument.title }}</div>
                                <div v-if="gedDocument.reference" class="text-xs text-muted font-mono truncate">{{ gedDocument.reference }}</div>
                                <div class="text-xs text-muted">
                                    <span v-if="gedDocument.fileSize">{{ formatSize(gedDocument.fileSize) }}</span>
                                    <span v-if="gedDocument.width && gedDocument.height"><span v-if="gedDocument.fileSize"> · </span>{{ gedDocument.width }}×{{ gedDocument.height }}</span>
                                    <span v-if="gedDocument.categoryName"><span v-if="gedDocument.fileSize || gedDocument.width"> · </span>{{ gedDocument.categoryName }}</span>
                                </div>
                                <div v-if="gedDocument.folderName" class="text-xs text-accent-400/80 truncate flex items-center gap-1">
                                    <Folder class="w-2.5 h-2.5 shrink-0" :stroke-width="2" />{{ gedDocument.folderName }}
                                </div>
                                <DocumentFamilyChips
                                    v-if="familyOf(gedDocument).length"
                                    v-model="previewedMember[gedDocument.id]"
                                    :members="familyOf(gedDocument)"
                                />
                                <DocumentFamilyUsage
                                    v-if="familyOf(gedDocument).length"
                                    :members="familyOf(gedDocument)"
                                />
                                <div v-if="gedDocument.tags?.length || storageRelocationAvailable || 0 === gedDocument.usageCount || gedDocument.kept || gedDocument.alternateCount || gedDocument.originalId" class="flex flex-wrap items-center gap-1 pt-0.5">
                                    <DocumentStateBadges :doc="gedDocument" v-on:open-family="viewDocument" />
                                    <DocumentStorageChip
                                        v-if="storageRelocationAvailable"
                                        :disk="gedDocument.storageDisk"
                                        :state="gedDocument.storageTransferState"
                                        :error="gedDocument.storageTransferError"
                                    />
                                    <DocumentTagChip v-for="tag in gedDocument.tags" :key="tag.id" :tag="tag" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile cards (list view fallback on mobile) -->
                    <!-- No card list here: `useListViewMode` forces the
                         thumbnails as soon as the container is narrow, so
                         "list" and "narrow" are never true together. The
                         block that lived here was never displayed; the
                         thumbnail, one per row on phone, plays that
                         role. -->

                    <!-- Desktop table (list view) -->
                    <div v-show="viewMode === 'list' && !isNarrow" class="aurora-card overflow-x-auto scrollbar-thin">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-surface-2/50 border-b border-line/40">
                                    <th v-if="isSelecting" class="w-8 px-3 py-3" />
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("suite.ged.documents.title") }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("suite.ged.documents.category") }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("suite.ged.documents.status") }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("suite.ged.documents.file") }}</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("suite.ged.documents.size") }}</th>
                                    <th v-if="storageRelocationAvailable" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("suite.ged.documents.storage.column") }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden xl:table-cell">{{ t("suite.ged.documents.preview") }}</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40">{{ t("shared.common.actions") }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/40">
                                <tr
                                    v-for="gedDocument in displayedItems"
                                    :key="gedDocument.id"
                                    class="group hover:bg-surface-2/40 transition-colors"
                                    :class="{ 'bg-accent-500/10': isSelecting && selectedIds.has(gedDocument.id), 'cursor-pointer': isSelecting }"
                                    draggable="true"
                                    v-on:click="isSelecting ? toggleSelect(gedDocument.id) : null"
                                    v-on:dragstart="onDocumentDragStart($event, gedDocument)"
                                >
                                    <td v-if="isSelecting" class="px-3 py-3" v-on:click.stop="toggleSelect(gedDocument.id)">
                                        <CheckSquare v-if="selectedIds.has(gedDocument.id)" class="w-4 h-4 text-accent-400" :stroke-width="2" />
                                        <Square v-else class="w-4 h-4 text-muted" :stroke-width="2" />
                                    </td>
                                    <td class="px-4 py-2">
                                        <p class="font-medium text-primary">{{ gedDocument.title }}</p>
                                        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                            <span v-if="gedDocument.reference" class="text-xs text-muted font-mono">{{ gedDocument.reference }}</span>
                                            <span v-if="gedDocument.folderName" class="text-xs text-muted flex items-center gap-0.5">
                                                <Folder class="w-3 h-3" :stroke-width="2" /> {{ gedDocument.folderName }}
                                            </span>
                                            <DocumentTagChip v-for="tag in gedDocument.tags" :key="tag.id" :tag="tag" />
                                            <!-- **Only when nothing displays it.** A count on
                                                 every row would fill the list with a figure that
                                                 is of no use: what you look for here is what you
                                                 can delete without breaking anything. Documents
                                                 in use therefore carry no mark, and the eye
                                                 lands on the others. The detail of who uses it
                                                 opens with the document. -->
                                            <DocumentStateBadges :doc="gedDocument" v-on:open-family="viewDocument" />
                                            <DocumentFamilyChips
                                                v-if="familyOf(gedDocument).length"
                                                v-model="previewedMember[gedDocument.id]"
                                                :members="familyOf(gedDocument)"
                                            />
                                            <DocumentFamilyUsage
                                                v-if="familyOf(gedDocument).length"
                                                class="basis-full"
                                                :members="familyOf(gedDocument)"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-secondary hidden md:table-cell">{{ gedDocument.categoryName ?? t("suite.ged.documents.no_category") }}</td>
                                    <td class="px-4 py-2 hidden lg:table-cell">
                                        <AppBadge :color="DOCUMENT_STATUS_BADGE[gedDocument.status]">{{ gedDocument.statusLabel }}</AppBadge>
                                    </td>
                                    <td class="px-4 py-2 hidden lg:table-cell">
                                        <span v-if="gedDocument.fileName" class="flex items-center gap-1 text-xs text-muted"><Paperclip class="w-3 h-3" :stroke-width="2" /> {{ gedDocument.fileName }}</span>
                                        <span v-else class="text-muted text-xs">-</span>
                                    </td>
                                    <td class="px-4 py-2 text-right hidden lg:table-cell text-xs text-muted tabular-nums">
                                        <span v-if="gedDocument.fileSize">{{ formatSize(gedDocument.fileSize) }}</span>
                                        <span v-else>-</span>
                                    </td>
                                    <!-- A column of its own rather than a chip tucked under the title:
                                         sorting a hundred rows by eye is what a column is for, and the
                                         chip under the title is only in the card view. Hidden entirely
                                         while a single suite exists, like the chip. -->
                                    <td v-if="storageRelocationAvailable" class="px-4 py-2 hidden lg:table-cell">
                                        <DocumentStorageChip
                                            :disk="gedDocument.storageDisk"
                                            :state="gedDocument.storageTransferState"
                                            :error="gedDocument.storageTransferError"
                                        />
                                    </td>
                                    <td class="px-4 py-2 hidden xl:table-cell">
                                        <!-- A card in grid mode already opens the detail modal on click; the
                                             thumbnail here looked identical and did nothing. Same target, so
                                             the same affordance: a real button, reachable by keyboard. -->
                                        <button
                                            v-if="gedDocument.fileUrl || gedDocument.thumbnailUrl"
                                            type="button"
                                            class="group/preview flex items-center gap-1.5 rounded transition-opacity hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-400 focus-visible:ring-offset-2 focus-visible:ring-offset-surface cursor-pointer"
                                            :aria-label="t('suite.ged.documents.preview_of', { name: gedDocument.title ?? gedDocument.fileName ?? '' })"
                                            v-on:click.stop="isSelecting ? toggleSelect(gedDocument.id) : viewDocument(gedDocument)"
                                        >
                                            <AppThumbnail
                                                v-if="gedDocument.thumbnailUrl"
                                                :src="gedDocument.thumbnailUrl"
                                                :alt="gedDocument.fileName"
                                                size="landscape"
                                            />
                                            <template v-else-if="gedDocument.fileMime === 'application/pdf'">
                                                <FileText class="w-5 h-5 shrink-0 text-rose-400" :stroke-width="1.5" />
                                                <span class="text-xs text-muted">PDF</span>
                                            </template>
                                            <template v-else>
                                                <FileText class="w-5 h-5 shrink-0" :stroke-width="1.5" />
                                                <span class="text-xs text-muted">{{ gedDocument.fileMime ?? '-' }}</span>
                                            </template>
                                        </button>
                                        <span v-else class="text-muted text-xs">-</span>
                                    </td>
                                    <td class="px-4 py-2 sticky right-0 bg-surface border-l border-line/40">
                                        <div class="flex items-center justify-end gap-0.5">
                                            <AppRowActions :actions="documentActions(gedDocument)" :label="gedDocument.title ?? ''" />
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!items?.length">
                                    <td :colspan="6"><AppNoData :message="t('suite.ged.documents.empty')" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <AppPagination v-if="totalPages > 1" :page="page" :total-pages="totalPages" v-on:change="goToPage" />
                    <AppLoader :active="loading" />
                </div>
            </main>
        </div>

        <!-- Create modal -->
        <AppModal
            :show="showCreate"
            :title="t('suite.ged.documents.create')"
            :icon="FileText"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <div class="space-y-4">
                <AppInput
                    v-model="newDocument.title"
                    :label="t('suite.ged.documents.title')"
                    :placeholder="t('suite.ged.documents.title_placeholder')"
                    :error="createErrors.title"
                    required
                />
                <AppInput v-model="newDocument.description" :label="t('suite.ged.documents.description')" :placeholder="t('suite.ged.documents.description_placeholder')" />
                <template v-if="newDocument.mimeType?.startsWith('image/')">
                    <AppInput v-model="newDocument.alt" :label="t('suite.ged.documents.alt')" :placeholder="t('suite.ged.documents.alt_placeholder')" />
                    <AppInput v-model="newDocument.caption" :label="t('suite.ged.documents.caption')" :placeholder="t('suite.ged.documents.caption_placeholder')" />
                </template>
                <AppMultiselect
                    v-model="newDocument.categoryId"
                    :label="t('suite.ged.documents.category')"
                    :options="categoryOptions"
                    :allow-empty="true"
                    :placeholder="t('suite.ged.documents.no_category')"
                />
                <AppMultiselect
                    v-if="tags.length"
                    v-model="newDocument.tagIds"
                    :label="t('suite.ged.documents.tags')"
                    :options="tagOptions"
                    :multiple="true"
                    :allow-empty="true"
                    :placeholder="t('suite.ged.documents.no_tags')"
                />
                <AppMultiselect
                    v-if="folders.length"
                    v-model="newDocument.folderId"
                    :label="t('suite.ged.documents.folder')"
                    :options="folderEditOptions"
                    :allow-empty="true"
                    :placeholder="t('suite.ged.documents.no_folder')"
                    track-by="id"
                    option-label="displayLabel"
                />
                <AppMultiselect
                    v-model="newDocument.status"
                    :label="t('suite.ged.documents.status')"
                    :options="statusOptions"
                    :allow-empty="false"
                    :searchable="false"
                />
                <div class="flex items-center gap-2 flex-wrap">
                    <AppFileInput v-on:change="onLocalFileCreate">
                        <template #default="{ trigger }">
                            <AppButton
                                variant="ghost"
                                size="sm"
                                type="button"
                                :loading="uploadingCreate"
                                v-on:click="trigger"
                            >
                                <Upload class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.ged.documents.choose_file") }}
                            </AppButton>
                        </template>
                    </AppFileInput>
                    <span v-if="newDocument.originalName ?? newDocument.fileName" class="text-sm text-muted flex items-center gap-1"><FileText class="w-4 h-4" :stroke-width="2" /> {{ newDocument.originalName ?? newDocument.fileName }}</span>
                </div>
                <!-- A new document can be born a variant, from the family strip
                     or by choosing its original here. -->
                <DocumentFamilyFields
                    v-model:kept="newDocument.kept"
                    v-model:original-id="newDocument.originalId"
                    v-model:original-title="newDocument.originalTitle"
                    v-model:label="newDocument.alternateLabel"
                    :alternates-path="alternatesPath"
                    :show-path="showPath"
                    :error="createErrors.originalId"
                    :label-suggestions="alternateLabels"
                />
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="createLoading" v-on:click="submitCreate"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Edit modal -->
        <AppModal
            :show="showEdit"
            :title="t('suite.ged.documents.edit', { title: editingDocument?.title ?? '' })"
            :icon="Pencil"
            max-width="4xl"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <div :class="editingDocument?.fileUrl ? 'grid grid-cols-1 md:grid-cols-2 gap-5 items-start' : 'space-y-4'">
                <AppFilePreview
                    v-if="editingDocument?.fileUrl"
                    :url="editingDocument.fileUrl"
                    :mime="editingDocument.fileMime"
                    :name="editingDocument.fileName"
                    :alt="editingDocument.alt ?? editingDocument.title"
                    max-height="28rem"
                    class="md:sticky md:top-0"
                />

                <div class="space-y-4">
                    <AppInput
                        v-model="editForm.title"
                        :label="t('suite.ged.documents.title')"
                        :placeholder="t('suite.ged.documents.title_placeholder')"
                        :error="editErrors.title"
                        required
                    />
                    <AppInput v-model="editForm.description" :label="t('suite.ged.documents.description')" :placeholder="t('suite.ged.documents.description_placeholder')" />
                    <template v-if="editForm.mimeType?.startsWith('image/')">
                        <AppInput v-model="editForm.alt" :label="t('suite.ged.documents.alt')" :placeholder="t('suite.ged.documents.alt_placeholder')" />
                        <AppInput v-model="editForm.caption" :label="t('suite.ged.documents.caption')" :placeholder="t('suite.ged.documents.caption_placeholder')" />
                    </template>
                    <AppMultiselect
                        v-model="editForm.categoryId"
                        :label="t('suite.ged.documents.category')"
                        :options="categoryOptions"
                        :allow-empty="true"
                        :placeholder="t('suite.ged.documents.no_category')"
                    />
                    <AppMultiselect
                        v-if="tags.length"
                        v-model="editForm.tagIds"
                        :label="t('suite.ged.documents.tags')"
                        :options="tagOptions"
                        :multiple="true"
                        :allow-empty="true"
                        :placeholder="t('suite.ged.documents.no_tags')"
                    />
                    <AppMultiselect
                        v-if="folders.length"
                        v-model="editForm.folderId"
                        :label="t('suite.ged.documents.folder')"
                        :options="folderEditOptions"
                        :allow-empty="true"
                        :placeholder="t('suite.ged.documents.no_folder')"
                        track-by="id"
                        option-label="displayLabel"
                    />
                    <AppMultiselect
                        v-model="editForm.status"
                        :label="t('suite.ged.documents.status')"
                        :options="statusOptions"
                        :allow-empty="false"
                        :searchable="false"
                    />
                    <div class="flex items-center gap-2 flex-wrap">
                        <AppFileInput v-on:change="onLocalFileEdit">
                            <template #default="{ trigger }">
                                <AppButton
                                    variant="ghost"
                                    size="sm"
                                    type="button"
                                    :loading="uploadingEdit"
                                    v-on:click="trigger"
                                >
                                    <Upload class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.ged.documents.choose_file") }}
                                </AppButton>
                            </template>
                        </AppFileInput>
                        <span v-if="editForm.fileName" class="text-sm text-muted flex items-center gap-1"><FileText class="w-4 h-4" :stroke-width="2" /> {{ editForm.fileName }}</span>
                    </div>
                    <DocumentFamilyFields
                        v-model:kept="editForm.kept"
                        v-model:original-id="editForm.originalId"
                        v-model:original-title="editForm.originalTitle"
                        v-model:label="editForm.alternateLabel"
                        :doc="editingDocument"
                        :alternates-path="alternatesPath"
                        :show-path="showPath"
                        :error="editErrors.originalId"
                        :label-suggestions="alternateLabels"
                        v-on:open="openEdit"
                    />
                </div>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Delete document modal -->
        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">{{ t("suite.ged.documents.delete_confirm", { title: pendingDelete?.title ?? "" }) }}</p>
            <p class="text-sm text-secondary">{{ t("suite.ged.documents.delete_warning") }}</p>
            <AppCheckbox
                v-if="pendingDelete?.alternateCount > 0"
                v-model="deleteWithAlternates"
                class="pt-2"
                :label="t('suite.ged.documents.family.with_alternates', { count: pendingDelete.alternateCount }, pendingDelete.alternateCount)"
            />
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="submitDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Folder modal (sidebar create/edit) -->


        <!-- Move the whole médiathèque to one suite. Confirmed, unlike the
             per-row move: this one is not undone by pressing the other button,
             it is undone by moving everything back. -->
        <AppModal
            :show="!!relocateAllDisk"
            max-width="lg"
            :closeable="false"
            :title="relocateAllDisk === 'local' ? t('suite.ged.documents.relocation.all_to_local') : t('suite.ged.documents.relocation.all_to_remote')"
            :icon="relocateAllDisk === 'local' ? HardDriveDownload : CloudUpload"
            v-on:close="cancelRelocateAll"
        >
            <p class="text-sm text-primary">{{ relocateAllLabel }}</p>
            <p class="text-sm text-secondary">{{ t("suite.ged.documents.relocation.all_explain") }}</p>
            <p class="text-sm text-secondary">{{ t("suite.ged.documents.relocation.all_background") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="cancelRelocateAll"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="relocatingAll" v-on:click="confirmRelocateAll">
                        <component :is="relocateAllDisk === 'local' ? HardDriveDownload : CloudUpload" class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.confirm") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Bulk category modal: empty files the selection under none. -->
        <AppModal :show="openBulkCategory" max-width="sm" v-on:close="openBulkCategory = false">
            <h3 class="text-sm font-semibold text-primary mb-3">{{ t("suite.ged.documents.bulk_category", { count: selectedIds.size }) }}</h3>
            <AppMultiselect
                v-model="bulkCategoryTargetId"
                :options="categoryOptions"
                :label="t('suite.ged.documents.category')"
                :placeholder="t('suite.ged.documents.no_category')"
                :allow-empty="true"
            />
            <AppCheckbox
                v-if="selectedHaveAlternates"
                v-model="bulkCategoryWithAlternates"
                class="pt-3"
                :label="t('suite.ged.documents.family.categorize_with_alternates')"
            />
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="openBulkCategory = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" v-on:click="bulkCategorize"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Bulk move modal -->
        <AppModal :show="openBulkMove" max-width="sm" v-on:close="openBulkMove = false">
            <h3 class="text-sm font-semibold text-primary mb-3">{{ t("suite.ged.documents.bulk_move", { count: selectedIds.size }) }}</h3>
            <AppMultiselect
                v-model="bulkMoveTargetId"
                :options="[{ id: null, displayLabel: t('suite.ged.documents.root_folder') }, ...folderEditOptions]"
                :label="t('suite.ged.documents.folder')"
                :placeholder="t('suite.ged.documents.root_folder')"
                :allow-empty="true"
                track-by="id"
                option-label="displayLabel"
            />
            <AppCheckbox
                v-if="selectedHaveAlternates"
                v-model="bulkMoveWithAlternates"
                class="pt-3"
                :label="t('suite.ged.documents.family.move_with_alternates')"
            />
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="openBulkMove = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" v-on:click="bulkMove"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Detail modal -->
        <AppModal
            :show="!!viewingDocument"
            :title="viewingDocument?.title ?? ''"
            :icon="FileText"
            max-width="5xl"
            :closeable="false"
            v-on:close="viewingDocument = null"
        >
            <template v-if="viewingDocument">
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-start">
                    <div v-if="viewingDocument.fileUrl" class="lg:col-span-3 rounded-lg border border-line overflow-hidden">
                        <AppImagePreview v-if="viewingDocument.fileMime?.startsWith('image/')" :src="viewingDocument.fileUrl" :alt="viewingDocument.alt ?? viewingDocument.fileName" full />
                        <iframe
                            v-else-if="viewingDocument.fileMime === 'application/pdf'"
                            :src="viewingDocument.fileUrl"
                            class="w-full h-144"
                            :title="viewingDocument.fileName"
                        />
                        <!-- A film used to land in the generic branch below and show a
                             paper icon: the one file type whose whole point is to be
                             played, and the screen offered no way to play it. Unlike the
                             public page this one preloads its metadata, because somebody
                             opening a document here asked for that document, and the
                             duration is part of what they came to check. -->
                        <video
                            v-else-if="viewingDocument.fileMime?.startsWith('video/')"
                            class="block w-full max-h-144 bg-black"
                            controls
                            playsinline
                            preload="metadata"
                            :poster="viewingDocument.thumbnailUrl ?? undefined"
                        >
                            <source :src="viewingDocument.fileUrl" :type="viewingDocument.fileMime">
                        </video>
                        <audio
                            v-else-if="viewingDocument.fileMime?.startsWith('audio/')"
                            class="block w-full p-4"
                            controls
                            preload="metadata"
                            :src="viewingDocument.fileUrl"
                        />
                        <div v-else class="flex flex-col items-center justify-center gap-3 px-4 py-16 bg-surface-2">
                            <FileText class="w-16 h-16 text-muted" :stroke-width="1.25" />
                            <p class="text-sm font-medium text-primary truncate max-w-full">{{ viewingDocument.fileName }}</p>
                            <p v-if="viewingDocument.fileMime" class="text-xs text-muted">{{ viewingDocument.fileMime }}</p>
                        </div>
                    </div>

                    <div :class="viewingDocument.fileUrl ? 'lg:col-span-2 space-y-4' : 'lg:col-span-5 space-y-4'">
                        <div class="flex items-center gap-3 flex-wrap">
                            <AppBadge :color="DOCUMENT_STATUS_BADGE[viewingDocument.status]">{{ viewingDocument.statusLabel }}</AppBadge>
                            <span v-if="viewingDocument.reference" class="text-xs text-muted font-mono">{{ viewingDocument.reference }}</span>
                        </div>

                        <p v-if="viewingDocument.description" class="text-sm text-secondary leading-relaxed">{{ viewingDocument.description }}</p>

                        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                            <div v-if="viewingDocument.categoryName">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.category") }}</dt>
                                <dd class="text-primary">{{ viewingDocument.categoryName }}</dd>
                            </div>
                            <div v-if="viewingDocument.folderName">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.folder") }}</dt>
                                <dd class="text-primary flex items-center gap-1"><Folder class="w-3.5 h-3.5 text-muted shrink-0" :stroke-width="2" /> {{ viewingDocument.folderName }}</dd>
                            </div>
                            <div v-if="viewingDocument.fileName">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.file") }}</dt>
                                <dd class="text-secondary truncate" :title="viewingDocument.fileName">{{ viewingDocument.fileName }}</dd>
                            </div>
                            <div v-if="viewingDocument.fileSize">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.size") }}</dt>
                                <dd class="text-secondary tabular-nums">{{ formatSize(viewingDocument.fileSize) }}</dd>
                            </div>
                            <div v-if="viewingDocument.width && viewingDocument.height">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.dimensions") }}</dt>
                                <dd class="text-secondary tabular-nums">{{ viewingDocument.width }}×{{ viewingDocument.height }}</dd>
                            </div>
                            <div v-if="viewingDocument.fileMime">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.type") }}</dt>
                                <dd class="text-secondary">{{ viewingDocument.fileMime }}</dd>
                            </div>
                            <div v-if="storageRelocationAvailable">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.storage.column") }}</dt>
                                <dd>
                                    <DocumentStorageChip
                                        :disk="viewingDocument.storageDisk"
                                        :state="viewingDocument.storageTransferState"
                                        :error="viewingDocument.storageTransferError"
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("shared.common.created") }}</dt>
                                <dd class="text-secondary">{{ formatDate(viewingDocument.createdAt) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("shared.common.updated") }}</dt>
                                <dd class="text-secondary">{{ formatDate(viewingDocument.updatedAt) }}</dd>
                            </div>
                        </dl>

                        <dl v-if="viewingDocument.fileMime?.startsWith('image/') && (viewingDocument.alt || viewingDocument.caption)" class="space-y-3 text-sm">
                            <div v-if="viewingDocument.alt">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.alt") }}</dt>
                                <dd class="text-primary">{{ viewingDocument.alt }}</dd>
                            </div>
                            <div v-if="viewingDocument.caption">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.caption") }}</dt>
                                <dd class="text-primary">{{ viewingDocument.caption }}</dd>
                            </div>
                        </dl>

                        <div v-if="viewingDocument.fileUrl">
                            <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("suite.ged.documents.permalink") }}</dt>
                            <div class="flex items-center gap-2">
                                <code class="text-xs text-secondary bg-surface-2 rounded px-2 py-1 truncate flex-1">{{ permalinkFor(viewingDocument) }}</code>
                                <AppIconButton color="default" :title="t('shared.common.copy')" v-on:click="copy(permalinkFor(viewingDocument))">
                                    <Copy class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                            </div>
                        </div>

                        <div v-if="viewingDocument.tags?.length" class="flex flex-wrap gap-1.5">
                            <DocumentTagChip v-for="tag in viewingDocument.tags" :key="tag.id" :tag="tag" />
                        </div>

                        <div v-if="viewingDocumentVersions.length > 1" class="space-y-2">
                            <p class="text-xs text-muted uppercase tracking-wide">{{ t("suite.ged.documents.versions") }}</p>
                            <div class="divide-y divide-line/40 rounded-lg border border-line overflow-hidden">
                                <div
                                    v-for="version in viewingDocumentVersions"
                                    :key="version.id"
                                    class="flex items-center gap-3 px-3 py-2 text-sm"
                                    :class="version.versionNumber === viewingDocumentVersions[0].versionNumber ? 'bg-accent/5' : 'bg-surface'"
                                >
                                    <span class="shrink-0 text-xs font-mono font-medium px-1.5 py-0.5 rounded bg-surface-2 text-secondary">v{{ version.versionNumber }}</span>
                                    <span class="flex-1 truncate text-primary text-xs">{{ version.fileName }}</span>
                                    <span class="text-xs text-muted shrink-0">{{ formatDate(version.createdAt) }}</span>
                                    <a :href="version.fileUrl" target="_blank" download class="shrink-0 text-xs text-accent hover:underline flex items-center gap-0.5">
                                        <Download class="w-3 h-3" :stroke-width="2" />
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div v-if="viewingDocumentUsage && viewingDocumentUsage.total > 0" class="space-y-2">
                            <p class="text-xs text-muted uppercase tracking-wide">{{ t("suite.ged.documents.usage_title") }} ({{ viewingDocumentUsage.total }})</p>
                            <div class="divide-y divide-line/40 rounded-lg border border-line overflow-hidden">
                                <template v-for="group in viewingDocumentUsage.groups" :key="group.type">
                                    <component
                                        :is="item.href ? 'a' : 'div'"
                                        v-for="(item, index) in group.items"
                                        :key="group.type + '-' + index"
                                        :href="item.href || undefined"
                                        class="flex items-center gap-3 px-3 py-2 text-xs"
                                        :class="item.href ? 'hover:bg-surface-2 transition' : ''"
                                    >
                                        <span class="shrink-0 text-muted">{{ item.detail }}</span>
                                        <span class="flex-1 truncate text-primary">{{ item.label }}</span>
                                        <ExternalLink v-if="item.href" class="w-3 h-3 text-muted shrink-0" :stroke-width="2" />
                                    </component>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
                <DocumentFamilyStrip
                    class="mt-5 border-t border-line/40 pt-4"
                    :doc="viewingDocument"
                    :alternates-path="alternatesPath"
                    :can-add="can('ged.documents.create')"
                    :can-recolor="canRecolor(viewingDocument)"
                    v-on:open="viewDocument"
                    v-on:add="startVariant"
                    v-on:recolor="recolorTarget = $event"
                />
            </template>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="viewingDocument = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.close") }}</AppButton>
                    <AppButton
                        v-if="cropPath && can('ged.documents.edit') && viewingDocument?.fileMime?.startsWith('image/')"
                        variant="ghost"
                        size="md"
                        v-on:click="cropTarget = viewingDocument"
                    >
                        <Crop class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.ged.documents.crop") }}
                    </AppButton>
                    <AppButton
                        v-if="canRecolor(viewingDocument)"
                        variant="ghost"
                        size="md"
                        v-on:click="recolorTarget = viewingDocument"
                    >
                        <Palette class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.ged.documents.recolor.open") }}
                    </AppButton>
                    <AppButton
                        v-if="viewingDocument?.fileUrl"
                        variant="ghost"
                        size="md"
                        v-on:click="openQr(viewingDocument)"
                    >
                        <QrCode class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.qr_code") }}
                    </AppButton>
                    <AppButton
                        v-if="viewingDocument?.fileUrl"
                        variant="secondary"
                        size="md"
                        :href="viewingDocument.fileUrl"
                        download
                    >
                        <Download class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.download") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <DocumentRecolorModal
            v-if="recolorPath"
            :doc="recolorTarget"
            :recolor-path="recolorPath"
            :theme-color="themeColor"
            :label-suggestions="alternateLabels"
            v-on:close="recolorTarget = null"
            v-on:created="onRecolored"
        />

        <AppQrCodeModal :item="qrDocument" v-on:close="closeQr" />

        <ImageCropperModal
            :item="cropTarget"
            :src="cropTarget?.fileUrl"
            :alt="cropTarget?.alt ?? ''"
            :name="cropTarget?.fileName"
            :crop-path="cropPath"
            entity-key="document"
            v-on:close="cropTarget = null"
            v-on:cropped="onCropped"
        />
    </div>
</template>
