<script setup>
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useMenus } from "./composables/useMenus.js";
import { useMenuItems } from "./composables/useMenuItems.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Plus, Pencil, Trash2, Save, X, Menu, ChevronUp, ChevronDown, TriangleAlert } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    menus: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    targetTypes: { type: Array, default: () => [] },
    visibilities: { type: Array, default: () => [] },
    targetsPath: { type: String, required: true },
    updatePathTemplate: { type: String, required: true },
    itemCreatePathTemplate: { type: String, required: true },
    itemEditPathTemplate: { type: String, required: true },
    itemDeletePathTemplate: { type: String, required: true },
    itemReorderPathTemplate: { type: String, required: true },
});

const {
    items, selectedId, selected, upsert,
    showEdit, editForm, editErrors, editLoading, openEdit, submitEdit,
} = useMenus(props);

const {
    rows, labelOf, showItem, editingItem, form, itemErrors, itemLoading,
    parentOptions, targetTypeMeta, targetTypeOptions, visibilityOptions,
    targetOptions, targetSearch, targetLoading,
    openItemCreate, openItemEdit, submitItem,
    pendingItemDelete, itemDeleteLoading, deleteItem, move,
} = useMenuItems(props, selected, upsert);

// The two actions a item row offers. The reorder arrows beside them stay as they
// are: they are pressed repeatedly, and a sheet would turn each nudge into
// open-click-close.
const itemActions = useEditDeleteActions({
    can,
    editPermission: "editorial.menus.edit",
    deletePermission: "editorial.menus.edit",
    openEdit: openItemEdit,
    confirmDelete: (item) => {
        pendingItemDelete.value = item;
    },
    editDescription: "backend.menus.row_actions.edit_description",
    deleteDescription: "backend.menus.row_actions.delete_description",
});

/**
 * An entry with children but nothing to link to is a heading, not a
 * mistake - the renderer keeps it. One with neither is a mistake, and the
 * site drops it silently, so the tree says so here.
 */
function isUnresolved(item) {
    if ("custom_url" === item.targetType) return !item.customUrl;

    return item.targetId !== null && !item.targetLabel;
}
</script>

<template>
    <AppNoData
        v-if="!items.length"
        :message="t('backend.menus.empty')"
        :hint="t('backend.menus.empty_hint')"
    />

    <div v-else class="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-4">
        <aside class="space-y-2">
            <button
                v-for="menu in items"
                :key="menu.id"
                type="button"
                class="w-full text-left px-4 py-3 rounded-xl border transition-colors"
                :class="menu.id === selectedId
                    ? 'bg-surface border-accent-500/60 text-primary'
                    : 'bg-surface border-line/60 text-secondary hover:text-primary hover:bg-surface-2/40'"
                v-on:click="selectedId = menu.id"
            >
                <span class="flex items-center justify-between gap-2">
                    <span class="font-medium truncate">{{ menu.name }}</span>
                    <AppBadge v-if="!menu.locationKnown" color="amber">{{ t("backend.menus.location") }}</AppBadge>
                </span>
                <span class="block text-xs text-muted font-mono mt-0.5 truncate">{{ menu.location }}</span>
            </button>
        </aside>

        <section v-if="selected" class="space-y-4">
            <div class="bg-surface border border-line rounded-xl p-5 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-primary truncate">{{ selected.name }}</h2>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ selected.location }}</p>
                        <p v-if="selected.description" class="text-sm text-secondary mt-2">{{ selected.description }}</p>
                    </div>
                    <AppIconButton
                        v-if="can('editorial.menus.edit')"
                        color="accent"
                        :title="t('shared.common.edit')"
                        class="shrink-0"
                        v-on:click="openEdit"
                    >
                        <Pencil class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </div>

            <div class="bg-surface border border-line rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.menus.items") }}</h3>
                    <AppButton
                        v-if="can('editorial.menus.edit')"
                        variant="ghost"
                        size="sm"
                        v-on:click="openItemCreate"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.menus.add_item") }}
                    </AppButton>
                </div>

                <AppNoData v-if="!rows.length" :message="t('backend.menus.items_empty')" />

                <div class="divide-y divide-line/40">
                    <div
                        v-for="item in rows"
                        :key="item.id"
                        class="flex items-center justify-between gap-3 py-2.5 text-sm"
                    >
                        <div class="min-w-0" :style="{ paddingLeft: `${item.depth * 1.25}rem` }">
                            <p class="font-medium text-primary truncate flex items-center gap-1.5">
                                {{ labelOf(item) }}
                                <TriangleAlert
                                    v-if="isUnresolved(item)"
                                    class="w-3.5 h-3.5 text-amber-500 shrink-0"
                                    :stroke-width="2"
                                    :aria-label="t('backend.menus.unresolved')"
                                />
                            </p>
                            <p class="text-xs text-muted mt-0.5 truncate">
                                {{ t(`backend.menus.target_types.${item.targetType}`) }}
                                <span v-if="item.targetLabel"> · {{ item.targetLabel }}</span>
                                <span v-if="item.visibility !== 'always'"> · {{ t(`backend.menus.visibilities.${item.visibility}`) }}</span>
                            </p>
                        </div>
                        <div v-if="can('editorial.menus.edit')" class="flex items-center gap-0.5 shrink-0">
                            <AppIconButton :title="t('backend.menus.move_up')" v-on:click="move(item, -1)">
                                <ChevronUp class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton :title="t('backend.menus.move_down')" v-on:click="move(item, 1)">
                                <ChevronDown class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppRowActions :actions="itemActions(item)" :label="item.label ?? item.name ?? ''" />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <AppModal
            :show="showEdit"
            :title="t('shared.common.edit')"
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitEdit">
                <AppInput
                    v-model="editForm.name"
                    :label="t('backend.menus.name')"
                    :placeholder="t('shared.placeholders.name')"
                    :error="editErrors.name"
                    required
                />
                <AppTextarea
                    v-model="editForm.description"
                    :label="t('backend.menus.description')"
                    :placeholder="t('shared.placeholders.description')"
                    :error="editErrors.description"
                    :rows="2"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showItem"
            :title="editingItem ? t('backend.menus.edit_item') : t('backend.menus.add_item')"
            :icon="editingItem ? Pencil : Menu"
            :closeable="false"
            v-on:close="showItem = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitItem">
                <AppSelect
                    v-model="form.targetType"
                    :label="t('backend.menus.target_type')"
                    :options="targetTypeOptions"
                    :error="itemErrors.targetType"
                />

                <template v-if="targetTypeMeta?.requiresTarget">
                    <AppInput
                        v-model="targetSearch"
                        :label="t('shared.common.search')"
                        :placeholder="t('shared.placeholders.search')"
                        :loading="targetLoading"
                    />
                    <AppSelect
                        v-model="form.targetId"
                        :label="t('backend.menus.target')"
                        :options="targetOptions"
                        :error="itemErrors.targetId"
                    />
                </template>

                <AppInput
                    v-if="targetTypeMeta?.requiresUrl"
                    v-model="form.customUrl"
                    :label="t('backend.menus.custom_url')"
                    :placeholder="t('backend.menus.custom_url_placeholder')"
                    :error="itemErrors.customUrl"
                />

                <AppSelect
                    v-model="form.parentId"
                    :label="t('backend.menus.parent')"
                    :placeholder="t('backend.menus.no_parent')"
                    :options="parentOptions"
                    :hint="t('backend.menus.heading_hint')"
                    :error="itemErrors.parentId"
                />

                <AppSelect
                    v-model="form.visibility"
                    :label="t('backend.menus.visibility')"
                    :options="visibilityOptions"
                    :error="itemErrors.visibility"
                />

                <AppCheckbox v-model="form.openInNewTab" :label="t('backend.menus.open_in_new_tab')" />
                <AppInput
                    v-model="form.cssClass"
                    :label="t('backend.menus.css_class')"
                    :placeholder="t('backend.menus.css_class_placeholder')"
                    :error="itemErrors.cssClass"
                />

                <div v-for="locale in locales" :key="locale" class="space-y-2 border-t border-line/40 pt-3">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ locale }}</p>
                    <AppInput
                        v-model="form.translations[locale].label"
                        :label="t('backend.menus.label')"
                        :placeholder="t('backend.menus.label_placeholder')"
                        :hint="t('backend.menus.label_hint')"
                    />
                </div>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showItem = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="itemLoading" v-on:click="submitItem"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingItemDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingItemDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t("backend.menus.item_delete_confirm", { label: pendingItemDelete ? labelOf(pendingItemDelete) : "" }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingItemDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="itemDeleteLoading" v-on:click="deleteItem"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
