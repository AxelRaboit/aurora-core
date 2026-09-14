<script setup>
/**
 * The decks: what gets shown to somebody, listed.
 *
 * A deck is filed under a category and may name the customer it was written
 * for. The customer picker is empty when the customers sub-module is off, and
 * the field simply offers nothing rather than disappearing: a deck without a
 * client is the ordinary internal case, not a degraded one.
 *
 * Duplicating is a row action rather than a button inside the deck, because
 * "start from this one" is decided while looking at the list.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useDecksList } from "./composables/useDecksList.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import {
    Copy,
    FileInput,
    Pencil,
    Plus,
    Presentation,
    Save,
    Trash2,
    X,
} from "lucide-vue-next";

const { t } = useI18n();
const { container, isNarrow } = useNarrowContainer();
const { can } = usePrivileges();

const props = defineProps({
    decks: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    layouts: { type: Array, default: () => [] },
    showPath: { type: String, required: true },
    createPath: { type: String, required: true },
    importPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    duplicatePath: { type: String, required: true },
    categoryCreatePath: { type: String, required: true },
    categoryUpdatePath: { type: String, required: true },
    categoryDeletePath: { type: String, required: true },
});

const {
    search,
    categoryFilter,
    filteredItems,
    categoryOptions,
    customerOptions,
    templateOptions,
    showCreate,
    newDeck,
    createErrors,
    createLoading,
    openCreate,
    submitCreate,
    showImport,
    importDeck,
    importBlocks,
    importErrors,
    importLoading,
    openImport,
    submitImport,
    showEdit,
    editingDeck,
    editForm,
    editErrors,
    editLoading,
    openEdit,
    submitEdit,
    pendingDelete,
    deleteLoading,
    confirmDelete,
    doDelete,
    duplicatingId,
    duplicate,
} = useDecksList(props);

/**
 * Three actions, and the middle one is not an edit.
 *
 * `useEditDeleteActions` covers the two-action case; a duplicate sits between
 * them here, so the list is written out rather than borrowed.
 */
function actionsFor(deck) {
    const actions = [];

    if (can("studio.decks.edit")) {
        actions.push({
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("shared.common.edit"),
            description: t("backend.studio.decks.edit_hint"),
            onSelect: () => openEdit(deck),
        });
    }

    if (can("studio.decks.create")) {
        actions.push({
            key: "duplicate",
            icon: Copy,
            title: t("backend.studio.decks.duplicate"),
            description: t("backend.studio.decks.duplicate_hint"),
            disabled: duplicatingId.value === deck.id,
            onSelect: () => duplicate(deck),
        });
    }

    // Last, as everywhere: the one that takes something away is read after the
    // ones that do not.
    if (can("studio.decks.delete")) {
        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.studio.decks.delete_hint"),
            onSelect: () => confirmDelete(deck),
        });
    }

    return actions;
}

/**
 * What the page offers, as opposed to what one deck offers.
 *
 * Both entries need the same privilege - importing a document produces a deck
 * like creating one does - so the list is empty or whole, never half.
 * Creating comes first and carries the accent: it is what somebody arriving on
 * an empty list is looking for.
 */
const pageActions = computed(() => {
    if (!can("studio.decks.create")) {
        return [];
    }

    return [
        {
            key: "create",
            color: "accent",
            icon: Plus,
            title: t("backend.studio.decks.create"),
            onSelect: openCreate,
        },
        {
            key: "import",
            icon: FileInput,
            title: t("backend.studio.decks.import"),
            onSelect: openImport,
        },
    ];
});

const filterOptions = () => categoryOptions.value;

const deckUrl = (deck) => buildPath(props.showPath, { id: deck.id });
</script>

<template>
    <div ref="container" class="space-y-4">
        <AppListToolbar>
            <AppSearchInput
                v-model="search"
                :placeholder="t('backend.studio.decks.search_placeholder')"
            />
            <!-- `inline` plutôt qu'une ligne à part : le filtre appartient à la
                 recherche, il ne se lit pas comme une seconde barre. -->
            <template #inline>
                <AppSelect
                    v-model="categoryFilter"
                    :options="filterOptions()"
                    :placeholder="t('backend.studio.decks.all_categories')"
                />
            </template>
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>

        <AppNoData
            v-if="!filteredItems.length"
            :icon="Presentation"
            :title="t('backend.studio.decks.empty_title')"
            :description="t('backend.studio.decks.empty_description')"
        />

        <div v-else-if="!isNarrow" class="overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-xs uppercase tracking-wide text-muted">
                        <th class="px-6 py-3 text-left font-medium">{{ t("backend.studio.decks.title_column") }}</th>
                        <th class="hidden px-6 py-3 text-left font-medium lg:table-cell">{{ t("backend.studio.decks.category") }}</th>
                        <th class="hidden px-6 py-3 text-left font-medium lg:table-cell">{{ t("backend.studio.decks.customer") }}</th>
                        <th class="px-6 py-3 text-right font-medium">{{ t("backend.studio.decks.slides") }}</th>
                        <th class="px-6 py-3 text-right font-medium sticky right-0 bg-surface-2 border-l border-line/40">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="deck in filteredItems"
                        :key="deck.id"
                        class="border-b border-line/60 last:border-0 hover:bg-surface-2/50"
                    >
                        <td class="px-6 py-3">
                            <!-- Le titre est le lien vers la page du deck : c'est
                                 ce qu'on vise pour composer les slides, et une
                                 action de plus dans le menu de ligne aurait mis
                                 le geste principal derrière un clic. -->
                            <a
                                class="block font-medium text-primary no-underline hover:text-accent"
                                :href="deckUrl(deck)"
                            >{{ deck.title }}</a>
                            <!-- Le badge sur la ligne plutôt qu'un filtre de
                                 plus : un modèle se reconnaît en passant, et
                                 la liste en porte trois, pas trente. -->
                            <span
                                v-if="deck.isTemplate"
                                class="mt-0.5 inline-flex items-center gap-1 rounded-full border border-accent/40 bg-accent-600/10 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-accent"
                            >
                                {{ t("backend.studio.decks.template_badge") }}
                            </span>
                            <span v-if="deck.description" class="block text-xs text-muted line-clamp-1">{{ deck.description }}</span>
                        </td>
                        <td class="hidden px-6 py-3 lg:table-cell">
                            <span
                                v-if="deck.category"
                                class="inline-flex items-center gap-1.5 rounded-full border border-line px-2 py-0.5 text-xs"
                            >
                                <span
                                    v-if="deck.category.color"
                                    class="h-2 w-2 rounded-full"
                                    :style="{ backgroundColor: deck.category.color }"
                                />
                                {{ deck.category.name }}
                            </span>
                            <span v-else class="text-xs text-muted">{{ t("backend.studio.decks.uncategorised") }}</span>
                        </td>
                        <td class="hidden px-6 py-3 text-secondary lg:table-cell">
                            {{ deck.customer?.legalName ?? "—" }}
                        </td>
                        <td class="px-6 py-3 text-right tabular-nums text-secondary">{{ deck.slideCount }}</td>
                        <td class="px-6 py-3 text-right sticky right-0 bg-surface border-l border-line/40">
                            <AppRowActions :actions="actionsFor(deck)" :label="deck.title" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- La même ligne, lue de haut en bas. Le titre reste le lien vers la
             présentation, et les actions sont dépliées : une carte a la place,
             et un menu dans un menu sur un téléphone est un geste de trop. -->
        <div v-else class="space-y-2">
            <article
                v-for="deck in filteredItems"
                :key="deck.id"
                class="rounded-lg border border-line bg-surface p-3 space-y-2.5"
            >
                <div>
                    <a
                        class="block font-medium text-primary no-underline hover:text-accent"
                        :href="deckUrl(deck)"
                    >{{ deck.title }}</a>
                    <span v-if="deck.description" class="block text-xs text-muted">{{ deck.description }}</span>
                </div>

                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                    <span
                        v-if="deck.category"
                        class="inline-flex items-center gap-1.5 rounded-full border border-line px-2 py-0.5"
                    >
                        <span
                            v-if="deck.category.color"
                            class="h-2 w-2 rounded-full"
                            :style="{ backgroundColor: deck.category.color }"
                        />
                        {{ deck.category.name }}
                    </span>
                    <span v-else>{{ t("backend.studio.decks.uncategorised") }}</span>
                    <span v-if="deck.customer" class="text-secondary">{{ deck.customer.legalName }}</span>
                    <span class="tabular-nums">{{ t("backend.studio.decks.slides") }} : {{ deck.slideCount }}</span>
                </p>

                <div class="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-line/40 pt-2">
                    <AppButton
                        v-for="action in actionsFor(deck)"
                        :key="action.key"
                        variant="ghost"
                        size="sm"
                        :href="action.href"
                        v-on:click="action.onSelect?.()"
                    >
                        <component :is="action.icon" v-if="action.icon" class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ action.title }}
                    </AppButton>
                </div>
            </article>
        </div>

        <AppModal
            :show="showCreate"
            max-width="lg"
            :closeable="false"
            :title="t('backend.studio.decks.create')"
            :icon="Presentation"
            v-on:close="showCreate = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <!-- Le modèle en premier : c'est la question qui décide de
                     tout ce qui suit, et on n'a pas envie de la découvrir
                     après avoir tapé un titre. -->
                <AppSelect
                    v-if="templateOptions.length"
                    v-model="newDeck.fromTemplateId"
                    :options="templateOptions"
                    :label="t('backend.studio.decks.from_template')"
                    :placeholder="t('backend.studio.decks.from_nothing')"
                />
                <AppInput
                    v-model="newDeck.title"
                    :label="t('backend.studio.decks.title_column')"
                    :placeholder="t('backend.studio.decks.title_placeholder')"
                    :error="createErrors.title ?? ''"
                    required
                />
                <AppTextarea
                    v-model="newDeck.description"
                    :label="t('backend.studio.decks.description')"
                    :placeholder="t('backend.studio.decks.description_placeholder')"
                    :rows="2"
                />
                <AppSelect
                    v-model="newDeck.categoryId"
                    :options="categoryOptions"
                    :label="t('backend.studio.decks.category')"
                    :placeholder="t('backend.studio.decks.uncategorised')"
                />
                <AppSelect
                    v-if="customerOptions.length"
                    v-model="newDeck.customerId"
                    :options="customerOptions"
                    :label="t('backend.studio.decks.customer')"
                    :placeholder="t('backend.studio.decks.no_customer')"
                />
                <AppToggle
                    v-model="newDeck.isTemplate"
                    :label="t('backend.studio.decks.is_template')"
                    :hint="t('backend.studio.decks.is_template_hint')"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="createLoading" v-on:click="submitCreate">
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showImport"
            max-width="4xl"
            :closeable="false"
            :title="t('backend.studio.decks.import')"
            :icon="FileInput"
            v-on:close="showImport = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitImport">
                <p class="m-0 text-sm text-secondary">
                    {{ t("backend.studio.decks.import_intro") }}
                </p>

                <AppInput
                    v-model="importDeck.title"
                    :label="t('backend.studio.decks.title_column')"
                    :placeholder="t('backend.studio.decks.title_placeholder')"
                    :error="importErrors.title ?? ''"
                    required
                />

                <AppSelect
                    v-model="importDeck.categoryId"
                    :options="categoryOptions"
                    :label="t('backend.studio.decks.category')"
                    :placeholder="t('backend.studio.decks.uncategorised')"
                />

                <div class="space-y-1.5">
                    <span class="text-xs uppercase tracking-wide text-muted">
                        {{ t("backend.studio.decks.import_document") }}
                    </span>
                    <!-- Le document vit dans la modale et n'est jamais
                         enregistré : ce qui est gardé, ce sont les slides qu'il
                         produit. Un brouillon conservé à côté du deck serait
                         une seconde version du même texte, et la question de
                         savoir laquelle fait foi. -->
                    <div class="max-h-96 overflow-y-auto rounded-lg border border-line bg-surface-2 p-2">
                        <AppBlockEditor
                            v-model="importBlocks"
                            :placeholder="t('backend.studio.decks.import_placeholder')"
                        />
                    </div>
                    <p class="m-0 text-xs text-muted">
                        {{ t("backend.studio.decks.import_hint") }}
                    </p>
                    <p v-if="importErrors.blocks" class="m-0 text-xs text-rose-400">
                        {{ t(importErrors.blocks) }}
                    </p>
                </div>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showImport = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="importLoading"
                        v-on:click="submitImport"
                    >
                        <FileInput class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.decks.import_submit") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showEdit"
            max-width="lg"
            :closeable="false"
            :title="editingDeck?.title ?? ''"
            :icon="Pencil"
            v-on:close="showEdit = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitEdit">
                <AppInput
                    v-model="editForm.title"
                    :label="t('backend.studio.decks.title_column')"
                    :placeholder="t('backend.studio.decks.title_placeholder')"
                    :error="editErrors.title ?? ''"
                    required
                />
                <AppTextarea
                    v-model="editForm.description"
                    :label="t('backend.studio.decks.description')"
                    :placeholder="t('backend.studio.decks.description_placeholder')"
                    :rows="2"
                />
                <AppSelect
                    v-model="editForm.categoryId"
                    :options="categoryOptions"
                    :label="t('backend.studio.decks.category')"
                    :placeholder="t('backend.studio.decks.uncategorised')"
                />
                <AppSelect
                    v-if="customerOptions.length"
                    v-model="editForm.customerId"
                    :options="customerOptions"
                    :label="t('backend.studio.decks.customer')"
                    :placeholder="t('backend.studio.decks.no_customer')"
                />
                <AppToggle
                    v-model="editForm.isTemplate"
                    :label="t('backend.studio.decks.is_template')"
                    :hint="t('backend.studio.decks.is_template_hint')"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit">
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
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
                {{ t("backend.studio.decks.delete_confirm", { title: pendingDelete?.title }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
