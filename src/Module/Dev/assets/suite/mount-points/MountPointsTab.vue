<script setup>
import { computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import { Plus, Pencil, Trash2, Wifi, WifiOff, CircleHelp, X, CheckCircle, XCircle, LoaderCircle, RotateCcw, Network } from "lucide-vue-next";
import { useMountPoints } from "./composables/useMountPoints.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    mountPointsPath: { type: String, required: true },
    mountPointCreatePath: { type: String, required: true },
    mountPointUpdatePath: { type: String, required: true },
    mountPointDeletePath: { type: String, required: true },
    mountPointTestPath: { type: String, required: true },
    initialData: { type: Object, default: null },
});

const mountPointsState = useMountPoints(
    props.mountPointsPath,
    props.mountPointCreatePath,
    props.mountPointUpdatePath,
    props.mountPointDeletePath,
    props.mountPointTestPath,
    props.initialData,
);

onMounted(() => {
    if (!mountPointsState.mountPoints.value.length) mountPointsState.load();
});


/**
 * What one mount point offers.
 *
 * Three glyphs in a row said what they did only to whoever already knew them,
 * and the destructive one sat a few pixels from the one that only pings the
 * host. Named rows, and the delete last.
 */
function actionsFor(mountPoint) {
    return [
        {
            key: "test",
            icon: Wifi,
            title: t("suite.mount_points.test"),
            disabled: mountPointsState.testModal.value.testing,
            onSelect: () => mountPointsState.openTestModal(mountPoint),
        },
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("shared.common.edit"),
            onSelect: () => mountPointsState.openEdit(mountPoint),
        },
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            onSelect: () => mountPointsState.confirmDelete(mountPoint),
        },
    ];
}

// The create verb is marked `primary`: AppPageActions sets it beside the
// sheet as the page's main button, and the sheet keeps whatever else there is.
const pageActions = computed(() => {
    return [
        {
            key: "create",
            primary: true,
            color: "accent",
            icon: Plus,
            title: t("suite.mount_points.add"),
            onSelect: mountPointsState.openCreate,
        },
    ];
});
</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-secondary">{{ t("suite.mount_points.intro") }}</p>

        <div class="flex items-center gap-2">
            <AppSearchInput
                v-model="mountPointsState.searchInput.value"
                class="flex-1"
                :placeholder="t('suite.mount_points.search_placeholder')"
            />
            <AppPageActions :actions="pageActions" icon-only-on-phone />
        </div>

        <div class="aurora-card overflow-x-auto scrollbar-thin">
            <p v-if="!mountPointsState.filteredMountPoints.value.length" class="py-8 text-center text-sm text-muted">
                {{ t("suite.mount_points.empty") }}
            </p>

            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("suite.mount_points.name") }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("suite.mount_points.type") }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("suite.mount_points.host") }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("suite.mount_points.last_tested") }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="mountPoint in mountPointsState.filteredMountPoints.value"
                        :key="mountPoint.id"
                        class="hover:bg-surface-2/40 transition-colors"
                    >
                        <td class="px-4 py-2 font-medium text-primary">{{ mountPoint.name }}</td>
                        <td class="px-4 py-2 text-muted capitalize">{{ mountPoint.type }}</td>
                        <td class="px-4 py-2 text-secondary hidden md:table-cell font-mono text-xs">
                            {{ mountPoint.host }}{{ mountPoint.port ? `:${mountPoint.port}` : "" }}
                        </td>
                        <td class="px-4 py-2 hidden lg:table-cell">
                            <span v-if="mountPoint.lastTestedAt" class="inline-flex items-center gap-1.5 text-xs">
                                <Wifi v-if="mountPoint.lastTestSuccessful" class="w-3.5 h-3.5 text-success shrink-0" :stroke-width="2" />
                                <WifiOff v-else class="w-3.5 h-3.5 text-danger shrink-0" :stroke-width="2" />
                                <span class="text-muted">{{ formatDateTime(mountPoint.lastTestedAt) }}</span>
                            </span>
                            <span v-else class="text-xs text-muted flex items-center gap-1">
                                <CircleHelp class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                                {{ t("suite.mount_points.never") }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <AppRowActions :actions="actionsFor(mountPoint)" :label="mountPoint.name" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create modal -->
        <AppModal
            :show="mountPointsState.showCreateModal.value"
            :title="t('suite.mount_points.add')"
            :icon="Network"
            max-width="4xl"
            :closeable="false"
            v-on:close="mountPointsState.closeCreate"
        >
            <form class="space-y-4" v-on:submit.prevent="mountPointsState.submitCreate">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mountPointsState.createForm.value.name"
                        :label="t('suite.mount_points.name')"
                        :placeholder="t('suite.mount_points.name_placeholder')"
                        :error="mountPointsState.createErrors.value.name"
                        required
                    />
                    <AppSelect v-model="mountPointsState.createForm.value.type" :label="t('suite.mount_points.type')" :options="mountPointsState.types.value" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                    <AppInput
                        v-model="mountPointsState.createForm.value.host"
                        :label="t('suite.mount_points.host')"
                        :placeholder="t('suite.mount_points.host_placeholder')"
                        :error="mountPointsState.createErrors.value.host"
                        required
                    />
                    <AppInput
                        v-model="mountPointsState.createForm.value.port"
                        :label="t('suite.mount_points.port')"
                        :placeholder="t('suite.mount_points.port_placeholder')"
                        type="number"
                    />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mountPointsState.createForm.value.username"
                        :label="t('suite.mount_points.username')"
                        :placeholder="t('suite.mount_points.username_placeholder')"
                        autocomplete="off"
                    />
                    <AppInput
                        v-model="mountPointsState.createForm.value.password"
                        :label="t('suite.mount_points.password')"
                        :placeholder="t('shared.placeholders.password')"
                        toggleable
                        autocomplete="new-password"
                    />
                </div>
                <AppInput
                    v-model="mountPointsState.createForm.value.database"
                    :label="t('suite.mount_points.database')"
                    :placeholder="t('suite.mount_points.database_placeholder')"
                />
                <AppInput
                    v-model="mountPointsState.createForm.value.sshPublicKey"
                    :label="t('suite.mount_points.ssh_public_key')"
                    :placeholder="t('suite.mount_points.ssh_public_key_placeholder')"
                />

                <div class="border-t border-line pt-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-primary">{{ t("suite.mount_points.ssh_tunnel") }}</p>
                            <p class="text-xs text-muted mt-0.5">{{ t("suite.mount_points.ssh_tunnel_hint") }}</p>
                        </div>
                        <AppToggle
                            :model-value="mountPointsState.createForm.value.config.sshTunnel"
                            v-on:update:model-value="mountPointsState.createForm.value.config.sshTunnel = $event"
                        />
                    </div>
                    <template v-if="mountPointsState.createForm.value.config.sshTunnel">
                        <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                            <AppInput
                                v-model="mountPointsState.createForm.value.config.sshHost"
                                :label="t('suite.mount_points.ssh_host')"
                                :placeholder="t('suite.mount_points.ssh_host_placeholder')"
                            />
                            <AppInput
                                v-model="mountPointsState.createForm.value.config.sshPort"
                                :label="t('suite.mount_points.ssh_port')"
                                type="number"
                                placeholder="22"
                            />
                        </div>
                        <AppInput
                            v-model="mountPointsState.createForm.value.config.sshUser"
                            :label="t('suite.mount_points.ssh_user')"
                            :placeholder="t('suite.mount_points.ssh_user_placeholder')"
                            autocomplete="off"
                        />
                        <AppTextarea
                            v-model="mountPointsState.createForm.value.sshPrivateKey"
                            :label="t('suite.mount_points.ssh_private_key')"
                            :placeholder="t('suite.mount_points.ssh_private_key_placeholder')"
                            :rows="5"
                            class="font-mono text-xs"
                        />
                    </template>
                </div>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" type="button" v-on:click="mountPointsState.closeCreate">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" type="submit" :loading="mountPointsState.saving.value">
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.create") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Edit modal -->
        <AppModal
            :show="mountPointsState.showEditModal.value"
            :title="mountPointsState.editingMountPoint.value?.name ?? t('suite.mount_points.edit')"
            :icon="Pencil"
            max-width="4xl"
            :closeable="false"
            v-on:close="mountPointsState.closeEdit"
        >
            <form class="space-y-4" v-on:submit.prevent="mountPointsState.submitEdit">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mountPointsState.editForm.value.name"
                        :label="t('suite.mount_points.name')"
                        :placeholder="t('suite.mount_points.name_placeholder')"
                        :error="mountPointsState.editErrors.value.name"
                        required
                    />
                    <AppSelect v-model="mountPointsState.editForm.value.type" :label="t('suite.mount_points.type')" :options="mountPointsState.types.value" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                    <AppInput
                        v-model="mountPointsState.editForm.value.host"
                        :label="t('suite.mount_points.host')"
                        :placeholder="t('suite.mount_points.host_placeholder')"
                        :error="mountPointsState.editErrors.value.host"
                        required
                    />
                    <AppInput
                        v-model="mountPointsState.editForm.value.port"
                        :label="t('suite.mount_points.port')"
                        :placeholder="t('suite.mount_points.port_placeholder')"
                        type="number"
                    />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mountPointsState.editForm.value.username"
                        :label="t('suite.mount_points.username')"
                        :placeholder="t('suite.mount_points.username_placeholder')"
                        autocomplete="off"
                    />
                    <AppInput
                        v-model="mountPointsState.editForm.value.password"
                        :label="t('suite.mount_points.password')"
                        :placeholder="t('suite.mount_points.password_placeholder')"
                        toggleable
                        autocomplete="new-password"
                    />
                </div>
                <AppInput
                    v-model="mountPointsState.editForm.value.database"
                    :label="t('suite.mount_points.database')"
                    :placeholder="t('suite.mount_points.database_placeholder')"
                />
                <AppInput
                    v-model="mountPointsState.editForm.value.sshPublicKey"
                    :label="t('suite.mount_points.ssh_public_key')"
                    :placeholder="t('suite.mount_points.ssh_public_key_placeholder')"
                />

                <div class="border-t border-line pt-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-primary">{{ t("suite.mount_points.ssh_tunnel") }}</p>
                            <p class="text-xs text-muted mt-0.5">{{ t("suite.mount_points.ssh_tunnel_hint") }}</p>
                        </div>
                        <AppToggle
                            :model-value="mountPointsState.editForm.value.config.sshTunnel"
                            v-on:update:model-value="mountPointsState.editForm.value.config.sshTunnel = $event"
                        />
                    </div>
                    <template v-if="mountPointsState.editForm.value.config.sshTunnel">
                        <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                            <AppInput
                                v-model="mountPointsState.editForm.value.config.sshHost"
                                :label="t('suite.mount_points.ssh_host')"
                                :placeholder="t('suite.mount_points.ssh_host_placeholder')"
                            />
                            <AppInput
                                v-model="mountPointsState.editForm.value.config.sshPort"
                                :label="t('suite.mount_points.ssh_port')"
                                type="number"
                                placeholder="22"
                            />
                        </div>
                        <AppInput
                            v-model="mountPointsState.editForm.value.config.sshUser"
                            :label="t('suite.mount_points.ssh_user')"
                            :placeholder="t('suite.mount_points.ssh_user_placeholder')"
                            autocomplete="off"
                        />
                        <AppTextarea
                            v-model="mountPointsState.editForm.value.sshPrivateKey"
                            :label="t('suite.mount_points.ssh_private_key')"
                            :placeholder="mountPointsState.editingMountPoint.value?.hasSshPrivateKey ? t('suite.mount_points.ssh_private_key_hint') : t('suite.mount_points.ssh_private_key_placeholder')"
                            :rows="5"
                            class="font-mono text-xs"
                        />
                    </template>
                </div>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" type="button" v-on:click="mountPointsState.closeEdit">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" type="submit" :loading="mountPointsState.saving.value">
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Test connection modal -->
        <AppModal
            :show="mountPointsState.testModal.value.show"
            :title="t('suite.mount_points.test_title', { name: mountPointsState.testModal.value.mountPoint?.name ?? '' })"
            :icon="Network"
            max-width="sm"
            :closeable="false"
            v-on:close="mountPointsState.testModal.value.testing ? mountPointsState.cancelTest() : mountPointsState.closeTestModal()"
        >
            <div class="flex flex-col items-center gap-4 py-2">
                <template v-if="mountPointsState.testModal.value.testing">
                    <LoaderCircle class="w-10 h-10 text-accent animate-spin" :stroke-width="1.5" />
                    <p class="text-sm text-secondary">{{ t("suite.mount_points.testing") }}</p>
                </template>
                <template v-else-if="mountPointsState.testModal.value.result">
                    <CheckCircle v-if="mountPointsState.testModal.value.result.success" class="w-10 h-10 text-success" :stroke-width="1.5" />
                    <XCircle v-else class="w-10 h-10 text-danger" :stroke-width="1.5" />
                    <p class="text-sm font-medium" :class="mountPointsState.testModal.value.result.success ? 'text-success' : 'text-danger'">
                        {{ mountPointsState.testModal.value.result.success ? t("suite.mount_points.test_success") : t("suite.mount_points.test_failure") }}
                    </p>
                    <p v-if="mountPointsState.testModal.value.result.message" class="text-xs text-muted text-center font-mono bg-surface-2 rounded-lg px-3 py-2 w-full">
                        {{ mountPointsState.testModal.value.result.message }}
                    </p>
                </template>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton v-if="mountPointsState.testModal.value.result" variant="ghost" size="md" v-on:click="mountPointsState.retryTest()">
                        <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.mount_points.retry") }}
                    </AppButton>
                    <AppButton v-if="mountPointsState.testModal.value.testing" variant="ghost" size="md" v-on:click="mountPointsState.cancelTest">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton v-else variant="ghost" size="md" v-on:click="mountPointsState.closeTestModal">
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Delete confirm modal -->
        <AppModal :show="mountPointsState.showDeleteModal.value" max-width="sm" :closeable="false" v-on:close="mountPointsState.showDeleteModal.value = false">
            <p class="text-sm text-primary">
                {{ t("suite.mount_points.delete_confirm") }}
                <strong v-if="mountPointsState.pendingDelete.value"> « {{ mountPointsState.pendingDelete.value.name }} »</strong>
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="mountPointsState.showDeleteModal.value = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="mountPointsState.doDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
