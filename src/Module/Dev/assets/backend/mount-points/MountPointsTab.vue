<script setup>
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
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

const mp = useMountPoints(
    props.mountPointsPath,
    props.mountPointCreatePath,
    props.mountPointUpdatePath,
    props.mountPointDeletePath,
    props.mountPointTestPath,
    props.initialData,
);

onMounted(() => {
    if (!mp.mountPoints.value.length) mp.load();
});

</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-secondary">{{ t("backend.mount_points.intro") }}</p>

        <div class="flex items-center gap-2">
            <AppSearchInput
                v-model="mp.searchInput.value"
                class="flex-1"
                :placeholder="t('backend.mount_points.search_placeholder')"
            />
            <AppButton variant="primary" size="md" v-on:click="mp.openCreate">
                <Plus class="w-4 h-4" :stroke-width="2" />
                {{ t("backend.mount_points.add") }}
            </AppButton>
        </div>

        <div class="bg-surface border border-line rounded-xl overflow-x-auto scrollbar-thin">
            <p v-if="!mp.filteredMountPoints.value.length" class="py-8 text-center text-sm text-muted">
                {{ t("backend.mount_points.empty") }}
            </p>

            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.mount_points.name") }}</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.mount_points.type") }}</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("backend.mount_points.host") }}</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("backend.mount_points.last_tested") }}</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr
                        v-for="mountPoint in mp.filteredMountPoints.value"
                        :key="mountPoint.id"
                        class="hover:bg-surface-2/40 transition-colors"
                    >
                        <td class="px-5 py-3 font-medium text-primary">{{ mountPoint.name }}</td>
                        <td class="px-5 py-3 text-muted capitalize">{{ mountPoint.type }}</td>
                        <td class="px-5 py-3 text-secondary hidden md:table-cell font-mono text-xs">
                            {{ mountPoint.host }}{{ mountPoint.port ? `:${mountPoint.port}` : "" }}
                        </td>
                        <td class="px-5 py-3 hidden lg:table-cell">
                            <span v-if="mountPoint.lastTestedAt" class="inline-flex items-center gap-1.5 text-xs">
                                <Wifi v-if="mountPoint.lastTestSuccessful" class="w-3.5 h-3.5 text-success shrink-0" :stroke-width="2" />
                                <WifiOff v-else class="w-3.5 h-3.5 text-danger shrink-0" :stroke-width="2" />
                                <span class="text-muted">{{ formatDateTime(mountPoint.lastTestedAt) }}</span>
                            </span>
                            <span v-else class="text-xs text-muted flex items-center gap-1">
                                <CircleHelp class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                                {{ t("backend.mount_points.never") }}
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <AppIconButton color="gray" :title="t('backend.mount_points.test')" :disabled="mp.testModal.value.testing" v-on:click="mp.openTestModal(mountPoint)">
                                    <Wifi class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton color="accent" :title="t('shared.common.edit')" v-on:click="mp.openEdit(mountPoint)">
                                    <Pencil class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton color="rose" :title="t('shared.common.delete')" v-on:click="mp.confirmDelete(mountPoint)">
                                    <Trash2 class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create modal -->
        <AppModal
            :show="mp.showCreateModal.value"
            :title="t('backend.mount_points.add')"
            :icon="Network"
            max-width="4xl"
            :closeable="false"
            v-on:close="mp.closeCreate"
        >
            <form class="space-y-4" v-on:submit.prevent="mp.submitCreate">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mp.createForm.value.name"
                        :label="t('backend.mount_points.name')"
                        :placeholder="t('backend.mount_points.name_placeholder')"
                        :error="mp.createErrors.value.name"
                        required
                    />
                    <AppSelect v-model="mp.createForm.value.type" :label="t('backend.mount_points.type')">
                        <option v-for="type in mp.types.value" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </AppSelect>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                    <AppInput
                        v-model="mp.createForm.value.host"
                        :label="t('backend.mount_points.host')"
                        :placeholder="t('backend.mount_points.host_placeholder')"
                        :error="mp.createErrors.value.host"
                        required
                    />
                    <AppInput
                        v-model="mp.createForm.value.port"
                        :label="t('backend.mount_points.port')"
                        :placeholder="t('backend.mount_points.port_placeholder')"
                        type="number"
                    />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mp.createForm.value.username"
                        :label="t('backend.mount_points.username')"
                        :placeholder="t('backend.mount_points.username_placeholder')"
                        autocomplete="off"
                    />
                    <AppInput
                        v-model="mp.createForm.value.password"
                        :label="t('backend.mount_points.password')"
                        toggleable
                        autocomplete="new-password"
                    />
                </div>
                <AppInput
                    v-model="mp.createForm.value.database"
                    :label="t('backend.mount_points.database')"
                    :placeholder="t('backend.mount_points.database_placeholder')"
                />
                <AppInput
                    v-model="mp.createForm.value.sshPublicKey"
                    :label="t('backend.mount_points.ssh_public_key')"
                    :placeholder="t('backend.mount_points.ssh_public_key_placeholder')"
                />

                <div class="border-t border-line pt-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-primary">{{ t("backend.mount_points.ssh_tunnel") }}</p>
                            <p class="text-xs text-muted mt-0.5">{{ t("backend.mount_points.ssh_tunnel_hint") }}</p>
                        </div>
                        <AppToggle
                            :model-value="mp.createForm.value.config.sshTunnel"
                            v-on:update:model-value="mp.createForm.value.config.sshTunnel = $event"
                        />
                    </div>
                    <template v-if="mp.createForm.value.config.sshTunnel">
                        <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                            <AppInput
                                v-model="mp.createForm.value.config.sshHost"
                                :label="t('backend.mount_points.ssh_host')"
                                :placeholder="t('backend.mount_points.ssh_host_placeholder')"
                            />
                            <AppInput
                                v-model="mp.createForm.value.config.sshPort"
                                :label="t('backend.mount_points.ssh_port')"
                                type="number"
                                placeholder="22"
                            />
                        </div>
                        <AppInput
                            v-model="mp.createForm.value.config.sshUser"
                            :label="t('backend.mount_points.ssh_user')"
                            :placeholder="t('backend.mount_points.ssh_user_placeholder')"
                            autocomplete="off"
                        />
                        <AppTextarea
                            v-model="mp.createForm.value.sshPrivateKey"
                            :label="t('backend.mount_points.ssh_private_key')"
                            :placeholder="t('backend.mount_points.ssh_private_key_placeholder')"
                            :rows="5"
                            class="font-mono text-xs"
                        />
                    </template>
                </div>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" type="button" v-on:click="mp.closeCreate">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" type="submit" :loading="mp.saving.value">
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.create") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Edit modal -->
        <AppModal
            :show="mp.showEditModal.value"
            :title="mp.editingMountPoint.value?.name ?? t('backend.mount_points.edit')"
            :icon="Pencil"
            max-width="4xl"
            :closeable="false"
            v-on:close="mp.closeEdit"
        >
            <form class="space-y-4" v-on:submit.prevent="mp.submitEdit">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mp.editForm.value.name"
                        :label="t('backend.mount_points.name')"
                        :placeholder="t('backend.mount_points.name_placeholder')"
                        :error="mp.editErrors.value.name"
                        required
                    />
                    <AppSelect v-model="mp.editForm.value.type" :label="t('backend.mount_points.type')">
                        <option v-for="type in mp.types.value" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </AppSelect>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                    <AppInput
                        v-model="mp.editForm.value.host"
                        :label="t('backend.mount_points.host')"
                        :placeholder="t('backend.mount_points.host_placeholder')"
                        :error="mp.editErrors.value.host"
                        required
                    />
                    <AppInput
                        v-model="mp.editForm.value.port"
                        :label="t('backend.mount_points.port')"
                        :placeholder="t('backend.mount_points.port_placeholder')"
                        type="number"
                    />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <AppInput
                        v-model="mp.editForm.value.username"
                        :label="t('backend.mount_points.username')"
                        :placeholder="t('backend.mount_points.username_placeholder')"
                        autocomplete="off"
                    />
                    <AppInput
                        v-model="mp.editForm.value.password"
                        :label="t('backend.mount_points.password')"
                        :placeholder="t('backend.mount_points.password_placeholder')"
                        toggleable
                        autocomplete="new-password"
                    />
                </div>
                <AppInput
                    v-model="mp.editForm.value.database"
                    :label="t('backend.mount_points.database')"
                    :placeholder="t('backend.mount_points.database_placeholder')"
                />
                <AppInput
                    v-model="mp.editForm.value.sshPublicKey"
                    :label="t('backend.mount_points.ssh_public_key')"
                    :placeholder="t('backend.mount_points.ssh_public_key_placeholder')"
                />

                <div class="border-t border-line pt-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-primary">{{ t("backend.mount_points.ssh_tunnel") }}</p>
                            <p class="text-xs text-muted mt-0.5">{{ t("backend.mount_points.ssh_tunnel_hint") }}</p>
                        </div>
                        <AppToggle
                            :model-value="mp.editForm.value.config.sshTunnel"
                            v-on:update:model-value="mp.editForm.value.config.sshTunnel = $event"
                        />
                    </div>
                    <template v-if="mp.editForm.value.config.sshTunnel">
                        <div class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                            <AppInput
                                v-model="mp.editForm.value.config.sshHost"
                                :label="t('backend.mount_points.ssh_host')"
                                :placeholder="t('backend.mount_points.ssh_host_placeholder')"
                            />
                            <AppInput
                                v-model="mp.editForm.value.config.sshPort"
                                :label="t('backend.mount_points.ssh_port')"
                                type="number"
                                placeholder="22"
                            />
                        </div>
                        <AppInput
                            v-model="mp.editForm.value.config.sshUser"
                            :label="t('backend.mount_points.ssh_user')"
                            :placeholder="t('backend.mount_points.ssh_user_placeholder')"
                            autocomplete="off"
                        />
                        <AppTextarea
                            v-model="mp.editForm.value.sshPrivateKey"
                            :label="t('backend.mount_points.ssh_private_key')"
                            :placeholder="mp.editingMountPoint.value?.hasSshPrivateKey ? t('backend.mount_points.ssh_private_key_hint') : t('backend.mount_points.ssh_private_key_placeholder')"
                            :rows="5"
                            class="font-mono text-xs"
                        />
                    </template>
                </div>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" type="button" v-on:click="mp.closeEdit">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" type="submit" :loading="mp.saving.value">
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Test connection modal -->
        <AppModal
            :show="mp.testModal.value.show"
            :title="t('backend.mount_points.test_title', { name: mp.testModal.value.mountPoint?.name ?? '' })"
            :icon="Network"
            max-width="sm"
            :closeable="false"
            v-on:close="mp.testModal.value.testing ? mp.cancelTest() : mp.closeTestModal()"
        >
            <div class="flex flex-col items-center gap-4 py-2">
                <template v-if="mp.testModal.value.testing">
                    <LoaderCircle class="w-10 h-10 text-accent animate-spin" :stroke-width="1.5" />
                    <p class="text-sm text-secondary">{{ t("backend.mount_points.testing") }}</p>
                </template>
                <template v-else-if="mp.testModal.value.result">
                    <CheckCircle v-if="mp.testModal.value.result.success" class="w-10 h-10 text-success" :stroke-width="1.5" />
                    <XCircle v-else class="w-10 h-10 text-danger" :stroke-width="1.5" />
                    <p class="text-sm font-medium" :class="mp.testModal.value.result.success ? 'text-success' : 'text-danger'">
                        {{ mp.testModal.value.result.success ? t("backend.mount_points.test_success") : t("backend.mount_points.test_failure") }}
                    </p>
                    <p v-if="mp.testModal.value.result.message" class="text-xs text-muted text-center font-mono bg-surface-2 rounded-lg px-3 py-2 w-full">
                        {{ mp.testModal.value.result.message }}
                    </p>
                </template>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton v-if="mp.testModal.value.result" variant="ghost" size="md" v-on:click="mp.retryTest()">
                        <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.mount_points.retry") }}
                    </AppButton>
                    <AppButton v-if="mp.testModal.value.testing" variant="ghost" size="md" v-on:click="mp.cancelTest">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton v-else variant="ghost" size="md" v-on:click="mp.closeTestModal">
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Delete confirm modal -->
        <AppModal :show="mp.showDeleteModal.value" max-width="sm" :closeable="false" v-on:close="mp.showDeleteModal.value = false">
            <p class="text-sm text-primary">
                {{ t("backend.mount_points.delete_confirm") }}
                <strong v-if="mp.pendingDelete.value"> « {{ mp.pendingDelete.value.name }} »</strong>
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="mp.showDeleteModal.value = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="mp.doDelete">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
