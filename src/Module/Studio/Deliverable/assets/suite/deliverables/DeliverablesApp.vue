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
 *
 * Les livrables de Studio se rangent par catégorie (audit, stratégie...) :
 * un filtre à côté des rayons, comme celui des métiers sur les trames, et un
 * affichage par catégorie, en sections, ou en simple liste. Le filtre et
 * l'affichage vont dans l'adresse, comme le rayon : un lien rouvre la même vue.
 *
 * Un livrable peut être un modèle : un badge sur sa carte, le filtre
 * « Modèles » à côté des catégories, et « Partir d'un modèle » dans la fenêtre
 * de création. Le client pour qui il a été écrit, quand il en a un, se lit sur
 * la carte.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, FolderInput, Layers, LayoutTemplate, Link2, List, Lock, Pencil, Plus, Presentation, Tags, Trash2, Users, X } from "lucide-vue-next";
import { useQueryState } from "@/shared/composables/useQueryState.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { queueFlash } from "@/shared/utils/flash.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCategoriesModal from "@/shared/components/category/AppCategoriesModal.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import DeliverableCards from "./components/DeliverableCards.vue";
import DeliverableCopyToSpaceModal from "./components/DeliverableCopyToSpaceModal.vue";
import DeliverableDeleteModal from "./components/DeliverableDeleteModal.vue";
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
    /** La route qui rend les deux rayons à jour, pour une liste devenue périmée. */
    listsPath: { type: String, default: "" },
    createPath: { type: String, required: true },
    scopePathTemplate: { type: String, required: true },
    duplicatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    linksPathTemplate: { type: String, required: true },
    copyToSpacePathTemplate: { type: String, default: "" },
    /** Les espaces où déposer une copie ; vide, le geste ne s'affiche pas. */
    copyTargets: { type: Array, default: () => [] },
    /** Les catégories, dans l'ordre choisi : `{ id, name, color, position }`. */
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
 * Le rayon ouvert va dans l'adresse : revenir d'un livrable rouvre le même.
 * Comme tous les états de liste, avec `useQueryState` : le rayon par défaut
 * n'y est pas écrit, et l'historique du navigateur est conservé.
 */
const { value: scopeQuery, set: setScope } = useQueryState("scope", { defaultValue: "personal", valid: SCOPES });

// Sans rayon dans l'adresse, celui que le serveur a choisi pour la page : il
// lit l'adresse lui aussi, donc cela ne compte que pour un écran monté ailleurs.
if (!new URLSearchParams(window.location.search).has("scope") && SCOPES.includes(props.initialScope)) {
    scopeQuery.value = props.initialScope;
}
const scope = computed(() => (SCOPES.includes(scopeQuery.value) ? scopeQuery.value : "personal"));

const categories = ref([...props.categories]);

/**
 * La réponse du serveur porte les deux rayons : un geste peut faire passer un
 * livrable de l'un à l'autre. Celle d'une catégorie porte aussi la liste des
 * catégories, renommée ou rangée.
 */
function refresh(data) {
    lists.value = { personal: data.personal ?? [], shared: data.shared ?? [] };
    if (Array.isArray(data.categories)) categories.value = data.categories;
}

const { send } = useDeliverableRequest({ listPath: props.listsPath, onList: refresh });

// ── Catégories : filtre et affichage ────────────────────────────────────────

const NO_CATEGORY = "none";

const { value: categoryQuery, set: setCategory } = useQueryState("category", { defaultValue: "" });

/**
 * Le filtre en vigueur : celui de l'adresse, s'il désigne encore une catégorie.
 * Une catégorie supprimée (depuis la fenêtre, ou un vieux lien) ne doit pas
 * laisser une liste vide sans raison : le filtre retombe sur « Toutes ».
 */
const categoryFilter = computed(() => {
    const value = categoryQuery.value;
    const known = NO_CATEGORY === value || categories.value.some((category) => String(category.id) === value);

    return known ? value : "";
});
const { value: layout, set: setLayout } = useQueryState("layout", { defaultValue: "grouped", valid: ["grouped", "flat"] });

/** Désélectionner rend null, et le filtre parle en chaînes. */
function setCategoryFilter(value) {
    setCategory(null === value || undefined === value ? "" : String(value));
}

/** Les modèles seuls, ou tout : dans l'adresse comme les autres filtres. */
const { value: templatesQuery, set: setTemplatesQuery } = useQueryState("templates", { defaultValue: "", valid: ["1"] });
const templatesOnly = computed(() => "1" === templatesQuery.value);

function toggleTemplatesOnly() {
    setTemplatesQuery(templatesOnly.value ? "" : "1");
}

/** Combien de modèles dans le rayon ouvert, pour le compte du filtre. */
const templateCount = computed(() => lists.value[scope.value].filter((row) => row.template).length);

/** La recherche porte sur le titre, le résumé et le client, dans le rayon ouvert. */
const searched = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();
    const rows = templatesOnly.value ? lists.value[scope.value].filter((row) => row.template) : lists.value[scope.value];

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

/** Les comptes du filtre, sur le rayon ouvert et la recherche en cours. */
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

/** Par catégorie, seulement quand il y en a : sinon, une seule section ne dirait rien. */
const grouped = computed(() => "grouped" === layout.value && categories.value.length > 0);

/** Les sections, dans l'ordre des catégories, « Sans catégorie » à la fin ; les vides se taisent. */
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

/** Ce que la page affiche : les sections, ou une seule, sans titre, en liste simple. */
const sections = computed(() => (grouped.value ? groups.value : [{ key: "all", name: null, color: null, rows: visible.value }]));

const categorySelectOptions = computed(() => categoryOptions(categories.value));

const managingCategories = ref(false);

// ── Création ────────────────────────────────────────────────────────────────

const creating = ref(false);
const saving = ref(false);
const title = ref("");
const newScope = ref("personal");
const newCategory = ref("");
const newTemplate = ref("");
/** Une page ou une présentation : décidé ici, une fois pour toutes. */
const newFormat = ref("page");
const errors = ref({});

const formatOptions = computed(() =>
    ["page", "slides"].map((value) => ({ value, label: t(`suite.studio.deliverables.formats.${value}`) })),
);

/**
 * Les modèles des deux rayons, du format choisi : on part d'un modèle partagé
 * comme d'un des siens, mais pas d'une page pour écrire une présentation.
 */
const templateSelectOptions = computed(() => templateOptions([...lists.value.personal, ...lists.value.shared], newFormat.value));

/** Ce qu'est le format choisi, et qu'il ne changera plus. */
const formatHint = computed(() =>
    [
        "slides" === newFormat.value
            ? t("suite.studio.deliverables.format.slides_hint")
            : t("suite.studio.deliverables.format.page_hint"),
        t("suite.studio.deliverables.format.fixed_hint"),
    ].join(" "),
);

/** Sans modèle, on part de rien : une page blanche ou une présentation vide. */
const templatePlaceholder = computed(() =>
    "slides" === newFormat.value
        ? t("suite.studio.deliverables.template.from_nothing_slides")
        : t("suite.studio.deliverables.template.from_nothing"),
);

// Changer de format oublie un modèle de l'autre format.
watch(newFormat, () => {
    if (!templateSelectOptions.value.some((option) => String(option.value) === String(newTemplate.value))) newTemplate.value = "";
});

// Choisir un modèle range le nouveau livrable dans sa catégorie : c'est ce
// qu'il en reprend, et le sélecteur reste là pour en changer.
watch(newTemplate, (value) => {
    const chosen = templateSelectOptions.value.find((option) => String(option.value) === String(value));
    if (chosen) newCategory.value = null === chosen.categoryId ? "" : chosen.categoryId;
});

function openCreate() {
    title.value = "";
    newTemplate.value = "";
    newFormat.value = "page";
    // Dans le rayon qu'on regarde : on crée là où l'on cherchait.
    newScope.value = scope.value;
    // La catégorie qu'on filtre, si c'en est une : on crée là où l'on regardait.
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

        // Droit dans l'éditeur : un livrable se commence pour s'écrire.
        window.location.href = data.editPath;
    } finally {
        saving.value = false;
    }
}

const pageActions = computed(() => {
    const actions = [];
    if (props.canCreate) {
        actions.push({ key: "create", color: "accent", icon: Plus, title: t("suite.studio.deliverables.add"), onSelect: openCreate });
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

        // Réussi ou refusé (déjà supprimé par un collègue), la fenêtre se
        // ferme : la liste a été redessinée d'un côté ou de l'autre.
        pendingDelete.value = null;
    } finally {
        deleting.value = false;
    }
}

/** Le livrable dont la fenêtre des liens est ouverte. */
const linksFor = ref(null);

/** Le livrable qu'on recopie dans un espace client. */
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
            title: t("suite.studio.deliverables.links.title"),
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

    // Une présentation reste dans Studio pour l'instant : pas de copie vers un espace.
    if (props.copyTargets.length && props.copyToSpacePathTemplate && "slides" !== deliverable.format) {
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
        <AppListToolbar>
            <AppSearchInput v-model="search" :placeholder="t('suite.studio.deliverables.search_placeholder')" />
            <!-- Par catégorie ou en liste : le même interrupteur que la vue
                 des autres listes, à côté de la recherche. Sans catégorie, il
                 n'y a rien à choisir. Caché sur téléphone, comme celui des
                 trames : empilé sous la recherche, il s'étirait sur toute une
                 ligne pour deux icônes collées à gauche. -->
            <template v-if="categories.length" #inline>
                <div class="hidden shrink-0 border border-line rounded-lg p-0.5 sm:flex">
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
            </template>
            <template #actions>
                <AppPageActions v-if="pageActions.length" :actions="pageActions" class="w-full sm:w-auto" />
            </template>
        </AppListToolbar>

        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide :title="t('suite.studio.deliverables.studio_guide.title')" storage-key="studio-deliverables">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 6" :key="step">{{ t(`suite.studio.deliverables.studio_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- Les rayons et la phrase qui dit ce qu'est celui qu'on regarde :
             un seul bloc, l'espace de la page vient après, avant les cartes. -->
        <div class="flex flex-col gap-2">
            <!-- Les rayons, puis le filtre des catégories : empilés sur un
                 téléphone, côte à côte dès `sm`, comme sur les trames. -->
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <!-- Les deux rayons, en pastilles avec leur compte, comme les filtres
                 des autres listes de Studio. -->
                <div
                    class="flex w-full flex-col p-1 bg-surface-2 border border-line rounded-lg gap-1 sm:inline-flex sm:w-auto sm:flex-row sm:self-start"
                    role="tablist"
                    :aria-label="t('suite.studio.deliverables.scope.label')"
                >
                    <AppTab
                        v-for="value in SCOPES"
                        :key="value"
                        role="tab"
                        :aria-selected="scope === value ? 'true' : 'false'"
                        size="sm"
                        class="justify-between sm:flex-none sm:justify-start"
                        :active="scope === value"
                        active-class="bg-surface text-primary shadow-sm"
                        inactive-class="text-secondary hover:text-primary"
                        v-on:click="setScope(value)"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <component :is="'shared' === value ? Users : Lock" class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t(`suite.studio.deliverables.scope.tab_${value}`) }}
                        </span>
                        <span class="ml-1 text-xs text-muted">{{ lists[value].length }}</span>
                    </AppTab>
                </div>

                <!-- « Toutes » en tête et « Sans catégorie » en dernier, avec leur
                 compte : un filtre qui mène à une liste vide se voit avant le
                 clic. -->
                <AppMultiselect
                    v-if="categories.length"
                    :model-value="categoryFilter"
                    :options="categoryFilterOptions"
                    :allow-empty="true"
                    :placeholder="t('suite.studio.deliverables.categories.all')"
                    class="w-full sm:w-auto sm:min-w-48"
                    v-on:update:model-value="setCategoryFilter"
                />

                <!-- Les modèles seuls : une pastille qu'on enfonce, avec son
                     compte, comme les rayons. Visible même à zéro quand le
                     filtre est allumé, pour pouvoir l'éteindre. -->
                <div
                    v-if="templateCount || templatesOnly"
                    class="flex w-full p-1 bg-surface-2 border border-line rounded-lg sm:inline-flex sm:w-auto sm:self-start"
                >
                    <AppTab
                        size="sm"
                        class="flex-1 justify-between sm:flex-none sm:justify-start"
                        :active="templatesOnly"
                        :aria-pressed="templatesOnly ? 'true' : 'false'"
                        :title="t('suite.studio.deliverables.template.filter_hint')"
                        active-class="bg-surface text-primary shadow-sm"
                        inactive-class="text-secondary hover:text-primary"
                        v-on:click="toggleTemplatesOnly"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <LayoutTemplate class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("suite.studio.deliverables.template.filter") }}
                        </span>
                        <span class="ml-1 text-xs text-muted">{{ templateCount }}</span>
                    </AppTab>
                </div>
            </div>

            <p class="m-0 text-xs text-muted sm:max-w-xl">{{ t(`suite.studio.deliverables.scope.intro_${scope}`) }}</p>
        </div>

        <AppNoData
            v-if="!visible.length"
            :message="lists[scope].length
                ? t('suite.studio.deliverables.no_match')
                : t(`suite.studio.deliverables.scope.empty_${scope}`)"
            :hint="lists[scope].length || !canCreate ? '' : t(`suite.studio.deliverables.scope.empty_${scope}_hint`)"
        />

        <!-- Une section par catégorie, ou une seule sans titre en liste
             simple : les mêmes cartes, les mêmes gestes. -->
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
                <!-- Le format d'abord, puis le modèle : ce sont les deux
                     questions qui décident de tout ce qui suit, et les modèles
                     proposés sont ceux du format choisi. -->
                <AppChoiceRow
                    v-model="newFormat"
                    :label="t('suite.studio.deliverables.format.label')"
                    :hint="formatHint"
                    :options="formatOptions"
                />
                <p v-if="errors.format" class="m-0 text-xs text-red-500">{{ t(errors.format) }}</p>
                <AppSelect
                    v-if="templateSelectOptions.length"
                    v-model="newTemplate"
                    :label="t('suite.studio.deliverables.template.from')"
                    :placeholder="templatePlaceholder"
                    :hint="newTemplate ? t('suite.studio.deliverables.template.from_hint') : ''"
                    :options="templateSelectOptions"
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

        <DeliverableDeleteModal
            :show="!!pendingDelete"
            :title="pendingDelete?.title ?? ''"
            :deleting="deleting"
            v-on:cancel="pendingDelete = null"
            v-on:confirm="doDelete"
        />
    </div>
</template>
