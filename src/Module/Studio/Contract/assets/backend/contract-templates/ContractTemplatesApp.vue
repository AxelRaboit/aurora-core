<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useContractTemplatesList } from "./composables/useContractTemplatesList.js";
import { useContractTemplateActions } from "./composables/useContractTemplateActions.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { useListViewMode } from "@/shared/composables/list/useListViewMode.js";
import {
    Archive,
    Copy,
    FileX2,
    LayoutGrid,
    List,
    Pencil,
    Plus,
    Save,
    ScrollText,
    Trash2,
    X,
} from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    templates: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    archivePath: { type: String, required: true },
    restorePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    openDraftPath: { type: String, required: true },
    duplicatePath: { type: String, required: true },
    discardDraftPath: { type: String, required: true },
    editorPath: { type: String, required: true },
});

const {
    items,
    search,
    visibleItems,
    showArchived,
    archivedCount,
    kind,
    category,
    setCategory,
    categoryCounts,
    NO_CATEGORY,
    setKind,
    kindCounts,
    showCreate,
    newTemplate,
    createErrors,
    createLoading,
    openCreate,
    submitCreate,
    showRename,
    renaming,
    renameForm,
    renameErrors,
    renameLoading,
    openRename,
    submitRename,
    pendingDelete,
    pendingDuplicate,
    pendingDiscard,
    busy,
    archive,
    restore,
    confirmDelete,
    confirmDuplicate,
    confirmDiscard,
    openDraft,
    editorPath,
} = useContractTemplatesList(props);

/**
 * List first, cards on demand.
 *
 * A list answers what this screen is opened for - which trames exist, what is
 * in force, whether something is open - in one row per trame. The cards say
 * the same thing with more air, which is worth having and not worth imposing.
 * Kept in the query string like every other list in the app, so a view is part
 * of the link somebody sends.
 */
const { viewMode, setViewMode, storedViewMode, isNarrow, container } =
    useListViewMode(["list", "grid"], "list");

const kindOptions = props.kinds.map((kind) => ({
    value: kind.value,
    label: t(kind.labelKey),
}));

function kindLabel(value) {
    return kindOptions.find((kind) => kind.value === value)?.label ?? value;
}

const categoryOptions = props.categories.map((category) => ({
    value: category.value,
    label: t(category.labelKey),
}));

/**
 * The form's list, with the empty option first.
 *
 * A select rather than a required choice: a trame written before anybody
 * decided how the library was organised has a legitimate answer, and it is
 * this one.
 */
const categorySelectOptions = [
    { value: "", label: t("backend.studio.contract_templates.category_none") },
    ...categoryOptions,
];

/**
 * The filter's list, "all" first.
 *
 * Kept as an option rather than left to the control: `AppMultiselect` allows
 * an empty value but shows nothing to clear one with on a single select -
 * deselecting means clicking the chosen row again, which nothing on screen
 * says. An explicit row is the only visible way back, and it is the one the
 * plain select offered before.
 */
const categoryFilterOptions = computed(() => [
    { value: "", label: t("backend.studio.contract_templates.category_all") },
    {
        value: NO_CATEGORY,
        label: `${t("backend.studio.contract_templates.category_none")} (${categoryCounts.value[NO_CATEGORY] ?? 0})`,
    },
    ...categoryOptions.map((option) => ({
        value: option.value,
        label: `${option.label} (${categoryCounts.value[option.value] ?? 0})`,
    })),
]);

/**
 * Deselecting yields null, and the filter works in strings.
 *
 * `useQueryState` only accepts the values it was declared valid, so a null
 * would be discarded and clearing the filter would appear to do nothing.
 */
function setCategoryFilter(value) {
    setCategory(value ?? "");
}

/**
 * A colour per trade, picked against what the row already holds.
 *
 * Emerald is the version in force and amber the open draft, two columns away,
 * so neither can be spent here without saying something they do not mean.
 * Sky, violet and rose are free, and grey is the absence - a trade nobody has
 * chosen should read as quieter than the three that were.
 */
const CATEGORY_COLORS = {
    community_management: "sky",
    photography: "violet",
    development: "rose",
};

/** An unknown trade keeps the neutral badge rather than losing its pill. */
function categoryColor(value) {
    return CATEGORY_COLORS[value] ?? "gray";
}

/** Null, undefined and "" all read as unclassified; anything else names a trade. */
function categoryLabel(value) {
    return (
        categoryOptions.find((category) => category.value === value)?.label ??
        t("backend.studio.contract_templates.category_none")
    );
}

/**
 * The theme's own colour marks the body; an annex stays neutral.
 *
 * Not two fixed hues: the accent scale follows the theme somebody chose, so a
 * palette picked in the settings is the palette these cards use. A second
 * imported hue would be the one colour on the screen that ignores that choice.
 *
 * The pair also says something true. A body is the contract, an annex is
 * attached to one, and the hierarchy reads even in greyscale or for somebody
 * who does not separate hues: coloured versus plain rather than blue versus
 * violet. The type is named in the pill either way, which is what a reader
 * actually goes by.
 *
 * Written out rather than composed from the kind name, because Tailwind only
 * ships the classes it can see in the source.
 */
const KIND_STYLES = {
    body: {
        card: "border-accent-500/40 bg-accent-500/5",
        icon: "text-accent-500",
        pill: "border-accent-500/40 text-accent-600 dark:text-accent-400",
    },
    annex: {
        card: "border-line bg-surface-2/30",
        icon: "text-muted",
        pill: "border-line text-muted",
    },
};

/** An unknown kind keeps the neutral card rather than losing its border. */
function kindStyle(kind) {
    return (
        KIND_STYLES[kind] ?? {
            card: "border-line",
            icon: "text-muted",
            pill: "border-line text-muted",
        }
    );
}

/**
 * One list of actions, two presentations.
 *
 * The table shows them behind a single button, like every other list in the
 * app; the cards keep them laid out, because a card has the room and losing
 * them would make the wider view the poorer one.
 */
const actionsFor = useContractTemplateActions(editorPath);

const handlers = {
    openDraft,
    discard: (template) => (pendingDiscard.value = template),
    duplicate: (template) => (pendingDuplicate.value = template),
    rename: openRename,
    archive,
    restore,
    remove: (template) => (pendingDelete.value = template),
};

function rowActions(template) {
    return actionsFor(template, handlers);
}

/**
 * What the page offers, as opposed to what one trame offers.
 *
 * The archived toggle rides along rather than staying a button of its own: it
 * only appears when something has been archived, and a control that comes and
 * goes beside a permanent one made the row two different widths on two
 * different days.
 */
const pageActions = computed(() => {
    const actions = [];

    if (can("studio.contract_templates.create")) {
        actions.push({
            key: "create",
            color: "accent",
            icon: Plus,
            title: t("backend.studio.contract_templates.add"),
            onSelect: openCreate,
        });
    }

    if (archivedCount.value) {
        actions.push({
            key: "archived",
            icon: Archive,
            title: showArchived.value
                ? t("backend.studio.contract_templates.hide_archived")
                : t("backend.studio.contract_templates.show_archived", {
                    count: archivedCount.value,
                }),
            onSelect: () => (showArchived.value = !showArchived.value),
        });
    }

    return actions;
});
</script>

<template>
    <div ref="container" class="space-y-2 sm:space-y-4">
        <AppListToolbar>
            <AppSearchInput
                v-model="search"
                :placeholder="t('backend.studio.contract_templates.search_placeholder')"
            />
            <!-- The toggle belongs to the search, not beside it: stacked under
                 the field on a phone it read as a second filter and cost a row.
                 Same control as every other list, so the gesture is learned
                 once. -->
            <template #inline>
                <div v-if="!isNarrow" class="flex shrink-0 border border-line rounded-lg p-0.5">
                    <AppIconButton
                        :title="t('shared.common.list_view')"
                        :class="storedViewMode === 'list' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setViewMode('list')"
                    >
                        <List class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        :title="t('shared.common.grid_view')"
                        :class="storedViewMode === 'grid' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setViewMode('grid')"
                    >
                        <LayoutGrid class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </template>
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>

        <!-- The type filter, in the pill group the rest of the app uses for
             filters. Two values and an "all", so a choice that reads at a
             glance rather than a panel of checkboxes - that panel earns its
             keep on the posts list, which filters on three dimensions at
             once. The count sits next to the label because a filter leading
             to an empty list is better seen before the click than after. -->
        <!-- Stacked on a phone, side by side from `sm`. A filter that hugs its
             own text leaves a thumb aiming at a third of the screen width, and
             the row it sits on looks like a mistake rather than a control. -->
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <div class="flex w-full flex-col p-1 bg-surface-2 border border-line rounded-lg gap-1 sm:inline-flex sm:w-auto sm:flex-row">
                <AppTab
                    size="sm"
                    class="justify-between sm:flex-none sm:justify-start"
                    :active="kind === ''"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    v-on:click="setKind('')"
                >
                    {{ t("backend.studio.contract_templates.filter_all_kinds") }}
                    <span class="ml-1 text-xs text-muted">{{ kindCounts[""] ?? 0 }}</span>
                </AppTab>
                <AppTab
                    v-for="option in kindOptions"
                    :key="option.value"
                    size="sm"
                    class="justify-between sm:flex-none sm:justify-start"
                    :active="kind === option.value"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    v-on:click="setKind(option.value)"
                >
                    {{ option.label }}
                    <span class="ml-1 text-xs text-muted">{{ kindCounts[option.value] ?? 0 }}</span>
                </AppTab>
            </div>

            <!-- A select rather than a second row of tabs: the two filters cross
                 rather than compete, and three trades plus "unclassified" plus
                 "all" is five more tabs on a line that already holds three.
                 Unclassified is an option of its own because "what have I not
                 sorted yet" is the question this screen is opened with the day
                 a second trade appears.

                 The same searchable select every other list filters with, so
                 the control is learned once - and so a library with thirty
                 trades stays usable, which a plain dropdown of thirty rows is
                 not. `allow-empty` because deselecting the chosen row is a
                 second way back, next to the "all" option. -->
            <AppMultiselect
                :model-value="category"
                :options="categoryFilterOptions"
                :allow-empty="true"
                :placeholder="t('backend.studio.contract_templates.category_all')"
                class="w-full sm:w-auto sm:min-w-44"
                v-on:update:model-value="setCategoryFilter"
            />
        </div>

        <!-- Two different absences: nothing exists yet, or nothing matches
             what is being asked. The second is the one where somebody should
             clear a filter rather than create a trame. -->
        <AppNoData
            v-if="!visibleItems.length"
            :message="
                items.length
                    ? t('backend.studio.contract_templates.no_match')
                    : t('backend.studio.contract_templates.empty')
            "
        />

        <!-- The list view. No colour but the type pill: a table earns its
             keep by being scannable, and a tinted row competes with the two
             states that actually change - published and draft. -->
        <div
            v-else-if="viewMode === 'list'"
            class="aurora-card overflow-x-auto scrollbar-thin"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">
                            {{ t("backend.studio.contract_templates.name") }}
                        </th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">
                            {{ t("backend.studio.contract_templates.kind_label") }}
                        </th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">
                            {{ t("backend.studio.contract_templates.category_label") }}
                        </th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">
                            {{ t("backend.studio.contract_templates.in_force") }}
                        </th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">
                            {{ t("backend.studio.contract_templates.state_draft") }}
                        </th>
                        <!-- Named, and the only column aligned right: it is
                             where the hand goes, not something to read across
                             with the rest. -->
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40">
                            {{ t("shared.common.actions") }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="template in visibleItems"
                        :key="template.id"
                        class="hover:bg-surface-2/40 transition-colors"
                        :class="{ 'opacity-60': template.isArchived }"
                    >
                        <td class="px-4 py-2 text-primary">
                            <span class="flex items-center gap-2 min-w-0">
                                <ScrollText class="w-4 h-4 shrink-0 text-muted" :stroke-width="2" />
                                <span class="truncate">{{ template.name }}</span>
                                <span
                                    v-if="template.isArchived"
                                    class="text-2xs uppercase tracking-wider px-1.5 py-0.5 rounded-full border border-line text-muted shrink-0"
                                >
                                    {{ t("backend.studio.contract_templates.state_archived") }}
                                </span>
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <span
                                class="text-2xs uppercase tracking-wider px-1.5 py-0.5 rounded-full border whitespace-nowrap"
                                :class="kindStyle(template.kind).pill"
                            >
                                {{ kindLabel(template.kind) }}
                            </span>
                        </td>
                        <!-- A badge, like the kind beside it. Unclassified gets
                             one too rather than an empty cell: a blank reads as
                             a bug, and grey against three colours says plainly
                             that nobody has chosen yet. -->
                        <td class="px-4 py-2 whitespace-nowrap">
                            <AppBadge :color="categoryColor(template.category)">
                                {{ categoryLabel(template.category) }}
                            </AppBadge>
                        </td>

                        <!-- The version in force reads as a state, so it gets
                             the colour the app gives a published thing. An
                             absence stays plain text: "never published" is a
                             sentence, not a state to spot. -->
                        <td class="px-4 py-2 whitespace-nowrap">
                            <AppBadge
                                v-if="template.publishedVersion"
                                color="emerald"
                                :href="editorPath(template.id, template.publishedVersionId)"
                            >
                                {{
                                    t("backend.studio.contract_templates.version_label", {
                                        number: template.publishedVersion,
                                    })
                                }}
                            </AppBadge>
                            <span v-else class="text-muted text-xs">
                                {{ t("backend.studio.contract_templates.never_published") }}
                            </span>
                        </td>
                        <!-- The draft is amber and clickable: on this screen it
                             is the one state somebody is meant to act on, and
                             the badge is the way into the editor. -->
                        <td class="px-4 py-2 hidden md:table-cell whitespace-nowrap">
                            <AppBadge
                                v-if="template.draftId"
                                color="amber"
                                :href="editorPath(template.id, template.draftId)"
                            >
                                {{
                                    t("backend.studio.contract_templates.version_label", {
                                        number: template.draftVersion,
                                    })
                                }}
                            </AppBadge>
                            <span v-else class="text-muted text-xs">
                                {{ t("backend.studio.contract_templates.no_draft") }}
                            </span>
                        </td>
                        <td class="px-4 py-2 sticky right-0 bg-surface border-l border-line/40">
                            <AppRowActions
                                :actions="rowActions(template)"
                                :label="template.name"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- The card view: the same facts with more air, and the type carried
             by the whole card rather than by one pill. -->
        <div
            v-else-if="viewMode === 'grid'"
            class="grid gap-3 md:grid-cols-2"
        >
            <!-- `min-w-0` is what keeps the card inside the screen. A grid
                 item is `min-width: auto`, so the single column sizes itself
                 on the card's minimum content width rather than on the space
                 there is: at 320px the track came out at 322px against a 288px
                 box, every card hung 34px past the right edge and the page
                 scrolled sideways. Nothing inside needs that width - with the
                 floor lifted the whole card reflows and nothing overflows. -->
            <article
                v-for="template in visibleItems"
                :key="template.id"
                class="bg-surface border rounded-lg p-4 space-y-3 min-w-0"
                :class="[
                    kindStyle(template.kind).card,
                    { 'opacity-60': template.isArchived },
                ]"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <h2 class="font-medium text-primary flex items-center gap-2">
                            <ScrollText
                                class="w-4 h-4 shrink-0"
                                :class="kindStyle(template.kind).icon"
                                :stroke-width="2"
                            />
                            <span class="truncate">{{ template.name }}</span>
                        </h2>
                        <p class="text-xs text-muted flex flex-wrap items-center gap-1.5">
                            <!-- The type as a pill rather than as grey text: it
                                 is the first thing somebody looks for on this
                                 screen, and the colour only helps if it is
                                 named next to it. -->
                            <span
                                class="text-2xs uppercase tracking-wider px-1.5 py-0.5 rounded-full border"
                                :class="kindStyle(template.kind).pill"
                            >
                                {{ kindLabel(template.kind) }}
                            </span>
                            <AppBadge :color="categoryColor(template.category)">
                                {{ categoryLabel(template.category) }}
                            </AppBadge>
                            <AppBadge
                                v-for="locale in template.locales"
                                :key="locale"
                                color="slate"
                            >
                                {{ locale }}
                            </AppBadge>
                        </p>
                    </div>
                    <span
                        v-if="template.isArchived"
                        class="text-2xs uppercase tracking-wider px-2 py-0.5 rounded-full border border-line text-muted shrink-0"
                    >
                        {{ t("backend.studio.contract_templates.state_archived") }}
                    </span>
                </div>

                <!-- The two facts that matter, and the two absences that mean
                     different things: never published, versus nothing open. -->
                <dl class="grid grid-cols-2 gap-2 text-xs">
                    <div class="space-y-0.5">
                        <dt class="text-muted uppercase tracking-wider">
                            {{ t("backend.studio.contract_templates.in_force") }}
                        </dt>
                        <dd class="text-primary">
                            <AppBadge
                                v-if="template.publishedVersion"
                                color="emerald"
                                :href="editorPath(template.id, template.publishedVersionId)"
                            >
                                {{
                                    t("backend.studio.contract_templates.version_label", {
                                        number: template.publishedVersion,
                                    })
                                }}
                            </AppBadge>
                            <span v-else class="text-muted">
                                {{ t("backend.studio.contract_templates.never_published") }}
                            </span>
                        </dd>
                    </div>
                    <div class="space-y-0.5">
                        <dt class="text-muted uppercase tracking-wider">
                            {{ t("backend.studio.contract_templates.state_draft") }}
                        </dt>
                        <dd class="text-primary">
                            <AppBadge
                                v-if="template.draftId"
                                color="amber"
                                :href="editorPath(template.id, template.draftId)"
                            >
                                {{
                                    t("backend.studio.contract_templates.version_label", {
                                        number: template.draftVersion,
                                    })
                                }}
                            </AppBadge>
                            <span v-else class="text-muted">
                                {{ t("backend.studio.contract_templates.no_draft") }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <!-- The same actions as the row, laid out rather than folded:
                     a card has the room, and the list is defined once so the
                     two views cannot drift apart. -->
                <div class="flex flex-wrap gap-2 pt-1 border-t border-line/40">
                    <AppButton
                        v-for="action in rowActions(template)"
                        :key="action.key"
                        variant="ghost"
                        size="sm"
                        :href="action.href"
                        :loading="action.key === 'openDraft' && busy"
                        v-on:click="action.onSelect?.()"
                    >
                        <component :is="action.icon" class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ action.title }}
                    </AppButton>
                </div>
            </article>
        </div>

        <AppModal
            :show="showCreate"
            max-width="md"
            :closeable="false"
            :title="t('backend.studio.contract_templates.create')"
            :icon="ScrollText"
            v-on:close="showCreate = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <AppInput
                    v-model="newTemplate.name"
                    :label="t('backend.studio.contract_templates.name')"
                    :placeholder="t('backend.studio.contract_templates.name_placeholder')"
                    :error="createErrors.name"
                    required
                />
                <AppSelect
                    v-model="newTemplate.kind"
                    :label="t('backend.studio.contract_templates.kind_label')"
                    :options="kindOptions"
                    :hint="t('backend.studio.contract_templates.kind_hint')"
                />
                <AppSelect
                    v-model="newTemplate.category"
                    :label="t('backend.studio.contract_templates.category_label')"
                    :options="categorySelectOptions"
                    :hint="t('backend.studio.contract_templates.category_hint')"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="createLoading"
                        v-on:click="submitCreate"
                    >
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showRename"
            max-width="md"
            :closeable="false"
            :title="t('backend.studio.contract_templates.edit', { name: renaming?.name ?? '' })"
            :icon="Pencil"
            v-on:close="showRename = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitRename">
                <AppInput
                    v-model="renameForm.name"
                    :label="t('backend.studio.contract_templates.name')"
                    :placeholder="t('backend.studio.contract_templates.name_placeholder')"
                    :error="renameErrors.name"
                    required
                />
                <AppSelect
                    v-model="renameForm.kind"
                    :label="t('backend.studio.contract_templates.kind_label')"
                    :options="kindOptions"
                    :hint="t('backend.studio.contract_templates.kind_hint')"
                />
                <AppSelect
                    v-model="renameForm.category"
                    :label="t('backend.studio.contract_templates.category_label')"
                    :options="categorySelectOptions"
                    :hint="t('backend.studio.contract_templates.category_hint')"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showRename = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="renameLoading"
                        v-on:click="submitRename"
                    >
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingDuplicate"
            max-width="sm"
            :closeable="false"
            :title="t('backend.studio.contract_templates.duplicate')"
            :icon="Copy"
            v-on:close="pendingDuplicate = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contract_templates.duplicate_confirm", {
                        name: pendingDuplicate?.name ?? "",
                    })
                }}
            </p>
            <!-- What the copy is and is not, said before the click: it starts
                 as a draft, so nothing becomes usable by accident. -->
            <p class="text-sm text-secondary">
                {{ t("backend.studio.contract_templates.duplicate_hint") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDuplicate = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="busy"
                        v-on:click="confirmDuplicate"
                    >
                        <Copy class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contract_templates.duplicate") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- What comes back and what does not, said before the click: the
             version in force is untouched, and the number the draft claimed
             is spent for good. Somebody who reads "as if I had never opened
             it" and then sees version 4 where they expected 3 would think
             something broke. -->
        <AppModal
            :show="!!pendingDiscard"
            max-width="sm"
            :closeable="false"
            :title="t('backend.studio.contract_templates.discard')"
            :icon="FileX2"
            v-on:close="pendingDiscard = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contract_templates.discard_confirm", {
                        number: pendingDiscard?.draftVersion ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{
                    t("backend.studio.contract_templates.discard_hint", {
                        number: pendingDiscard?.draftVersion ?? "",
                        next: (pendingDiscard?.draftVersion ?? 0) + 1,
                    })
                }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDiscard = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="busy"
                        v-on:click="confirmDiscard"
                    >
                        <FileX2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contract_templates.discard") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contract_templates.delete_confirm", {
                        name: pendingDelete?.name ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.contract_templates.delete_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="busy"
                        v-on:click="confirmDelete"
                    >
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
