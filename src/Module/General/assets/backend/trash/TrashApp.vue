<script setup>
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { Trash2, RotateCcw, Flame, X, Info } from "lucide-vue-next";
import { useTrashOverview } from "@general/backend/trash/composables/useTrashOverview.js";

/**
 * Everything that was deleted, in one place, whatever module it came from.
 *
 * Names no module and imports none: each row comes from a
 * TrashSourceInterface on the PHP side, icon, label and action addresses
 * included. A module that is switched off, or one the reader may not open,
 * contributes no tab at all.
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
const { can } = usePrivileges();

const {
    rows, total, active, activeKey, busyId,
    pendingForceDelete, pendingEmpty, emptying,
    select, restore, askForceDelete, doForceDelete, askEmpty, doEmpty,
} = useTrashOverview(props);

function mayAct(trash) {
    return !trash.actionPrivilege || can(trash.actionPrivilege);
}

function formatDate(value) {
    if (!value) return "";

    return new Date(value).toLocaleDateString(undefined, {
        day: "2-digit",
        month: "short",
        year: "numeric",
    });
}
</script>

<template>
    <div class="space-y-5">
        <div class="space-y-1">
            <p class="text-sm text-secondary">{{ t("backend.trash.intro") }}</p>
            <p class="text-xs text-muted">
                {{ retentionDays > 0 ? t("backend.trash.retention", { days: retentionDays }) : t("backend.trash.retention_off") }}
            </p>
        </div>

        <AppNoData
            v-if="total === 0"
            :message="t('backend.trash.all_empty')"
            :hint="t('backend.trash.all_empty_hint')"
        />

        <template v-else>
            <!-- One tab per type rather than one long list: what can be done to
                 a row depends on what it is, and the counter says where to
                 look without opening anything. -->
            <nav class="flex items-center gap-1 flex-wrap" :aria-label="t('backend.trash.title')">
                <AppTab
                    v-for="row in rows"
                    :key="row.key"
                    size="sm"
                    :color="row.count > 0 ? 'rose' : 'accent'"
                    :active="activeKey === row.key"
                    v-on:click="select(row.key)"
                >
                    <component :is="row.iconComponent" class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t(row.labelKey) }}
                    <span v-if="row.count" class="ml-1 tabular-nums">({{ row.count }})</span>
                </AppTab>
            </nav>

            <div v-if="active" class="space-y-3">
                <div class="flex flex-wrap items-center gap-3 bg-rose-500/10 border border-rose-400/30 rounded-xl px-4 py-2.5">
                    <Trash2 class="w-4 h-4 text-rose-400 shrink-0" :stroke-width="2" />
                    <p class="text-sm text-primary min-w-0">
                        <span v-if="active.count === 0">{{ t("backend.trash.empty_one") }}</span>
                        <span v-else>
                            {{ t("backend.trash.count", { count: active.count }, active.count) }}
                            <template v-if="active.daysLeft !== null">
                                &middot;
                                {{ active.daysLeft === 0 ? t("backend.trash.purge_due") : t("backend.trash.days_left", { count: active.daysLeft }, active.daysLeft) }}
                            </template>
                        </span>
                    </p>
                    <AppButton
                        v-if="active.emptyTrashPath && active.count > 0 && mayAct(active)"
                        size="sm"
                        variant="danger"
                        class="ml-auto"
                        v-on:click="askEmpty(active)"
                    >
                        <Flame class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.trash.empty") }}
                    </AppButton>
                </div>

                <AppNoData v-if="!active.items.length" :message="t('backend.trash.empty_one')" />

                <div v-else class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm divide-y divide-line/40">
                    <div
                        v-for="item in active.items"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-3"
                    >
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-primary truncate">{{ item.label }}</p>
                            <p class="text-xs text-muted mt-0.5">
                                <span v-if="item.context">{{ item.context }} &middot; </span>
                                {{ t("backend.trash.deleted_on", { date: formatDate(item.deletedAt) }) }}
                            </p>
                        </div>
                        <div v-if="mayAct(active)" class="flex items-center gap-2">
                            <AppButton
                                v-if="active.restorePath"
                                size="sm"
                                variant="ghost"
                                :loading="busyId === item.id"
                                v-on:click="restore(active, item)"
                            >
                                <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.trash.restore") }}
                            </AppButton>
                            <AppButton
                                v-if="active.forceDeletePath"
                                size="sm"
                                variant="danger"
                                v-on:click="askForceDelete(active, item)"
                            >
                                <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.trash.delete_forever") }}
                            </AppButton>
                        </div>
                    </div>
                </div>

                <!-- The list is capped, and says so rather than pretending to
                     be everything: a trash holding more than this has a purge
                     to run, not a page to leaf through. -->
                <p v-if="active.count > active.items.length" class="text-xs text-muted">
                    {{ t("backend.trash.truncated", { shown: active.items.length, total: active.count }) }}
                </p>
            </div>
        </template>

        <p class="flex items-start gap-2 text-xs text-muted">
            <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" :stroke-width="2" />
            {{ t("backend.trash.rules_note") }}
        </p>

        <AppModal
            :show="!!pendingForceDelete"
            max-width="sm"
            :closeable="false"
            :title="t('backend.trash.delete_forever')"
            :icon="Trash2"
            v-on:close="pendingForceDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t("backend.trash.delete_forever_confirm", { name: pendingForceDelete?.item.label ?? "" }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingForceDelete = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="doForceDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.trash.delete_forever") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingEmpty"
            max-width="sm"
            :closeable="false"
            :title="t('backend.trash.empty')"
            :icon="Flame"
            v-on:close="pendingEmpty = null"
        >
            <p class="text-sm text-primary">
                {{ t("backend.trash.empty_confirm", { count: pendingEmpty?.count ?? 0 }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingEmpty = null">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="emptying" v-on:click="doEmpty">
                        <Flame class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.trash.empty") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
