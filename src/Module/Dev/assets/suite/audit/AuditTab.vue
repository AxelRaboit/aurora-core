<script setup>
import { computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useAudit } from "./composables/useAudit.js";

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    auditPath: { type: String, required: true },
    initialData: { type: Object, default: null },
});

const { data, load, module, setModule } = useAudit(props.auditPath, props.initialData);

const moduleOptions = computed(() =>
    (data.value?.modules ?? []).map((name) => ({
        value: name,
        // The raw name as a fallback: a renamed module leaves behind rows
        // carrying its old name, and a translation key shown as it is is more
        // confusing than the name itself. Measured on 16/09/2026: twenty-seven
        // rows written under "accounting", which Studio replaced.
        label: t(`suite.modules.${name}`, name),
    })),
);

onMounted(() => {
    if (!data.value?.items?.length) load();
});
</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-secondary">{{ t('suite.audit.intro') }}</p>
        <div v-if="data?.modules?.length">
            <AppMultiselect
                :model-value="module"
                :options="moduleOptions"
                :label="t('suite.audit.module')"
                :placeholder="t('shared.common.all')"
                allow-empty
                v-on:update:model-value="setModule"
            />
        </div>
        <p v-if="!data?.items?.length" class="py-8 text-center text-sm text-muted">{{ t('suite.audit.empty') }}</p>
        <div v-else class="aurora-card overflow-x-auto scrollbar-thin">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('suite.audit.action') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden sm:table-cell">{{ t('suite.audit.module') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t('suite.audit.user') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('suite.audit.date') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr v-for="log in data.items" :key="log.id" class="group hover:bg-surface-2/40 transition-colors">
                        <td class="px-4 py-2">
                            <span class="text-primary text-sm font-medium">{{ t(`suite.audit.actions.${log.module}.${log.action}`) }}</span>
                            <span v-if="log.entityType" class="ml-2 text-muted text-xs">{{ log.entityType }} #{{ log.entityId }}</span>
                            <span v-if="log.data?.name" class="ml-2 text-secondary text-xs truncate">· {{ log.data.name }}</span>
                        </td>
                        <td class="px-4 py-2 hidden sm:table-cell">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-surface-2 text-secondary">{{ t(`suite.modules.${log.module}`, log.module) }}</span>
                        </td>
                        <td class="px-4 py-2 text-secondary text-xs hidden md:table-cell">
                            <template v-if="log.userName">{{ log.userName }}</template>
                            <template v-if="log.userName && log.userEmail"> · </template>
                            <span v-if="log.userEmail" class="text-muted">{{ log.userEmail }}</span>
                            <template v-if="!log.userName && !log.userEmail">-</template>
                        </td>
                        <td class="px-4 py-2 text-secondary text-xs">{{ formatDateTime(log.createdAt) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
