<script setup>
/**
 * The space's content, read by its date.
 *
 * **It draws the shared month grid**, the same component the Calendrier module
 * uses, rather than a second one. That component decides nothing about dates -
 * which days show, which bars cross which weeks, how two overlapping runs avoid
 * each other are all in `monthGrid.js`, tested without a component mounted - so
 * it takes cells and events and asks nothing about where they came from.
 *
 * The rail on the right is the half a calendar usually hides: the content that
 * has no date yet. It is the work waiting to be scheduled, and a month view
 * that only showed the scheduled half would say the week is empty when there
 * are six ideas to place in it.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useSpaceCalendar } from "./composables/useSpaceCalendar.js";
import SpaceContentItemFields from "./components/SpaceContentItemFields.vue";
import CalendarMonth from "@/shared/components/calendar/CalendarMonth.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import {
    CalendarPlus,
    ChevronLeft,
    ChevronRight,
    FileText,
    Save,
    Trash2,
    X,
} from "lucide-vue-next";

const { t, d } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    space: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    itemCreatePath: { type: String, required: true },
    itemUpdatePath: { type: String, required: true },
    itemDeletePath: { type: String, required: true },
    schedulePath: { type: String, required: true },
});

const {
    cells,
    events,
    unscheduled,
    columnOptions,
    columnNames,
    monthLabel,
    goToMonth,
    goToToday,
    moveEvent,
    openEvent,
    addOn,
    showItemForm,
    editingItem,
    itemForm,
    itemErrors,
    itemLoading,
    openItemEdit,
    submitItem,
    pendingItemDelete,
    itemDeleteLoading,
    confirmItemDelete,
    deleteItem,
} = useSpaceCalendar(props.items, props.columns, {
    itemCreatePath: props.itemCreatePath,
    itemUpdatePath: props.itemUpdatePath,
    itemDeletePath: props.itemDeletePath,
    schedulePath: props.schedulePath,
    colourSlot: props.space.colourSlot,
});

const editable = computed(() => can("studio.spaces.edit"));

const monthTitle = computed(() =>
    d(monthLabel.value, { year: "numeric", month: "long" }),
);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="rounded-md p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                    :aria-label="t('shared.common.previous')"
                    v-on:click="goToMonth(-1)"
                >
                    <ChevronLeft class="h-4 w-4" :stroke-width="2" />
                </button>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                    :aria-label="t('shared.common.next')"
                    v-on:click="goToMonth(1)"
                >
                    <ChevronRight class="h-4 w-4" :stroke-width="2" />
                </button>
                <h2 class="ml-2 text-sm font-medium capitalize text-primary">
                    {{ monthTitle }}
                </h2>
            </div>
            <AppButton variant="ghost" size="sm" v-on:click="goToToday">
                {{ t("backend.studio.space_content.today") }}
            </AppButton>
        </div>

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <div class="min-w-0 flex-1">
                <CalendarMonth
                    :cells="cells"
                    :events="events"
                    v-on:open-event="openEvent"
                    v-on:move-event="moveEvent"
                    v-on:add-on="addOn"
                />
            </div>

            <!-- Beside the grid and not under it: the two are read together,
                 one card at a time being dragged out of the list into a week. -->
            <aside
                class="w-full shrink-0 rounded-xl border border-line/60 bg-surface-2/40 lg:w-72"
            >
                <header class="flex items-center gap-2 border-b border-line/40 px-3 py-2">
                    <h3 class="min-w-0 flex-1 truncate text-sm font-medium text-primary">
                        {{ t("backend.studio.space_content.unscheduled_rail") }}
                    </h3>
                    <span class="text-xs tabular-nums text-muted">
                        {{ unscheduled.length }}
                    </span>
                </header>

                <p
                    v-if="!unscheduled.length"
                    class="px-3 py-3 text-xs text-muted"
                >
                    {{ t("backend.studio.space_content.unscheduled_rail_empty") }}
                </p>

                <ul v-else class="flex flex-col gap-2 p-2">
                    <li v-for="item in unscheduled" :key="item.id">
                        <button
                            type="button"
                            class="w-full rounded-lg border border-line/60 bg-surface px-3 py-2 text-left transition-colors hover:border-line"
                            v-on:click="openItemEdit(item)"
                        >
                            <p class="truncate text-sm font-medium text-primary">
                                {{ item.title }}
                            </p>
                            <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-muted">
                                <CalendarPlus class="h-3 w-3 shrink-0" :stroke-width="2" />
                                {{ columnNames.get(item.columnId) }}
                            </p>
                        </button>
                    </li>
                </ul>
            </aside>
        </div>

        <AppNoData
            v-if="!events.length && !unscheduled.length"
            :message="t('backend.studio.space_content.empty_board')"
        />

        <AppModal
            :show="showItemForm"
            max-width="2xl"
            :title="
                editingItem
                    ? t('backend.studio.space_content.edit_item', {
                        title: editingItem.title,
                    })
                    : t('backend.studio.space_content.create_item')
            "
            :icon="FileText"
            :closeable="false"
            v-on:close="showItemForm = false"
        >
            <form v-on:submit.prevent="submitItem">
                <SpaceContentItemFields
                    v-model="itemForm"
                    :errors="itemErrors"
                    :column-options="columnOptions"
                    :timezone="space.timezone"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton
                        v-if="editable && editingItem"
                        variant="ghost"
                        size="md"
                        v-on:click="confirmItemDelete(editingItem)"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                    <AppButton variant="ghost" size="md" v-on:click="showItemForm = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="itemLoading"
                        v-on:click="submitItem"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
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
                {{
                    t("backend.studio.space_content.delete_item_confirm", {
                        title: pendingItemDelete?.title ?? "",
                    })
                }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingItemDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="itemDeleteLoading"
                        v-on:click="deleteItem"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
