<script setup>
/**
 * Les livrables de Studio : ceux qu'on écrit sans espace client.
 *
 * Deux rayons, comme les notes : « Mes livrables », que soi seul voit, et
 * « Partagés », ceux de l'équipe. Un livrable passe de l'un à l'autre par son
 * auteur. Pour le reste, c'est le même document que dans un espace : la
 * grille des pages, son apparence, ses liens de lecture.
 *
 * Les contrôles sont ceux de la liste des trames de contrat, l'écran voisin :
 * la barre de recherche et le bouton en tête, l'encart du mode d'emploi, les
 * rayons en pastilles avec leur compte.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, Link2, Lock, Pencil, Plus, Trash2, Users, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import DeliverableCards from "./components/DeliverableCards.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";

const props = defineProps({
    personal: { type: Array, default: () => [] },
    shared: { type: Array, default: () => [] },
    initialScope: { type: String, default: "personal" },
    canCreate: { type: Boolean, default: false },
    createPath: { type: String, required: true },
    scopePathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    linksPathTemplate: { type: String, required: true },
});

const { t } = useI18n();
const { request } = useRequest();

const SCOPES = ["personal", "shared"];

const lists = ref({ personal: [...props.personal], shared: [...props.shared] });
const scope = ref(SCOPES.includes(props.initialScope) ? props.initialScope : "personal");
const search = ref("");

/** Le rayon ouvert va dans l'adresse : revenir d'un livrable rouvre le même. */
function setScope(value) {
    scope.value = value;
    const url = new URL(window.location.href);
    url.searchParams.set("scope", value);
    window.history.replaceState(null, "", url);
}

/** La réponse du serveur porte les deux rayons : un geste peut faire passer un livrable de l'un à l'autre. */
function refresh(data) {
    lists.value = { personal: data.personal ?? [], shared: data.shared ?? [] };
}

const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();
    const rows = lists.value[scope.value];

    return needle ? rows.filter((row) => row.title.toLocaleLowerCase().includes(needle)) : rows;
});

// ── Création ────────────────────────────────────────────────────────────────

const creating = ref(false);
const saving = ref(false);
const title = ref("");
const newScope = ref("personal");
const errors = ref({});

function openCreate() {
    title.value = "";
    // Dans le rayon qu'on regarde : on crée là où l'on cherchait.
    newScope.value = scope.value;
    errors.value = {};
    creating.value = true;
}

async function create() {
    if (saving.value) return;

    saving.value = true;
    try {
        const data = await request(props.createPath, { title: title.value, scope: newScope.value });
        if (!data?.success) {
            errors.value = data?.errors ?? {};

            return;
        }

        // Droit dans l'éditeur : un livrable se commence pour s'écrire.
        window.location.href = data.editPath;
    } finally {
        saving.value = false;
    }
}

const pageActions = computed(() =>
    props.canCreate
        ? [{ key: "create", color: "accent", icon: Plus, title: t("backend.studio.deliverables.add"), onSelect: openCreate }]
        : [],
);

// ── Gestes d'une carte ──────────────────────────────────────────────────────

const busyId = ref(null);

async function changeScope(deliverable, value) {
    busyId.value = deliverable.id;
    try {
        const data = await request(buildPath(props.scopePathTemplate, { id: deliverable.id }), { scope: value });
        if (data?.success) {
            refresh(data);
            toast.success(t(`backend.studio.deliverables.scope.moved_${value}`));
        }
    } finally {
        busyId.value = null;
    }
}

async function duplicate(deliverable) {
    busyId.value = deliverable.id;
    try {
        const data = await request(buildPath(props.duplicatePathTemplate, { id: deliverable.id }), {});
        if (data?.success) window.location.href = data.editPath;
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
        const data = await request(buildPath(props.deletePathTemplate, { id: pendingDelete.value.id }), {});
        if (data?.success) {
            refresh(data);
            toast.success(t("backend.studio.deliverables.deleted"));
            pendingDelete.value = null;
        }
    } finally {
        deleting.value = false;
    }
}

/** Le livrable dont la fenêtre des liens est ouverte. */
const linksFor = ref(null);

function actionsFor(deliverable) {
    const actions = [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("backend.studio.deliverables.open"),
            description: t("backend.studio.deliverables.open_hint"),
            href: deliverable.editPath,
        },
        {
            key: "preview",
            icon: ExternalLink,
            title: t("backend.studio.deliverables.preview"),
            description: t("backend.studio.deliverables.preview_hint"),
            onSelect: () => window.open(deliverable.previewPath, "_blank", "noopener"),
        },
    ];

    if (deliverable.canShare) {
        actions.push({
            key: "links",
            icon: Link2,
            title: t("backend.studio.deliverables.links.title"),
            description: t("backend.studio.deliverables.links_hint"),
            onSelect: () => (linksFor.value = deliverable),
        });
    }

    if (deliverable.canChangeScope) {
        const target = "personal" === deliverable.scope ? "shared" : "personal";
        actions.push({
            key: "scope",
            icon: "shared" === target ? Users : Lock,
            title: t(`backend.studio.deliverables.scope.move_${target}`),
            description: t(`backend.studio.deliverables.scope.move_${target}_hint`),
            disabled: busyId.value === deliverable.id,
            onSelect: () => changeScope(deliverable, target),
        });
    }

    if (deliverable.canDuplicate) {
        actions.push({
            key: "duplicate",
            icon: Copy,
            title: t("backend.studio.deliverables.duplicate"),
            description: t("backend.studio.deliverables.duplicate_hint"),
            disabled: busyId.value === deliverable.id,
            onSelect: () => duplicate(deliverable),
        });
    }

    if (deliverable.canDelete) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.studio.deliverables.delete_hint"),
            onSelect: () => (pendingDelete.value = deliverable),
        });
    }

    return actions;
}
</script>

<template>
    <div class="aurora-stack">
        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('backend.studio.deliverables.search_placeholder')" />
            <template #actions>
                <AppPageActions v-if="pageActions.length" :actions="pageActions" class="w-full sm:w-auto" />
            </template>
        </AppListToolbar>

        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('backend.studio.deliverables.studio_guide.title')" storage-key="studio-deliverables">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`backend.studio.deliverables.studio_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Les deux rayons, en pastilles avec leur compte, comme les filtres
             des autres listes de Studio. -->
        <div class="flex w-full flex-col p-1 bg-surface-2 border border-line rounded-lg gap-1 sm:inline-flex sm:w-auto sm:flex-row sm:self-start">
            <AppTab
                v-for="value in SCOPES"
                :key="value"
                size="sm"
                class="justify-between sm:flex-none sm:justify-start"
                :active="scope === value"
                active-class="bg-surface text-primary shadow-sm"
                inactive-class="text-secondary hover:text-primary"
                v-on:click="setScope(value)"
            >
                <span class="inline-flex items-center gap-1.5">
                    <component :is="'shared' === value ? Users : Lock" class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t(`backend.studio.deliverables.scope.tab_${value}`) }}
                </span>
                <span class="ml-1 text-xs text-muted">{{ lists[value].length }}</span>
            </AppTab>
        </div>

        <p class="m-0 text-xs text-muted sm:max-w-xl">{{ t(`backend.studio.deliverables.scope.intro_${scope}`) }}</p>

        <AppNoData
            v-if="!visible.length"
            :message="lists[scope].length
                ? t('backend.studio.deliverables.no_match')
                : t(`backend.studio.deliverables.scope.empty_${scope}`)"
            :hint="lists[scope].length ? '' : t(`backend.studio.deliverables.scope.empty_${scope}_hint`)"
        />

        <DeliverableCards v-else :deliverables="visible" :actions-for="actionsFor">
            <template #meta="{ deliverable }">
                <AppBadge v-if="'shared' === deliverable.scope" color="sky">
                    {{ deliverable.ownerName
                        ? t("backend.studio.deliverables.scope.by", { name: deliverable.ownerName })
                        : t("backend.studio.deliverables.scope.no_owner") }}
                </AppBadge>
                <AppBadge v-else-if="!deliverable.ownerName" color="amber">
                    {{ t("backend.studio.deliverables.scope.orphan") }}
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
            :title="t('backend.studio.deliverables.create_title')"
            :icon="Plus"
            v-on:close="creating = false"
        >
            <form class="space-y-4" v-on:submit.prevent="create">
                <AppInput
                    v-model="title"
                    autofocus
                    :label="t('backend.studio.deliverables.title')"
                    :placeholder="t('backend.studio.deliverables.title_placeholder')"
                    :hint="t('backend.studio.deliverables.studio_title_hint')"
                    :error="errors.title ?? ''"
                />
                <fieldset class="m-0 space-y-2 border-0 p-0">
                    <legend class="mb-1.5 text-sm font-medium text-primary">{{ t("backend.studio.deliverables.scope.label") }}</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="value in SCOPES"
                            :key="value"
                            type="button"
                            class="rounded-lg border p-3 text-left transition-colors"
                            :class="newScope === value ? 'border-accent bg-accent/10' : 'border-line hover:border-line-strong'"
                            :aria-pressed="newScope === value"
                            v-on:click="newScope = value"
                        >
                            <span class="flex items-center gap-1.5 text-sm font-medium text-primary">
                                <component :is="'shared' === value ? Users : Lock" class="h-3.5 w-3.5" :stroke-width="2" />
                                {{ t(`backend.studio.deliverables.scope.${value}`) }}
                            </span>
                            <span class="mt-0.5 block text-xs text-muted">{{ t(`backend.studio.deliverables.scope.${value}_hint`) }}</span>
                        </button>
                    </div>
                </fieldset>
            </form>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="creating = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="create">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.studio.deliverables.create") }}
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
