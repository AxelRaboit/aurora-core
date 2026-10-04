<script setup>
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Radar, Trash2, Save, ShieldCheck } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";

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

async function forget(row) {
    if (!window.confirm(t("backend.beacon.forget_confirm"))) return;
    const result = await request(buildPath(props.forgetPath, { id: row.id }), {});
    if (result?.success) {
        rows.value = rows.value.filter((item) => item.id !== row.id);
    }
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
                                <AppButton
                                    variant="ghost"
                                    size="sm"
                                    icon-only
                                    :label="t('backend.beacon.forget')"
                                    v-on:click="forget(row)"
                                >
                                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                                </AppButton>
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
    </div>
</template>
