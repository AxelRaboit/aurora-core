<script setup>
/**
 * The list of client spaces.
 *
 * A list and not yet a page per space: this lot gives the space a row, a team
 * and a colour, and the tabbed page it will open onto arrives with the content
 * that has to go in it. Shipping the shell first would have been a click that
 * leads to an empty screen.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useCustomerSpacesForm } from "./composables/useCustomerSpacesForm.js";
import CustomerSpaceFormFields from "./components/CustomerSpaceFormFields.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import { PanelsTopLeft, Pencil, Plus, Save, Trash2, X } from "lucide-vue-next";

const { t } = useI18n();
const { container, isNarrow } = useNarrowContainer();
const { can } = usePrivileges();

const props = defineProps({
    spaces: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

const {
    search,
    visibleItems,
    showArchived,
    archivedCount,
    customerOptions,
    showCreate,
    newSpace,
    createErrors,
    createLoading,
    openCreate,
    submitCreate,
    showEdit,
    editingSpace,
    editForm,
    editErrors,
    editLoading,
    openEdit,
    submitEdit,
    pendingDelete,
    deleteLoading,
    confirmDelete,
    doDelete,
} = useCustomerSpacesForm(
    props.spaces,
    props.customers,
    props.users,
    props.createPath,
    props.updatePath,
    props.deletePath,
);

const actionsFor = useEditDeleteActions({
    can,
    editPermission: "studio.spaces.edit",
    deletePermission: "studio.spaces.delete",
    openEdit,
    confirmDelete,
    editDescription: "backend.studio.spaces.row_actions.edit_description",
    deleteDescription: "backend.studio.spaces.row_actions.delete_description",
});

/**
 * Three names, then a count.
 *
 * A team of eight rendered in a table cell is a wall of text nobody reads, and
 * past three the names stop being distinguishable at a glance anyway.
 */
const MAX_NAMES = 3;

function teamLabel(space) {
    const members = space.members ?? [];

    if (!members.length) return t("backend.studio.spaces.no_members");

    const names = members.slice(0, MAX_NAMES).map((member) => member.name);

    if (members.length <= MAX_NAMES) return names.join(", ");

    return `${names.join(", ")} +${members.length - MAX_NAMES}`;
}

const pageActions = computed(() => {
    if (!can("studio.spaces.create")) {
        return [];
    }

    return [
        {
            key: "create",
            color: "accent",
            icon: Plus,
            title: t("backend.studio.spaces.add"),
            onSelect: openCreate,
        },
    ];
});
</script>

<template>
    <div ref="container" class="space-y-4">
        <AppListToolbar>
            <AppSearchInput
                v-model="search"
                :placeholder="t('backend.studio.spaces.search_placeholder')"
            />
            <template #actions>
                <AppPageActions
                    v-if="pageActions.length"
                    :actions="pageActions"
                    class="w-full sm:w-auto"
                />
            </template>
        </AppListToolbar>

        <!-- Only offered when there is something to reveal: a switch that does
             nothing is a switch people learn to distrust. -->
        <AppCheckbox
            v-if="archivedCount"
            v-model="showArchived"
            :label="t('backend.studio.spaces.show_archived')"
        />

        <!-- Mobile cards -->
        <div v-if="isNarrow" class="space-y-2">
            <AppNoData
                v-if="!visibleItems.length"
                :message="t('backend.studio.spaces.empty')"
                :hint="t('backend.studio.spaces.empty_hint')"
            />
            <div
                v-for="space in visibleItems"
                :key="space.id"
                class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm"
            >
                <div class="flex items-start gap-3 px-4 py-3">
                    <span
                        class="mt-1 w-2.5 h-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: `var(--chart-cat-${space.colourSlot})` }"
                    />
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium text-primary text-sm">
                            {{ space.name }}
                            <span
                                v-if="space.archived"
                                class="ml-1 text-xs font-normal text-muted"
                            >
                                · {{ t("backend.studio.spaces.archived_badge") }}
                            </span>
                        </p>
                        <p class="text-xs text-secondary">{{ space.customerName }}</p>
                        <p class="text-xs text-muted">{{ teamLabel(space) }}</p>
                    </div>
                </div>
                <div
                    class="flex justify-end px-3 py-2 border-t border-line/40 bg-surface-2/40"
                >
                    <AppRowActions
                        :actions="actionsFor(space)"
                        :label="space.name ?? ''"
                    />
                </div>
            </div>
        </div>

        <!-- Desktop table -->
        <div
            v-else
            class="bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("backend.studio.spaces.col_space") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted"
                        >
                            {{ t("backend.studio.spaces.col_customer") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell"
                        >
                            {{ t("backend.studio.spaces.col_team") }}
                        </th>
                        <th
                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell"
                        >
                            {{ t("backend.studio.spaces.col_status") }}
                        </th>
                        <th
                            class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted sticky right-0 bg-surface-2 border-l border-line/40"
                        >
                            {{ t("shared.common.actions") }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="space in visibleItems"
                        :key="space.id"
                        class="group hover:bg-surface-2/40 transition-colors"
                        :class="space.archived ? 'opacity-60' : ''"
                    >
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="w-2.5 h-2.5 shrink-0 rounded-full"
                                    :style="{
                                        backgroundColor: `var(--chart-cat-${space.colourSlot})`,
                                    }"
                                />
                                <div class="min-w-0">
                                    <div class="font-medium text-primary truncate">
                                        {{ space.name }}
                                    </div>
                                    <div
                                        v-if="space.description"
                                        class="text-xs text-muted truncate"
                                    >
                                        {{ space.description }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-3 text-primary">{{ space.customerName }}</td>
                        <td class="px-6 py-3 text-muted hidden lg:table-cell">
                            {{ teamLabel(space) }}
                        </td>
                        <td class="px-6 py-3 hidden md:table-cell">
                            <span
                                v-if="space.archived"
                                class="text-xs text-muted"
                            >
                                {{ t("backend.studio.spaces.statuses.archived") }}
                            </span>
                            <span v-else class="text-xs text-primary">
                                {{ t("backend.studio.spaces.statuses.active") }}
                            </span>
                        </td>
                        <td class="px-6 py-3 sticky right-0 bg-surface border-l border-line/40">
                            <div class="flex items-center justify-end gap-0.5">
                                <AppRowActions
                                    :actions="actionsFor(space)"
                                    :label="space.name ?? ''"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!visibleItems.length">
                        <td :colspan="5">
                            <AppNoData
                                :message="t('backend.studio.spaces.empty')"
                                :hint="t('backend.studio.spaces.empty_hint')"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AppModal
            :show="showCreate"
            max-width="2xl"
            :title="t('backend.studio.spaces.create')"
            :icon="PanelsTopLeft"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form v-on:submit.prevent="submitCreate">
                <CustomerSpaceFormFields
                    v-model="newSpace"
                    :errors="createErrors"
                    :customer-options="customerOptions"
                    :users="users"
                    :statuses="statuses"
                    :roles="roles"
                    :timezones="timezones"
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
            :show="showEdit"
            max-width="2xl"
            :title="
                t('backend.studio.spaces.edit', { name: editingSpace?.name ?? '' })
            "
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form v-on:submit.prevent="submitEdit">
                <CustomerSpaceFormFields
                    v-model="editForm"
                    :errors="editErrors"
                    :customer-options="customerOptions"
                    :users="users"
                    :statuses="statuses"
                    :roles="roles"
                    :timezones="timezones"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="editLoading"
                        v-on:click="submitEdit"
                    >
                        <Save class="w-3.5 h-3.5" :stroke-width="2" />
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
                {{
                    t("backend.studio.spaces.delete_confirm", {
                        name: pendingDelete?.name ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.spaces.delete_warning") }}
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
                        :loading="deleteLoading"
                        v-on:click="doDelete"
                    >
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
