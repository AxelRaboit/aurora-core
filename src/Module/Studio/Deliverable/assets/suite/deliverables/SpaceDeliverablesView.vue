<script setup>
/**
 * A client's deliverables: audits, strategies, reports, everything written
 * to hand over to them.
 *
 * A module of its own, no longer site publications: the page grid to
 * compose, the document's appearance, and a box to open it to the client.
 * Neither draft nor publication: what is closed is in progress, what is open
 * the client reads in their space.
 *
 * Same layout as the neighbouring resources: the intro and the button on top,
 * cards in a list, the actions spelled out on a phone.
 *
 * A page or a presentation, as in Studio: the create modal asks the same two
 * questions (the format, the Studio template to start from), and "Importer
 * un texte" turns a pasted text into a presentation. A presentation wears
 * its badge on its card.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { AlertTriangle, Copy, Eye, EyeOff, ExternalLink, FileInput, FolderOutput, Link2, Pencil, Plus, Presentation, Trash2, X } from "lucide-vue-next";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { queueFlash } from "@/shared/utils/flash.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import DeliverableCards from "./components/DeliverableCards.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
import DeliverableFormatFields from "./components/DeliverableFormatFields.vue";
import DeliverableImportModal from "./components/DeliverableImportModal.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";
import { useDeliverableRequest } from "./composables/useDeliverableRequest.js";

const props = defineProps({
    deliverables: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    /**
     * The right to share the space, which is not the right to edit it: give a
     * reading address, and show or hide a deliverable from the client.
     */
    canShare: { type: Boolean, default: false },
    /** False for an archive: it receives no more deliverables, created or duplicated. */
    canAdd: { type: Boolean, default: false },
    /** The route that returns the rows up to date, for a list gone stale. */
    listPath: { type: String, default: "" },
    createPath: { type: String, required: true },
    /** A pasted text that becomes a presentation; empty, the gesture is not offered. */
    importPath: { type: String, default: "" },
    /**
     * The Studio templates to start from, pages and presentations:
     * `{ id, title, format, template, category }`. Empty without the right to
     * read Studio deliverables, and the picker is not drawn.
     */
    templates: { type: Array, default: () => [] },
    visibilityPathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    /** A deliverable's reading links; when empty, the action is not offered. */
    linksPathTemplate: { type: String, default: "" },
    /** Keep a copy in Studio; empty without the right to create there. */
    copyToStudioPathTemplate: { type: String, default: "" },
});

const { t } = useI18n();

const rows = ref([...props.deliverables]);

// The parent page can refresh its rows: without this, the ones here stayed
// those of the first load.
watch(
    () => props.deliverables,
    (next) => (rows.value = [...next]),
);

const { send } = useDeliverableRequest({
    listPath: props.listPath,
    onList: (data) => (rows.value = data.deliverables ?? []),
});

// ── Creation ────────────────────────────────────────────────────────────────

const creating = ref(false);
const saving = ref(false);
const title = ref("");
/** A page or a presentation, and the template to start from: see `DeliverableFormatFields`. */
const newFormat = ref("page");
const newTemplate = ref("");
const errors = ref({});

function openCreate() {
    title.value = "";
    newFormat.value = "page";
    newTemplate.value = "";
    errors.value = {};
    creating.value = true;
}

async function create() {
    if (saving.value) return;

    saving.value = true;
    try {
        const data = await send(props.createPath, {
            title: title.value,
            format: newFormat.value,
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
const importBlocks = ref([]);
const importErrors = ref({});

function openImport() {
    importTitle.value = "";
    importBlocks.value = [];
    importErrors.value = {};
    importing.value = true;
}

async function submitImport() {
    if (importSaving.value) return;

    importSaving.value = true;
    try {
        const data = await send(props.importPath, { title: importTitle.value, blocks: importBlocks.value });
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

// ── Gestes d'une ligne ──────────────────────────────────────────────────────

const busyId = ref(null);

/** What holds back opening to the client, until the author says they know. */
const pendingShow = ref(null);

async function toggleVisibility(deliverable, confirm = false) {
    busyId.value = deliverable.id;
    try {
        const visible = !deliverable.visibleToClient;
        const data = await send(
            buildPath(props.visibilityPathTemplate, { id: deliverable.id }),
            { visible, ...(confirm ? { confirm: true } : {}) },
            { own: ["confirmation_needed"] },
        );

        // An unfinished template, or unpublished images: the server refuses to
        // open without the author knowing, and says what is left.
        if ("confirmation_needed" === data?.error) {
            pendingShow.value = {
                deliverable,
                placeholders: data.placeholders ?? 0,
                pictures: data.withheldPictures ?? [],
            };

            return;
        }

        if (data?.success) {
            pendingShow.value = null;
            rows.value = data.deliverables;
            toast.success(t(deliverable.visibleToClient
                ? "suite.studio.deliverables.hidden_toast"
                : "suite.studio.deliverables.shown_toast"));
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

async function copyToStudio(deliverable) {
    busyId.value = deliverable.id;
    try {
        const data = await send(buildPath(props.copyToStudioPathTemplate, { id: deliverable.id }), {});
        if (data?.success) {
            queueFlash("success", t("suite.studio.deliverables.copy_to_studio.done"));
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
            rows.value = data.deliverables;
            toast.success(t("suite.studio.deliverables.deleted"));
        }

        // Succeeded or refused (the deliverable no longer exists), the dialog
        // closes: the list has been redrawn either way.
        pendingDelete.value = null;
    } finally {
        deleting.value = false;
    }
}

// ── Liens de lecture ────────────────────────────────────────────────────────

/** The deliverable whose links dialog is open. */
const linksFor = ref(null);

function actionsFor(deliverable) {
    const actions = [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("suite.studio.deliverables.open"),
            description: t("suite.studio.deliverables.open_hint"),
            // A navigation is a link, as the action sheet wants: changing the
            // address from `onSelect` did not fire on a real click.
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

    // The reading links, as in the editor: creating an address for a
    // recipient no longer requires opening the document first. Only with the
    // right to share the space: the list carries the addresses themselves.
    if (props.linksPathTemplate && props.canShare) {
        actions.push({
            key: "links",
            icon: Link2,
            title: t("suite.studio.deliverables.share"),
            description: t("suite.studio.deliverables.links_hint"),
            onSelect: () => (linksFor.value = deliverable),
        });
    }

    // Keep this deliverable as a template: reading it here and being able to
    // create in Studio is enough.
    if (props.copyToStudioPathTemplate) {
        actions.push({
            key: "copy-to-studio",
            icon: FolderOutput,
            title: t("suite.studio.deliverables.copy_to_studio.action"),
            description: t("suite.studio.deliverables.copy_to_studio.action_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => copyToStudio(deliverable),
        });
    }

    if (!props.canEdit) return actions;

    actions.push(
        // Show or hide from the client: the right to share the space, on top
        // of the right to edit it, as everywhere in a space.
        ...(props.canShare
            ? [{
                key: "visibility",
                icon: deliverable.visibleToClient ? EyeOff : Eye,
                title: t(deliverable.visibleToClient
                    ? "suite.studio.deliverables.hide"
                    : "suite.studio.deliverables.show"),
                description: t(deliverable.visibleToClient
                    ? "suite.studio.deliverables.hide_hint"
                    : "suite.studio.deliverables.show_hint"),
                disabled: busyId.value === deliverable.id,
                onSelect: () => toggleVisibility(deliverable),
            }]
            : []),
        ...(props.canAdd
            ? [{
                key: "duplicate",
                icon: Copy,
                title: t("suite.studio.deliverables.duplicate"),
                description: t("suite.studio.deliverables.duplicate_hint"),
                disabled: busyId.value === deliverable.id,
                onSelect: () => duplicate(deliverable),
            }]
            : []),
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("suite.studio.deliverables.trash_action"),
            description: t("suite.studio.deliverables.delete_hint"),
            onSelect: () => (pendingDelete.value = deliverable),
        },
    );

    return actions;
}
</script>

<template>
    <div class="aurora-stack">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="m-0 text-xs text-muted sm:max-w-lg">{{ t("suite.studio.deliverables.intro") }}</p>

            <div v-if="canEdit && canAdd" class="flex flex-col gap-2 sm:flex-row">
                <AppButton
                    v-if="importPath"
                    variant="ghost"
                    size="sm"
                    class="w-full justify-center sm:w-auto"
                    v-on:click="openImport"
                >
                    <FileInput class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("suite.studio.deliverables.import.action") }}
                </AppButton>
                <AppButton
                    variant="ghost"
                    size="sm"
                    class="w-full justify-center sm:w-auto"
                    v-on:click="openCreate"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("suite.studio.deliverables.add") }}
                </AppButton>
            </div>
        </div>

        <!-- The screen's how-to, next to what it explains;
     collapsed or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.deliverables.guide.title')" storage-key="space-deliverables">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 6" :key="step">{{ t(`suite.studio.deliverables.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <p v-if="canEdit && !canAdd" class="m-0 rounded-lg border border-line bg-surface-2 p-3 text-sm text-secondary" role="status">
            {{ t("suite.studio.deliverables.archived_hint") }}
        </p>

        <AppNoData
            v-if="0 === rows.length"
            :message="t('suite.studio.deliverables.empty')"
            :hint="canEdit && canAdd ? t('suite.studio.deliverables.empty_hint') : ''"
        />

        <DeliverableCards v-else :deliverables="rows" :actions-for="actionsFor">
            <template #meta="{ deliverable }">
                <AppBadge v-if="'slides' === deliverable.format" color="emerald">
                    <Presentation class="me-1 inline h-3 w-3 align-[-1px]" :stroke-width="2" />
                    {{ t("suite.studio.deliverables.format.badge_slides") }}
                </AppBadge>
                <AppBadge :color="deliverable.visibleToClient ? 'emerald' : 'gray'">
                    {{ t(deliverable.visibleToClient
                        ? "suite.studio.deliverables.visible_badge"
                        : "suite.studio.deliverables.hidden_badge") }}
                </AppBadge>
            </template>
        </DeliverableCards>

        <DeliverableLinksModal
            :show="null !== linksFor"
            :links-path="linksFor ? buildPath(linksPathTemplate, { id: linksFor.id }) : ''"
            v-on:close="linksFor = null"
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
                    :rows="templates"
                    :error="errors.format ?? ''"
                />
                <AppInput
                    v-model="title"
                    autofocus
                    :label="t('suite.studio.deliverables.title')"
                    :placeholder="t('suite.studio.deliverables.title_placeholder')"
                    :hint="t('suite.studio.deliverables.title_hint')"
                    :error="errors.title ?? ''"
                />
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
        />

        <!-- Opening an unfinished document to the client: say so, do not
             refuse it, the author knows what they are doing. -->
        <AppModal
            :show="!!pendingShow"
            max-width="md"
            :title="t('suite.studio.deliverables.show_confirm.title')"
            :icon="AlertTriangle"
            v-on:close="pendingShow = null"
        >
            <div v-if="pendingShow" class="space-y-3 text-sm text-primary">
                <p class="m-0">{{ t("suite.studio.deliverables.show_confirm.intro", { title: pendingShow.deliverable.title }) }}</p>
                <p v-if="pendingShow.placeholders" class="m-0 rounded-md bg-amber-500/10 px-3 py-2 text-amber-700 dark:text-amber-400">
                    {{ t("suite.studio.deliverables.show_confirm.placeholders", { count: pendingShow.placeholders }) }}
                </p>
                <p v-if="pendingShow.pictures.length" class="m-0 rounded-md bg-amber-500/10 px-3 py-2 text-amber-700 dark:text-amber-400">
                    {{ t("suite.studio.deliverables.show_confirm.pictures", { count: pendingShow.pictures.length }) }}
                    <span class="block truncate text-xs text-secondary">{{ pendingShow.pictures.map((picture) => picture.name).join(", ") }}</span>
                </p>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingShow = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.studio.deliverables.show_confirm.keep_hidden") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="busyId === pendingShow?.deliverable.id" v-on:click="toggleVisibility(pendingShow.deliverable, true)">
                        <Eye class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.studio.deliverables.show_confirm.confirm") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <DeliverableDeleteModal
            :show="!!pendingDelete"
            :title="pendingDelete?.title ?? ''"
            :deleting="deleting"
            v-on:cancel="pendingDelete = null"
            v-on:confirm="doDelete"
        />
    </div>
</template>
