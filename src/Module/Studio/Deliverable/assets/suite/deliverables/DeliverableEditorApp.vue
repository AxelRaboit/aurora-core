<script setup>
/**
 * A deliverable, on its own page.
 *
 * Three tabs: the grid to compose the document, its appearance, and its
 * settings (title, language, header, what the client sees). No SEO, no
 * status: a deliverable is never on the site, and what decides that the
 * client reads it is the "visible par le client" box.
 *
 * The same editor for a space deliverable and a Studio deliverable: without
 * `space`, there is no client to open the document to, and the header shows
 * its shelf, personal or shared, instead.
 *
 * The grid is the site pages' grid, with its zones and its preview: a
 * deliverable is composed like a page, it is not published like a page.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Copy, ExternalLink, FileDown, FolderInput, FolderOutput, LayoutTemplate, Link2, Lock, RefreshCw, Save, Trash2, Users } from "lucide-vue-next";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppPageBar from "@/shared/components/nav/AppPageBar.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { queueFlash } from "@/shared/utils/flash.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useTabState } from "@/shared/composables/useTabState.js";
import { countPlaceholders } from "@/shared/utils/format/placeholders.js";
import PostGridPanel from "../../../../../Editorial/assets/suite/posts/components/PostGridPanel.vue";
import DeliverableAppearanceTab from "./components/DeliverableAppearanceTab.vue";
import DeliverableCopyToSpaceModal from "./components/DeliverableCopyToSpaceModal.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";
import DeliverableSettingsTab from "./components/DeliverableSettingsTab.vue";
import { useDeliverableEditor } from "./composables/useDeliverableEditor.js";

const props = defineProps({
    deliverable: { type: Object, required: true },
    /** The client's space; null for a Studio deliverable. */
    space: { type: Object, default: null },
    /** The author of a Studio deliverable. */
    ownerName: { type: String, default: null },
    locales: { type: Array, default: () => [] },
    hiddenZoneTypes: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    /** When null, they follow `canEdit`: that is a space's rule. */
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
    /** Studio: drop a copy into a client space, among these spaces. */
    copyToSpacePath: { type: String, default: "" },
    copyTargets: { type: Array, default: () => [] },
    /** Space: keep a copy in Studio; empty without the right. */
    copyToStudioPath: { type: String, default: "" },
    /** The Studio deliverable categories. */
    categories: { type: Array, default: () => [] },
    /** Studio: the clients that can be named, empty without the right to see them. */
    customers: { type: Array, default: () => [] },
    canPickCustomer: { type: Boolean, default: false },
});

const { t } = useI18n();
const { request } = useRequest();

const mayShare = computed(() => props.canShare ?? props.canEdit);
const mayDelete = computed(() => props.canDelete ?? props.canEdit);
const mayDuplicate = computed(() => props.canDuplicate ?? props.canEdit);

// Inside a client space the shell's header carries the way back, to the
// space's Deliverables tab: one way back per page.
const backLabel = computed(() => (props.space ? null : t("suite.studio.deliverables.back_to_list")));
const { form, saving, errors, conflict, dirty, save, saveAnyway, dismissConflict, markClean } = useDeliverableEditor(props);

/**
 * The [blanks] still in the document, for the badge in the header and the
 * client toggle: the grid, but also the title, the summary and the reading
 * header, where « Préparé pour » reads « [Client] » in a copied model.
 */
const placeholders = computed(() =>
    countPlaceholders([form.value.title, form.value.summary, form.value.readingHeader, form.value.gridContent?.zones]),
);

/**
 * Going back reopens the shelf the deliverable is now on: the author may have
 * moved it from one to the other in the settings.
 */
const backHref = computed(() => {
    if (props.space || !form.value.scope) return props.deliverablesPath;

    const url = new URL(props.deliverablesPath, window.location.origin);
    url.searchParams.set("scope", form.value.scope);

    return url.pathname + url.search;
});

// The keys go into the address: a link to a deliverable's appearance can be
// sent as is.
const TABS = ["content", "appearance", "settings"];
const { select: selectTab, isActive: isTabActive } = useTabState(TABS, { hash: true });

/**
 * A tab that holds an error says so, even when closed. The server only
 * answers for these three fields: the title and the language (settings), the
 * grid (content).
 */
const tabsWithErrors = computed(() => {
    const keys = Object.keys(errors.value);

    return {
        content: keys.includes("gridLayout"),
        appearance: false,
        settings: keys.some((key) => ["title", "locale"].includes(key)),
    };
});

/**
 * What the server refused, spelled out at the top of the page: a grid error
 * only had a dot on the tab, and a language error nothing at all when the
 * language selector is hidden (a single language).
 */
const errorMessages = computed(() => [
    ...new Set(
        Object.values(errors.value)
            .filter((message) => "string" === typeof message && "" !== message)
            .map((message) => t(message)),
    ),
]);

// ── Header ──────────────────────────────────────────────────────────────────

const showLinks = ref(false);
const pendingDelete = ref(false);
const deleting = ref(false);
const duplicating = ref(false);

/** Opening the preview saves first: the page shows what is in the database. */
async function openPreview() {
    if (dirty.value && !(await save())) return;

    window.open(props.previewPath, "_blank", "noopener");
}

/**
 * The document as slides, one landscape page each, ready for the browser's
 * « Save as PDF »: the only renderer that draws the grid as the page does.
 */
async function exportPdf() {
    if (dirty.value && !(await save())) return;

    window.open(`${props.previewPath}?print=1`, "_blank", "noopener");
}

async function duplicate() {
    if (duplicating.value) return;
    if (dirty.value && !(await save())) return;

    duplicating.value = true;
    try {
        const data = await request(props.duplicatePath, {});
        if (data?.success) {
            // Told to the landing page: this one would have left with the page.
            queueFlash("success", t("suite.studio.deliverables.duplicated"));
            window.location.href = data.editPath;
        }
    } finally {
        duplicating.value = false;
    }
}

const showCopyToSpace = ref(false);
const copyingToStudio = ref(false);

/** The copy starts from what is in the database: save first. */
async function openCopyToSpace() {
    if (dirty.value && !(await save())) return;

    showCopyToSpace.value = true;
}

async function copyToStudio() {
    if (copyingToStudio.value) return;
    if (dirty.value && !(await save())) return;

    copyingToStudio.value = true;
    try {
        const data = await request(props.copyToStudioPath, {});
        if (data?.success) {
            queueFlash("success", t("suite.studio.deliverables.copy_to_studio.done"));
            window.location.href = data.editPath;
        }
    } finally {
        copyingToStudio.value = false;
    }
}

async function doDelete() {
    if (deleting.value) return;

    deleting.value = true;
    try {
        const data = await request(props.deletePath, {});
        if (data?.success) {
            queueFlash("success", t("suite.studio.deliverables.deleted"));
            markClean();
            window.location.href = props.deliverablesPath;
        }
    } finally {
        deleting.value = false;
    }
}

/**
 * A panel's `inert` attribute, or nothing: Vue writes `inert="false"` for a
 * false value, and for a browser the mere presence of the attribute makes the
 * area inert. So it has to be omitted (`undefined`) when writing is allowed.
 */
const inertWhenReadOnly = computed(() => (props.canEdit ? undefined : true));

/** Reload the page: unsaved work is lost, and the dialog says so. */
function reload() {
    markClean();
    window.location.reload();
}

const headerActions = computed(() => {
    const actions = [
        {
            key: "preview",
            icon: ExternalLink,
            title: t("suite.studio.deliverables.preview"),
            description: t("suite.studio.deliverables.preview_hint"),
            onSelect: openPreview,
        },
        {
            key: "pdf",
            icon: FileDown,
            title: t("suite.studio.deliverables.export_pdf"),
            description: t("suite.studio.deliverables.export_pdf_hint"),
            onSelect: exportPdf,
        },
    ];

    if (mayShare.value) {
        actions.push({
            key: "links",
            icon: Link2,
            title: t("suite.studio.deliverables.share"),
            description: t("suite.studio.deliverables.links_hint"),
            onSelect: () => (showLinks.value = true),
        });
    }

    if (mayDuplicate.value) {
        actions.push({
            key: "duplicate",
            icon: Copy,
            title: t("suite.studio.deliverables.duplicate"),
            description: t("suite.studio.deliverables.duplicate_hint"),
            disabled: duplicating.value,
            onSelect: duplicate,
        });
    }

    if (props.copyToSpacePath && props.copyTargets.length) {
        actions.push({
            key: "copy-to-space",
            icon: FolderInput,
            title: t("suite.studio.deliverables.copy_to_space.action"),
            description: t("suite.studio.deliverables.copy_to_space.action_hint"),
            onSelect: openCopyToSpace,
        });
    }

    if (props.copyToStudioPath) {
        actions.push({
            key: "copy-to-studio",
            icon: FolderOutput,
            title: t("suite.studio.deliverables.copy_to_studio.action"),
            description: t("suite.studio.deliverables.copy_to_studio.action_hint"),
            disabled: copyingToStudio.value,
            onSelect: copyToStudio,
        });
    }

    if (mayDelete.value) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("suite.studio.deliverables.trash_action"),
            description: t("suite.studio.deliverables.delete_hint"),
            onSelect: () => (pendingDelete.value = true),
        });
    }

    return actions;
});
</script>

<template>
    <div class="aurora-stack">
        <!-- The bar every screen has: back on the left, commands on the
             right. The status pills do not go in it: three of them pushed the
             bar onto two lines on a phone, so they come under the title. -->
        <AppPageBar :back-href="backHref" :back-label="backLabel">
            <AppPageActions :actions="headerActions" icon-only-on-phone />
            <AppButton
                v-if="canEdit"
                variant="primary"
                size="md"
                :loading="saving"
                :label="t('shared.common.save')"
                icon-only-on-phone
                v-on:click="save()"
            >
                <Save class="h-4 w-4" :stroke-width="2" />
            </AppButton>
        </AppPageBar>

        <div class="min-w-0">
            <h1 class="m-0 truncate text-[1.625rem] font-semibold leading-tight tracking-tight text-primary">{{ form.title }}</h1>
            <p v-if="space" class="m-0 mt-0.5 text-sm text-secondary">
                {{ t("suite.studio.deliverables.for_customer", { name: space.customerName }) }}
            </p>
            <p v-else-if="ownerName" class="m-0 mt-0.5 text-sm text-secondary">
                {{ t("suite.studio.deliverables.scope.by", { name: ownerName }) }}
            </p>

            <!-- What the client sees, whatever the tab: you must know you are
                 editing a document they already read. Without a space, the
                 shelf: personal or shared with the team. -->
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <AppBadge v-if="!space" :color="'shared' === form.scope ? 'sky' : 'gray'">
                    <component :is="'shared' === form.scope ? Users : Lock" class="me-1 inline h-3 w-3 align-[-1px]" :stroke-width="2" />
                    {{ t(`suite.studio.deliverables.scope.${form.scope}`) }}
                </AppBadge>
                <AppBadge v-if="!space && form.template" color="violet">
                    <LayoutTemplate class="me-1 inline h-3 w-3 align-[-1px]" :stroke-width="2" />
                    {{ t("suite.studio.deliverables.template.badge") }}
                </AppBadge>
                <AppBadge v-else :color="form.visibleToClient ? 'emerald' : 'gray'">
                    {{ t(form.visibleToClient
                        ? "suite.studio.deliverables.visible_badge"
                        : "suite.studio.deliverables.hidden_badge") }}
                </AppBadge>
                <AppBadge v-if="placeholders" color="amber" :title="t('suite.posts.grid.placeholders_left', { count: placeholders })">
                    [{{ placeholders }}] {{ t("suite.studio.deliverables.placeholders_badge") }}
                </AppBadge>
                <!-- Said in words, not with a dot: a state that is only a
                     coloured dot does not exist for someone who cannot see it. -->
                <AppBadge v-if="dirty" color="amber">{{ t("suite.studio.deliverables.unsaved") }}</AppBadge>
                <AppBadge v-if="!canEdit" color="gray">{{ t("suite.studio.deliverables.read_only") }}</AppBadge>
            </div>
        </div>

        <ul
            v-if="errorMessages.length"
            class="m-0 flex list-none flex-col gap-1 rounded-lg border border-rose-500/40 bg-rose-500/10 p-3 text-sm text-rose-700 dark:text-rose-300"
            role="alert"
        >
            <li v-for="message in errorMessages" :key="message">{{ message }}</li>
        </ul>

        <p v-if="!canEdit" class="m-0 rounded-lg border border-line bg-surface-2 p-3 text-sm text-secondary" role="status">
            {{ t("suite.studio.deliverables.read_only_hint") }}
        </p>

        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.deliverables.editor_guide.title')" storage-key="deliverable-editor">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <!-- Without a space, the steps that talk about the client
                     describe its recipient and its visibility instead. -->
                <li v-for="step in 6" :key="step">{{ t(`suite.studio.deliverables.editor_guide.step_${step}${!space && [3, 4, 6].includes(step) ? "_studio" : ""}`) }}</li>
            </ol>
        </AppGuide>

        <div class="flex max-w-full gap-1 overflow-x-auto border-b border-line scrollbar-thin" role="tablist" :aria-label="t('suite.studio.deliverables.tabs.label')">
            <AppTab
                v-for="tab in TABS"
                :key="tab"
                class="shrink-0"
                variant="underline"
                role="tab"
                :aria-selected="isTabActive(tab) ? 'true' : 'false'"
                :active="isTabActive(tab)"
                v-on:click="selectTab(tab)"
            >
                {{ t(`suite.studio.deliverables.tabs.${tab}`) }}
                <template v-if="tabsWithErrors[tab]">
                    <span class="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-rose-500" aria-hidden="true" />
                    <span class="sr-only">{{ t("suite.studio.deliverables.tab_has_error") }}</span>
                </template>
            </AppTab>
        </div>

        <!-- v-show and not v-if: the grid holds text editors that keep their
             input, and switching tabs must not make them lose anything. -->
        <div v-show="isTabActive('content')" class="aurora-card p-3 sm:p-5" :inert="inertWhenReadOnly">
            <PostGridPanel
                :layout="form.gridLayout"
                :content="form.gridContent"
                :locale="form.locale"
                :preview-path="gridPreviewPath"
                :banner-preview-path="bannerPreviewPath"
                :toggleable="false"
                :hidden-types="hiddenZoneTypes"
                :preview-extra="{ title: form.title, summary: form.summary, appearance: form.appearance, readingHeader: form.readingHeader }"
            />
        </div>

        <DeliverableAppearanceTab
            v-show="isTabActive('appearance')"
            v-model:appearance="form.appearance"
            :inert="inertWhenReadOnly"
            :title="form.title"
            :summary="form.summary"
            :format="form.format"
        />

        <DeliverableSettingsTab
            v-show="isTabActive('settings')"
            v-model:title="form.title"
            v-model:summary="form.summary"
            v-model:locale="form.locale"
            v-model:reading-header="form.readingHeader"
            v-model:visible-to-client="form.visibleToClient"
            v-model:scope="form.scope"
            v-model:category-id="form.categoryId"
            v-model:template="form.template"
            v-model:customer-id="form.customerId"
            v-model:thumbnail="form.thumbnail"
            :inert="inertWhenReadOnly"
            :locales="locales"
            :errors="errors"
            :customer-name="space?.customerName ?? ''"
            :with-client="!!space"
            :can-show-to-client="mayShare"
            :can-change-scope="canChangeScope"
            :categories="categories"
            :customers="customers"
            :can-pick-customer="canPickCustomer"
            :placeholders="placeholders"
        />

        <DeliverableLinksModal :show="showLinks" :links-path="linksPath" v-on:close="showLinks = false" />

        <DeliverableCopyToSpaceModal
            v-if="copyToSpacePath"
            :show="showCopyToSpace"
            :source-title="form.title"
            :copy-path="copyToSpacePath"
            :targets="copyTargets"
            v-on:close="showCopyToSpace = false"
        />

        <!-- Someone else saved before us: say so, and do not choose between
             their version and ours on the author's behalf. -->
        <AppModal
            :show="conflict"
            max-width="md"
            :title="t('suite.studio.deliverables.conflict.title')"
            :icon="RefreshCw"
            v-on:close="dismissConflict"
        >
            <p class="m-0 text-sm text-primary">{{ t("suite.studio.deliverables.errors.conflict") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="dismissConflict">
                        {{ t("suite.studio.deliverables.conflict.keep_editing") }}
                    </AppButton>
                    <AppButton variant="secondary" size="md" v-on:click="reload">
                        {{ t("suite.studio.deliverables.conflict.reload") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="saving" v-on:click="saveAnyway">
                        {{ t("suite.studio.deliverables.conflict.overwrite") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <DeliverableDeleteModal
            :show="pendingDelete"
            :title="form.title"
            :deleting="deleting"
            v-on:cancel="pendingDelete = false"
            v-on:confirm="doDelete"
        />
    </div>
</template>
