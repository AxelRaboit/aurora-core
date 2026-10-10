<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppLink from "@/shared/components/nav/AppLink.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { Trash2, RotateCcw, Flame, X } from "lucide-vue-next";
import { useTrashOverview } from "@general/suite/trash/composables/useTrashOverview.js";

/**
 * Everything that was deleted, in one place, whatever module it came from.
 *
 * Names no module and imports none: each row comes from a
 * TrashSourceInterface on the PHP side, icon, label and action addresses
 * included. A module that is switched off, or one the reader may not open,
 * contributes no tab at all. The tabs come in two rows: the modules holding
 * something, then the types of the open one.
 *
 * The actions post to those addresses, which are the module's own endpoints.
 * Restoring a document and restoring a category obey different rules - a
 * folder releases its contents, a category frees its slug - and this screen
 * deliberately knows none of them.
 */
const props = defineProps({
    trashes: { type: Array, default: () => [] },
    retentionDays: { type: Number, default: 0 },
    listPath: { type: String, required: true },
});

const { t } = useI18n();
const { formatDateShort } = useDateFormat();
const { can } = usePrivileges();

const {
    visibleModules, total, active, activeModule, activeKey, busyId,
    pendingForceDelete, pendingEmpty, emptying,
    select, selectModule, restore, askForceDelete, doForceDelete, askEmpty, doEmpty,
} = useTrashOverview(props);

function mayAct(trash) {
    return !trash.actionPrivilege || can(trash.actionPrivilege);
}

/** What can be done with a trash item, behind the "…" button. */
function itemActions(trash, item) {
    const actions = [];
    if (trash.restorePath) {
        actions.push({
            key: "restore",
            icon: RotateCcw,
            title: t("suite.trash.restore"),
            loading: busyId.value === item.id,
            onSelect: () => restore(trash, item),
        });
    }
    if (trash.forceDeletePath) {
        actions.push({
            key: "delete-forever",
            color: "rose",
            icon: Trash2,
            title: t("suite.trash.delete_forever"),
            onSelect: () => askForceDelete(trash, item),
        });
    }

    return actions;
}
</script>

<template>
    <div class="aurora-stack">
        <!-- The retention alone: what the trash is and how a restore goes
             are told by the guide below, said a second time above it and a
             third time under the list. -->
        <p class="text-xs text-muted">
            {{ retentionDays > 0 ? t("suite.trash.retention", { days: retentionDays }) : t("suite.trash.retention_off") }}
        </p>

        <!-- The screen's how-to guide, next to what it explains; folded
             or unfolded, the choice applies to every panel. -->
        <AppGuide :title="t('suite.trash.guide.title')" storage-key="trash">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.trash.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <AppNoData
            v-if="total === 0"
            :message="t('suite.trash.all_empty')"
            :hint="t('suite.trash.all_empty_hint')"
        />

        <template v-else>
            <!-- The modules, then the types of the open one, rather than one
                 tab per type: what can be done to a row depends on what it
                 is, and the counts say where to look without opening
                 anything. Both rows are the house segmented group, on one
                 line, scrolling sideways on a phone. -->
            <div class="flex flex-col gap-2">
                <div
                    class="flex w-fit max-w-full items-center gap-0.5 overflow-x-auto aurora-segmented scrollbar-hide"
                    role="group"
                    :aria-label="t('suite.trash.modules_label')"
                >
                    <button
                        v-for="group in visibleModules"
                        :key="group.key"
                        type="button"
                        class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-sm transition-colors"
                        :class="activeModule?.key === group.key ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
                        :aria-pressed="activeModule?.key === group.key"
                        v-on:click="selectModule(group.key)"
                    >
                        {{ group.label }}
                        <span class="text-xs tabular-nums text-muted">{{ group.count }}</span>
                    </button>
                </div>

                <!-- A module with a single type needs no second row: its
                     name above already says what the list holds. -->
                <div
                    v-if="activeModule && activeModule.rows.length > 1"
                    class="flex w-fit max-w-full items-center gap-0.5 overflow-x-auto aurora-segmented scrollbar-hide"
                    role="group"
                    :aria-label="t('suite.trash.types_label')"
                >
                    <button
                        v-for="row in activeModule.rows"
                        :key="row.key"
                        type="button"
                        class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-sm transition-colors"
                        :class="activeKey === row.key ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
                        :aria-pressed="activeKey === row.key"
                        v-on:click="select(row.key)"
                    >
                        <component :is="row.iconComponent" class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ row.label }}
                        <span class="text-xs tabular-nums text-muted">{{ row.count }}</span>
                    </button>
                </div>
            </div>

            <div v-if="active" class="space-y-3">
                <div class="flex flex-wrap items-center gap-3 bg-rose-500/10 border border-rose-400/30 rounded-xl px-2 py-2 sm:px-4 sm:py-2.5">
                    <Trash2 class="w-4 h-4 text-rose-400 shrink-0" :stroke-width="2" />
                    <p class="text-sm text-primary min-w-0">
                        <span v-if="active.count === 0">{{ t("suite.trash.empty_one") }}</span>
                        <span v-else>
                            {{ t("suite.trash.count", { count: active.count }, active.count) }}
                            <template v-if="active.daysLeft !== null">
                                &middot;
                                {{ active.daysLeft === 0 ? t("suite.trash.purge_due") : t("suite.trash.days_left", { count: active.daysLeft }, active.daysLeft) }}
                            </template>
                        </span>
                    </p>
                    <!-- In the banner that already exists, not above it: two
                         banners stacked for a list of three rows would be
                         more furniture than content. -->
                    <AppLink
                        v-if="active.listPath"
                        :href="active.listPath"
                        class="ml-auto text-sm"
                    >
                        {{ t("suite.trash.open_list", { list: active.label }) }}
                    </AppLink>
                    <AppButton
                        v-if="active.emptyTrashPath && active.count > 0 && mayAct(active)"
                        size="sm"
                        variant="danger"
                        :class="active.listPath ? '' : 'ml-auto'"
                        v-on:click="askEmpty(active)"
                    >
                        <Flame class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.trash.empty") }}
                    </AppButton>
                </div>

                <AppMessage v-if="!active.items.length" variant="info">
                    {{ t("suite.trash.empty_one") }}
                    <AppLink v-if="active.listPath" :href="active.listPath" class="ml-1">
                        {{ t("suite.trash.open_list", { list: active.label }) }}
                    </AppLink>
                </AppMessage>

                <div v-else class="aurora-card overflow-hidden divide-y divide-line/40">
                    <!-- The name keeps the whole row, and its actions (restore,
                         delete permanently) are behind the "…" button, as on
                         every list (Axel's decision of 04/10/2026). -->
                    <div
                        v-for="item in active.items"
                        :key="item.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-primary truncate">{{ item.label }}</p>
                            <p class="text-xs text-muted mt-0.5">
                                <span v-if="item.context">{{ item.context }} &middot; </span>
                                {{ t("suite.trash.deleted_on", { date: formatDateShort(item.deletedAt) }) }}
                            </p>
                        </div>
                        <AppRowActions
                            v-if="mayAct(active)"
                            class="shrink-0"
                            :actions="itemActions(active, item)"
                            :label="item.label"
                        />
                    </div>
                </div>

                <!-- The list is capped, and says so rather than pretending to
                     be everything: a trash holding more than this has a purge
                     to run, not a page to leaf through. -->
                <p v-if="active.count > active.items.length" class="text-xs text-muted">
                    {{ t("suite.trash.truncated", { shown: active.items.length, total: active.count }) }}
                </p>
            </div>
        </template>

        <AppModal
            :show="!!pendingForceDelete"
            max-width="sm"
            :closeable="false"
            :title="t('suite.trash.delete_forever')"
            :icon="Trash2"
            v-on:close="pendingForceDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t("suite.trash.delete_forever_confirm", { name: pendingForceDelete?.item.label ?? "" }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingForceDelete = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="doForceDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.trash.delete_forever") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingEmpty"
            max-width="sm"
            :closeable="false"
            :title="t('suite.trash.empty')"
            :icon="Flame"
            v-on:close="pendingEmpty = null"
        >
            <p class="text-sm text-primary">
                {{ t("suite.trash.empty_confirm", { count: pendingEmpty?.count ?? 0 }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingEmpty = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="emptying" v-on:click="doEmpty">
                        <Flame class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.trash.empty") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
