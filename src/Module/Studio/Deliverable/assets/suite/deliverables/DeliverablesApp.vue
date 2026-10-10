<script setup>
/**
 * Studio deliverables: the ones written without a client space.
 *
 * Two shelves, like the notes: "Mes livrables", which only you see, and
 * "Partagés", the team's. A deliverable moves from one to the other through
 * its author. Otherwise it is the same document as in a space: the page grid,
 * its appearance, its reading links.
 *
 * The controls are those of the other lists: the shelves as a segmented
 * group with their count on their own row, then the toolbar with the search,
 * the filters as selects next to it, and the button on the right.
 *
 * Studio deliverables are sorted by category (audit, strategy...): a filter
 * next to the search, and a display by category, in sections, or as a plain
 * list. The filter and the display go into the address, like the shelf: a
 * link reopens the same view.
 *
 * A deliverable can be a template: a badge on its card, a "Modèles" entry in
 * the format filter, and "Partir d'un modèle" in the creation dialog.
 * The client it was written for, when it has one, shows on the card.
 *
 * Pages and presentations are a single list: a badge on a presentation's
 * card, and a format filter ("Tous les formats", Pages, Présentations,
 * Modèles) next to the categories, in the address like them. "Importer un texte" turns pasted text into a
 * presentation: a heading opens a slide, what follows fills it.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, FileInput, FolderInput, Layers, LayoutTemplate, Link2, List, Lock, Pencil, Plus, Presentation, Tags, Trash2, Users, X } from "lucide-vue-next";
import { useQueryState } from "@/shared/composables/useQueryState.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { queueFlash } from "@/shared/utils/flash.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCategoriesModal from "@/shared/components/category/AppCategoriesModal.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import DeliverableCards from "./components/DeliverableCards.vue";
import DeliverableCopyToSpaceModal from "./components/DeliverableCopyToSpaceModal.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
import DeliverableFormatFields from "./components/DeliverableFormatFields.vue";
import DeliverableImportModal from "./components/DeliverableImportModal.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";
import DeliverableScopePicker from "./components/DeliverableScopePicker.vue";
import { categoryOptions } from "./composables/categoryOptions.js";
import { templateOptions } from "./composables/templateOptions.js";
import { useDeliverableRequest } from "./composables/useDeliverableRequest.js";

const props = defineProps({
    personal: { type: Array, default: () => [] },
    shared: { type: Array, default: () => [] },
    initialScope: { type: String, default: "personal" },
    canCreate: { type: Boolean, default: false },
    /** The route that returns both shelves up to date, for a list gone stale. */
    listsPath: { type: String, default: "" },
    createPath: { type: String, required: true },
    /** Pasted text that becomes a presentation; when empty, the action is not shown. */
    importPath: { type: String, default: "" },
    scopePathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    linksPathTemplate: { type: String, required: true },
    copyToSpacePathTemplate: { type: String, default: "" },
    /** The spaces a copy can be dropped into; when empty, the action is not shown. */
    copyTargets: { type: Array, default: () => [] },
    /** The categories, in the chosen order: `{ id, name, color, position }`. */
    categories: { type: Array, default: () => [] },
    canManageCategories: { type: Boolean, default: false },
    categoryCreatePath: { type: String, default: "" },
    categoryUpdatePathTemplate: { type: String, default: "" },
    categoryDeletePathTemplate: { type: String, default: "" },
    categoryReorderPath: { type: String, default: "" },
});

const { t } = useI18n();

const SCOPES = ["personal", "shared"];

const lists = ref({ personal: [...props.personal], shared: [...props.shared] });
const search = ref("");

/**
 * The open shelf goes into the address: coming back from a deliverable
 * reopens the same one. Like every list state, through `useQueryState`: the
 * default shelf is not written there, and the browser history is kept.
 */
const { value: scopeQuery, set: setScope } = useQueryState("scope", { defaultValue: "personal", valid: SCOPES });

// With no shelf in the address, the one the server chose for the page: it
// reads the address too, so this only matters for a screen mounted elsewhere.
if (!new URLSearchParams(window.location.search).has("scope") && SCOPES.includes(props.initialScope)) {
    scopeQuery.value = props.initialScope;
}
const scope = computed(() => (SCOPES.includes(scopeQuery.value) ? scopeQuery.value : "personal"));

const categories = ref([...props.categories]);

/**
 * The server response carries both shelves: an action can move a deliverable
 * from one to the other. A category response also carries the category list,
 * renamed or reordered.
 */
function refresh(data) {
    lists.value = { personal: data.personal ?? [], shared: data.shared ?? [] };
    if (Array.isArray(data.categories)) categories.value = data.categories;
}

const { send } = useDeliverableRequest({ listPath: props.listsPath, onList: refresh });

// ── Categories: filter and display ──────────────────────────────────────────

const NO_CATEGORY = "none";

const { value: categoryQuery, set: setCategory } = useQueryState("category", { defaultValue: "" });

/**
 * The filter in force: the one in the address, if it still names a category.
 * A deleted category (from the dialog, or an old link) must not leave an
 * empty list for no reason: the filter falls back to "Toutes".
 */
const categoryFilter = computed(() => {
    const value = categoryQuery.value;
    const known = NO_CATEGORY === value || categories.value.some((category) => String(category.id) === value);

    return known ? value : "";
});
const { value: layout, set: setLayout } = useQueryState("layout", { defaultValue: "grouped", valid: ["grouped", "flat"] });

/** Deselecting returns null, and the filter speaks in strings. */
function setCategoryFilter(value) {
    setCategory(null === value || undefined === value ? "" : String(value));
}

/**
 * Pages, presentations, or both: in the address like the other filters.
 * The default format, "Tous", is not written there.
 */
const FORMATS = ["page", "slides"];
// "Tous" is the empty string: it must be valid, otherwise `set("")` would be
// ignored and the filter could no longer be turned off.
const { value: formatQuery, set: setFormatQuery } = useQueryState("format", { defaultValue: "", valid: ["", ...FORMATS] });
const formatFilter = computed(() => (FORMATS.includes(formatQuery.value) ? formatQuery.value : ""));

/** A deliverable with no stated format is a page: that is what the server creates by default. */
function formatOf(row) {
    return "slides" === row.format ? "slides" : "page";
}

/** The open shelf, in the chosen format: what the other filters count and filter. */
const scoped = computed(() =>
    "" === formatFilter.value ? lists.value[scope.value] : lists.value[scope.value].filter((row) => formatOf(row) === formatFilter.value),
);

/** How many of each format in the open shelf, for the format filter. */
const formatCounts = computed(() => {
    const rows = lists.value[scope.value];

    return {
        "": rows.length,
        page: rows.filter((row) => "page" === formatOf(row)).length,
        slides: rows.filter((row) => "slides" === formatOf(row)).length,
    };
});

/** Templates only, or everything: in the address like the other filters. */
const { value: templatesQuery, set: setTemplatesQuery } = useQueryState("templates", { defaultValue: "", valid: ["", "1"] });
const templatesOnly = computed(() => "1" === templatesQuery.value);

/** How many templates in the open shelf, for the filter's count. */
const templateCount = computed(() => lists.value[scope.value].filter((row) => row.template).length);

/**
 * Format and templates share one select, "Tous les formats", "Pages",
 * "Présentations", "Modèles": two pill groups beside the shelves and the
 * categories pushed "Modèles" alone onto a second line. The address keeps its
 * two parameters, `format` and `templates`, so older links still open the
 * same view.
 */
const TEMPLATES = "templates";
const kindFilter = computed(() => (templatesOnly.value ? TEMPLATES : formatFilter.value));

function setKindFilter(value) {
    setTemplatesQuery(TEMPLATES === value ? "1" : "");
    setFormatQuery(FORMATS.includes(value) ? value : "");
}

/**
 * "Modèles" only when the shelf holds some, or when the filter is on, so it
 * can be turned off. The counts as on the category filter.
 */
const kindFilterOptions = computed(() => [
    { value: "", label: t("suite.studio.deliverables.format.filter_all") },
    ...FORMATS.map((value) => ({
        value,
        label: `${t(`suite.studio.deliverables.format.filter_${value}`)} (${formatCounts.value[value]})`,
    })),
    ...(templateCount.value || templatesOnly.value
        ? [{ value: TEMPLATES, label: `${t("suite.studio.deliverables.template.filter")} (${templateCount.value})` }]
        : []),
]);

/** The search covers the title, the summary and the client, in the open shelf. */
const searched = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();
    const rows = templatesOnly.value ? scoped.value.filter((row) => row.template) : scoped.value;

    return needle
        ? rows.filter((row) => `${row.title} ${row.summary ?? ""} ${row.customer?.legalName ?? ""}`.toLocaleLowerCase().includes(needle))
        : rows;
});

function categoryKey(row) {
    return row.category?.id ? String(row.category.id) : NO_CATEGORY;
}

const visible = computed(() =>
    "" === categoryFilter.value ? searched.value : searched.value.filter((row) => categoryKey(row) === categoryFilter.value),
);

/** The filter's counts, over the open shelf and the current search. */
const categoryCounts = computed(() => {
    const counts = {};
    for (const row of searched.value) counts[categoryKey(row)] = (counts[categoryKey(row)] ?? 0) + 1;

    return counts;
});

const categoryFilterOptions = computed(() => [
    { value: "", label: t("suite.studio.deliverables.categories.all") },
    ...categories.value.map((category) => ({
        value: String(category.id),
        label: `${category.name} (${categoryCounts.value[String(category.id)] ?? 0})`,
    })),
    { value: NO_CATEGORY, label: `${t("suite.studio.deliverables.categories.none")} (${categoryCounts.value[NO_CATEGORY] ?? 0})` },
]);

/** By category, only when there are some: otherwise a single section would say nothing. */
const grouped = computed(() => "grouped" === layout.value && categories.value.length > 0);

/** The sections, in category order, "Sans catégorie" last; empty ones stay silent. */
const groups = computed(() => {
    const sections = categories.value.map((category) => ({
        key: String(category.id),
        name: category.name,
        color: category.color,
        rows: visible.value.filter((row) => categoryKey(row) === String(category.id)),
    }));
    sections.push({
        key: NO_CATEGORY,
        name: t("suite.studio.deliverables.categories.none"),
        color: null,
        rows: visible.value.filter((row) => NO_CATEGORY === categoryKey(row)),
    });

    return sections.filter((section) => section.rows.length);
});

/** What the page shows: the sections, or a single untitled one as a plain list. */
const sections = computed(() => (grouped.value ? groups.value : [{ key: "all", name: null, color: null, rows: visible.value }]));

const categorySelectOptions = computed(() => categoryOptions(categories.value));

const managingCategories = ref(false);

// ── Creation ────────────────────────────────────────────────────────────────

const creating = ref(false);
const saving = ref(false);
const title = ref("");
const newScope = ref("personal");
const newCategory = ref("");
const newTemplate = ref("");
/** A page or a presentation: decided here, once and for all. */
const newFormat = ref("page");
const errors = ref({});

/**
 * Both shelves, where the modal looks for templates: one starts from a shared
 * template as from one of one's own, see `DeliverableFormatFields`.
 */
const templateRows = computed(() => [...lists.value.personal, ...lists.value.shared]);

/** The templates of the chosen format, to take over the category of the one picked. */
const templateSelectOptions = computed(() => templateOptions(templateRows.value, newFormat.value));

// Picking a template files the new deliverable under its category: that is
// what it takes from it, and the selector stays there to change it.
watch(newTemplate, (value) => {
    const chosen = templateSelectOptions.value.find((option) => String(option.value) === String(value));
    if (chosen) newCategory.value = null === chosen.categoryId ? "" : chosen.categoryId;
});

function openCreate() {
    title.value = "";
    newTemplate.value = "";
    newFormat.value = "page";
    // In the shelf being viewed: create where you were looking.
    newScope.value = scope.value;
    // The filtered category, if it is one: create where you were looking.
    newCategory.value = "" !== categoryFilter.value && NO_CATEGORY !== categoryFilter.value ? categoryFilter.value : "";
    errors.value = {};
    creating.value = true;
}

async function create() {
    if (saving.value) return;

    saving.value = true;
    try {
        const data = await send(props.createPath, {
            title: title.value,
            scope: newScope.value,
            format: newFormat.value,
            categoryId: newCategory.value ? Number(newCategory.value) : null,
            fromTemplateId: newTemplate.value ? Number(newTemplate.value) : null,
        });
        if (!data?.success) {
            errors.value = data?.errors ?? {};

            return;
        }

        // Straight into the editor: a deliverable is started to be written.
        window.location.href = data.editPath;
    } finally {
        saving.value = false;
    }
}

// ── Importer un texte ───────────────────────────────────────────────────────

const importing = ref(false);
const importSaving = ref(false);
const importTitle = ref("");
const importScope = ref("personal");
const importCategory = ref("");
/** The pasted text, which only lives in the modal, see `DeliverableImportModal`. */
const importBlocks = ref([]);
const importErrors = ref({});

function openImport() {
    importTitle.value = "";
    importBlocks.value = [];
    importScope.value = scope.value;
    importCategory.value = "" !== categoryFilter.value && NO_CATEGORY !== categoryFilter.value ? categoryFilter.value : "";
    importErrors.value = {};
    importing.value = true;
}

async function submitImport() {
    if (importSaving.value) return;

    importSaving.value = true;
    try {
        const data = await send(props.importPath, {
            title: importTitle.value,
            scope: importScope.value,
            categoryId: importCategory.value ? Number(importCategory.value) : null,
            blocks: importBlocks.value,
        });
        if (!data?.success) {
            importErrors.value = data?.errors ?? {};

            return;
        }

        queueFlash("success", t("suite.studio.deliverables.import.done"));
        window.location.href = data.editPath;
    } finally {
        importSaving.value = false;
    }
}

const pageActions = computed(() => {
    const actions = [];
    if (props.canCreate) {
        actions.push({ key: "create", primary: true, color: "accent", icon: Plus, title: t("suite.studio.deliverables.add"), onSelect: openCreate });
        if (props.importPath) {
            actions.push({
                key: "import",
                icon: FileInput,
                title: t("suite.studio.deliverables.import.action"),
                description: t("suite.studio.deliverables.import.action_hint"),
                onSelect: openImport,
            });
        }
    }
    if (props.canManageCategories) {
        actions.push({
            key: "categories",
            icon: Tags,
            title: t("suite.studio.deliverables.categories.manage"),
            description: t("suite.studio.deliverables.categories.manage_hint"),
            onSelect: () => (managingCategories.value = true),
        });
    }

    return actions;
});

// ── Gestes d'une carte ──────────────────────────────────────────────────────

const busyId = ref(null);

async function changeScope(deliverable, value) {
    busyId.value = deliverable.id;
    try {
        const data = await send(buildPath(props.scopePathTemplate, { id: deliverable.id }), { scope: value });
        if (data?.success) {
            refresh(data);
            toast.success(t(`suite.studio.deliverables.scope.moved_${value}`));
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
            queueFlash("success", t("suite.studio.deliverables.duplicated"));
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
            refresh(data);
            toast.success(t("suite.studio.deliverables.deleted"));
        }

        // Succeeded or refused (already deleted by a colleague), the dialog
        // closes: the list has been redrawn either way.
        pendingDelete.value = null;
    } finally {
        deleting.value = false;
    }
}

/** The deliverable whose links dialog is open. */
const linksFor = ref(null);

/** The deliverable being copied into a client space. */
const copyFor = ref(null);

function actionsFor(deliverable) {
    const actions = [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("suite.studio.deliverables.open"),
            description: t("suite.studio.deliverables.open_hint"),
            href: deliverable.editPath,
        },
        {
            key: "preview",
            icon: ExternalLink,
            title: t("suite.studio.deliverables.preview"),
            description: t("suite.studio.deliverables.preview_hint"),
            onSelect: () => window.open(deliverable.previewPath, "_blank", "noopener"),
        },
    ];

    if (deliverable.canShare) {
        actions.push({
            key: "links",
            icon: Link2,
            title: t("suite.studio.deliverables.share"),
            description: t("suite.studio.deliverables.links_hint"),
            onSelect: () => (linksFor.value = deliverable),
        });
    }

    if (deliverable.canChangeScope) {
        const target = "personal" === deliverable.scope ? "shared" : "personal";
        actions.push({
            key: "scope",
            icon: "shared" === target ? Users : Lock,
            title: t(`suite.studio.deliverables.scope.move_${target}`),
            description: t(`suite.studio.deliverables.scope.move_${target}_hint`),
            disabled: busyId.value === deliverable.id,
            onSelect: () => changeScope(deliverable, target),
        });
    }

    if (deliverable.canDuplicate) {
        actions.push({
            key: "duplicate",
            icon: Copy,
            title: t("suite.studio.deliverables.duplicate"),
            description: t("suite.studio.deliverables.duplicate_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => duplicate(deliverable),
        });
    }

    // A page or a presentation: the copy takes the body, slides included.
    if (props.copyTargets.length && props.copyToSpacePathTemplate) {
        actions.push({
            key: "copy-to-space",
            icon: FolderInput,
            title: t("suite.studio.deliverables.copy_to_space.action"),
            description: t("suite.studio.deliverables.copy_to_space.action_hint"),
            onSelect: () => (copyFor.value = deliverable),
        });
    }

    if (deliverable.canDelete) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("suite.studio.deliverables.trash_action"),
            description: t("suite.studio.deliverables.delete_hint"),
            onSelect: () => (pendingDelete.value = deliverable),
        });
    }

    return actions;
}
</script>

<template>
    <div class="aurora-stack">
        <!-- The two shelves and the sentence that says what the current one
             is: their own row, above the toolbar, because they decide what
             every filter below counts. The house segmented group, at its
             natural width, scrolling sideways rather than wrapping. -->
        <div class="flex flex-col gap-2">
            <div
                class="flex w-fit max-w-full items-center gap-0.5 overflow-x-auto aurora-segmented scrollbar-hide"
                role="tablist"
                :aria-label="t('suite.studio.deliverables.scope.label')"
            >
                <button
                    v-for="value in SCOPES"
                    :key="value"
                    type="button"
                    role="tab"
                    :aria-selected="scope === value ? 'true' : 'false'"
                    class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-sm transition-colors"
                    :class="scope === value ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
                    v-on:click="setScope(value)"
                >
                    <component :is="'shared' === value ? Users : Lock" class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t(`suite.studio.deliverables.scope.tab_${value}`) }}
                    <span class="text-xs tabular-nums text-muted">{{ lists[value].length }}</span>
                </button>
            </div>
            <p class="m-0 text-xs text-muted sm:max-w-xl">{{ t(`suite.studio.deliverables.scope.intro_${scope}`) }}</p>
        </div>

        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('suite.studio.deliverables.search_placeholder')" />
            <!-- One row from `sm`, stacked on a phone: the display switch, the
                 category and the format, next to the search like the filters
                 of the other lists. The display switch is hidden on phones:
                 stacked under the search, it stretched across a whole line
                 for two icons stuck to the left. -->
            <template #inline>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div v-if="categories.length" class="hidden shrink-0 border border-line rounded-lg p-0.5 sm:flex">
                        <AppIconButton
                            :title="t('suite.studio.deliverables.categories.layout_grouped')"
                            :active="'grouped' === layout"
                            v-on:click="setLayout('grouped')"
                        >
                            <Layers class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            :title="t('suite.studio.deliverables.categories.layout_flat')"
                            :active="'flat' === layout"
                            v-on:click="setLayout('flat')"
                        >
                            <List class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                    <!-- "Toutes" first and "Sans catégorie" last, with their
                         count: a filter that leads to an empty list shows
                         before the click. -->
                    <AppSelect
                        v-if="categories.length"
                        :model-value="categoryFilter"
                        :options="categoryFilterOptions"
                        class="sm:min-w-48"
                        v-on:update:model-value="setCategoryFilter"
                    />
                    <AppSelect
                        :model-value="kindFilter"
                        :options="kindFilterOptions"
                        class="sm:min-w-48"
                        v-on:update:model-value="setKindFilter"
                    />
                </div>
            </template>
            <template #actions>
                <AppPageActions v-if="pageActions.length" :actions="pageActions" class="w-full sm:w-auto" />
            </template>
        </AppListToolbar>

        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.deliverables.studio_guide.title')" storage-key="studio-deliverables">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 6" :key="step">{{ t(`suite.studio.deliverables.studio_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <AppNoData
            v-if="!visible.length"
            :message="lists[scope].length
                ? t('suite.studio.deliverables.no_match')
                : t(`suite.studio.deliverables.scope.empty_${scope}`)"
            :hint="lists[scope].length || !canCreate ? '' : t(`suite.studio.deliverables.scope.empty_${scope}_hint`)"
        />

        <!-- One section per category, or a single untitled one as a plain
             list: the same cards, the same actions. -->
        <div v-else class="flex flex-col gap-6">
            <section v-for="section in sections" :key="section.key" class="flex flex-col gap-2">
                <h3 v-if="section.name" class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <span
                        class="h-2.5 w-2.5 shrink-0 rounded-full"
                        :class="section.color ? '' : 'border border-line'"
                        :style="section.color ? { backgroundColor: section.color } : {}"
                    />
                    {{ section.name }}
                    <span class="text-xs font-normal text-muted">{{ section.rows.length }}</span>
                </h3>
                <DeliverableCards :deliverables="section.rows" :actions-for="actionsFor">
                    <template #meta="{ deliverable }">
                        <span v-if="!grouped && deliverable.category" class="inline-flex items-center gap-1.5 text-xs text-secondary">
                            <span
                                class="h-2 w-2 shrink-0 rounded-full"
                                :style="deliverable.category.color ? { backgroundColor: deliverable.category.color } : {}"
                            />
                            {{ deliverable.category.name }}
                        </span>
                        <AppBadge v-if="'slides' === deliverable.format" color="emerald">
                            <Presentation class="me-1 inline h-3 w-3 align-[-1px]" :stroke-width="2" />
                            {{ t("suite.studio.deliverables.format.badge_slides") }}
                        </AppBadge>
                        <AppBadge v-if="deliverable.template" color="violet">
                            <LayoutTemplate class="me-1 inline h-3 w-3 align-[-1px]" :stroke-width="2" />
                            {{ t("suite.studio.deliverables.template.badge") }}
                        </AppBadge>
                        <span v-if="deliverable.customer" class="text-xs text-secondary">
                            {{ t("suite.studio.deliverables.for_customer", { name: deliverable.customer.legalName }) }}
                        </span>
                        <AppBadge v-if="'shared' === deliverable.scope" color="sky">
                            {{ deliverable.ownerName
                                ? t("suite.studio.deliverables.scope.by", { name: deliverable.ownerName })
                                : t("suite.studio.deliverables.scope.no_owner") }}
                        </AppBadge>
                        <AppBadge v-else-if="!deliverable.ownerName" color="amber">
                            {{ t("suite.studio.deliverables.scope.orphan") }}
                        </AppBadge>
                    </template>
                </DeliverableCards>
            </section>
        </div>

        <AppCategoriesModal
            v-if="canManageCategories"
            :show="managingCategories"
            :title="t('suite.studio.deliverables.categories.manage_title')"
            :intro="t('suite.studio.deliverables.categories.manage_intro')"
            :categories="categories"
            :create-path="categoryCreatePath"
            :update-path-template="categoryUpdatePathTemplate"
            :delete-path-template="categoryDeletePathTemplate"
            :reorder-path="categoryReorderPath"
            v-on:close="managingCategories = false"
            v-on:changed="refresh"
        />

        <DeliverableLinksModal
            :show="null !== linksFor"
            :links-path="linksFor ? buildPath(linksPathTemplate, { id: linksFor.id }) : ''"
            v-on:close="linksFor = null"
        />

        <DeliverableCopyToSpaceModal
            :show="null !== copyFor"
            :source-title="copyFor?.title ?? ''"
            :copy-path="copyFor ? buildPath(copyToSpacePathTemplate, { id: copyFor.id }) : ''"
            :targets="copyTargets"
            v-on:close="copyFor = null"
        />

        <AppModal
            :show="creating"
            max-width="md"
            :title="t('suite.studio.deliverables.create_title')"
            :icon="Plus"
            v-on:close="creating = false"
        >
            <form class="space-y-4" v-on:submit.prevent="create">
                <DeliverableFormatFields
                    v-model:format="newFormat"
                    v-model:template="newTemplate"
                    :rows="templateRows"
                    :error="errors.format ?? ''"
                />
                <AppInput
                    v-model="title"
                    autofocus
                    :label="t('suite.studio.deliverables.title')"
                    :placeholder="t('suite.studio.deliverables.title_placeholder')"
                    :hint="t('suite.studio.deliverables.studio_title_hint')"
                    :error="errors.title ?? ''"
                />
                <AppSelect
                    v-if="categories.length"
                    v-model="newCategory"
                    :label="t('suite.studio.deliverables.categories.label')"
                    :placeholder="t('suite.studio.deliverables.categories.none')"
                    :options="categorySelectOptions"
                />
                <fieldset class="m-0 space-y-2 border-0 p-0">
                    <legend class="mb-1.5 text-sm font-medium text-primary">{{ t("suite.studio.deliverables.scope.label") }}</legend>
                    <DeliverableScopePicker v-model="newScope" />
                </fieldset>
            </form>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="creating = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="create">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.studio.deliverables.create") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <DeliverableImportModal
            v-if="importPath"
            v-model:title="importTitle"
            v-model:blocks="importBlocks"
            :show="importing"
            :saving="importSaving"
            :errors="importErrors"
            v-on:close="importing = false"
            v-on:submit="submitImport"
        >
            <AppSelect
                v-if="categories.length"
                v-model="importCategory"
                :label="t('suite.studio.deliverables.categories.label')"
                :placeholder="t('suite.studio.deliverables.categories.none')"
                :options="categorySelectOptions"
            />
            <fieldset class="m-0 space-y-2 border-0 p-0">
                <legend class="mb-1.5 text-sm font-medium text-primary">{{ t("suite.studio.deliverables.scope.label") }}</legend>
                <DeliverableScopePicker v-model="importScope" />
            </fieldset>
        </DeliverableImportModal>

        <DeliverableDeleteModal
            :show="!!pendingDelete"
            :title="pendingDelete?.title ?? ''"
            :deleting="deleting"
            v-on:cancel="pendingDelete = null"
            v-on:confirm="doDelete"
        />
    </div>
</template>
