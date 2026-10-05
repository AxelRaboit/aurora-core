<script setup>
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Radar, Trash2, Save, ShieldCheck, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const props = defineProps({
    instances: { type: Array, default: () => [] },
    knownDomains: { type: Array, default: () => [] },
    saveKnownDomainsPath: { type: String, default: "" },
    forgetPath: { type: String, default: "" },
});

const { t } = useI18n();
const { request } = useRequest();
const { formatDateTime } = useDateFormat();

const rows = ref([...props.instances]);
const domainsText = ref(props.knownDomains.join("\n"));
const savingDomains = ref(false);

const leadCount = computed(() => rows.value.filter((row) => !row.known).length);

async function saveDomains() {
    if (savingDomains.value) return;
    savingDomains.value = true;
    const domains = domainsText.value
        .split("\n")
        .map((line) => line.trim())
        .filter((line) => line !== "");
    const result = await request(props.saveKnownDomainsPath, { domains });
    savingDomains.value = false;
    if (result?.success) {
        domainsText.value = (result.domains ?? domains).join("\n");
        toast.success(t("backend.beacon.saved"));
    }
}

const {
    pendingDelete: pendingForget,
    loading: forgetting,
    confirm: confirmForget,
    submit: forget,
} = useDelete(
    props.forgetPath,
    (id) => {
        rows.value = rows.value.filter((item) => item.id !== id);
    },
    "backend.beacon.forgotten",
);

/** Ce qu'on peut faire d'une instance, derrière « … » comme sur les autres listes. */
function rowActions(row) {
    return [
        {
            key: "forget",
            color: "rose",
            icon: Trash2,
            title: t("backend.beacon.forget"),
            onSelect: () => confirmForget(row),
        },
    ];
}
</script>

<template>
    <div class="aurora-stack">
        <p class="text-sm text-muted max-w-3xl">{{ t("backend.beacon.intro") }}</p>

        <section class="space-y-3">
            <div class="flex items-center gap-2 text-primary">
                <Radar class="size-5" aria-hidden="true" />
                <h2 class="text-base font-semibold">{{ t("backend.beacon.instances_title") }}</h2>
                <AppBadge v-if="leadCount > 0" color="rose">{{ leadCount }}</AppBadge>
            </div>

            <div v-if="rows.length === 0" class="rounded-xl border border-line bg-surface px-4 py-10 text-center text-sm text-muted">
                {{ t("backend.beacon.empty") }}
            </div>

            <div v-else class="overflow-x-auto rounded-xl border border-line">
                <table class="w-full text-sm">
                    <thead class="bg-surface-2 text-secondary">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ t("backend.beacon.col_status") }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t("backend.beacon.col_domain") }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t("backend.beacon.col_host") }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t("backend.beacon.col_ip") }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t("backend.beacon.col_version") }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t("backend.beacon.col_signed") }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ t("backend.beacon.col_pings") }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t("backend.beacon.col_last_seen") }}</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.id" class="text-primary">
                            <td class="px-3 py-2">
                                <AppBadge :color="row.known ? 'emerald' : 'rose'">
                                    {{ row.known ? t("backend.beacon.known") : t("backend.beacon.lead") }}
                                </AppBadge>
                            </td>
                            <td class="px-3 py-2 font-medium">{{ row.domain ?? "-" }}</td>
                            <td class="px-3 py-2 text-secondary">{{ row.hostname ?? "-" }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-secondary select-all" data-beacon-ip>{{ row.lastIp ?? "-" }}</td>
                            <td class="px-3 py-2 text-secondary">{{ row.appVersion ?? "-" }}</td>
                            <td class="px-3 py-2">
                                <span v-if="row.signatureValid" class="inline-flex items-center gap-1 text-emerald-400">
                                    <ShieldCheck class="size-4" aria-hidden="true" />{{ t("backend.beacon.signed_yes") }}
                                </span>
                                <span v-else class="text-muted">{{ t("backend.beacon.signed_no") }}</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-secondary">{{ row.pingCount }}</td>
                            <td class="px-3 py-2 text-secondary">{{ formatDateTime(row.lastSeenAt) }}</td>
                            <td class="px-3 py-2 text-right">
                                <AppRowActions :actions="rowActions(row)" :label="row.domain ?? row.instanceId" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3 max-w-xl">
            <h2 class="text-base font-semibold text-primary">{{ t("backend.beacon.allowlist_title") }}</h2>
            <AppTextarea
                v-model="domainsText"
                :rows="5"
                :label="t('backend.beacon.allowlist_title')"
                :placeholder="t('backend.beacon.allowlist_placeholder')"
            />
            <p class="text-xs text-muted">{{ t("backend.beacon.allowlist_hint") }}</p>
            <div>
                <AppButton variant="primary" :loading="savingDomains" v-on:click="saveDomains">
                    <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.beacon.save") }}
                </AppButton>
            </div>
        </section>
        <AppModal
            :show="!!pendingForget"
            max-width="sm"
            :closeable="false"
            :title="t('backend.beacon.forget')"
            :icon="Trash2"
            v-on:close="pendingForget = null"
        >
            <p class="text-sm text-primary">
                {{ t("backend.beacon.forget_confirm", { name: pendingForget?.domain ?? pendingForget?.instanceId ?? "" }) }}
            </p>
            <p class="text-sm text-secondary">{{ t("backend.beacon.forget_warning") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingForget = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="forgetting" v-on:click="forget">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.beacon.forget_action") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
