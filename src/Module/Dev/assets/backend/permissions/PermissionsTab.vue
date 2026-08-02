<script setup>
import { useI18n } from "vue-i18n";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Inbox } from "lucide-vue-next";
import { usePermissions } from "./composables/usePermissions.js";
import { usePermissionsFilter } from "./composables/usePermissionsFilter.js";

const { t } = useI18n();

const props = defineProps({
    permissionsPath: { type: String, required: true },
    initialData: { type: Object, default: null },
});

const { data } = usePermissions(props.permissionsPath, props.initialData);
const { searchInput, filteredModules } = usePermissionsFilter(data);
</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-secondary">{{ t('backend.permissions.intro') }}</p>
        <AppSearchInput
            v-model="searchInput"
            :placeholder="t('backend.permissions.search_placeholder')"
        />
        <AppNoData v-if="!filteredModules.length" :message="t('backend.permissions.empty')" />
        <div v-for="moduleEntry in filteredModules" :key="moduleEntry.id" class="bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
            <div class="bg-surface-2 border-b border-line px-4 py-2.5">
                <h3 class="text-sm font-semibold text-primary">{{ t(`backend.modules.${moduleEntry.id}`) }}</h3>
            </div>
            <p v-if="!moduleEntry.permissions.length" class="px-4 py-3 text-xs text-muted flex items-center gap-1.5">
                <Inbox class="w-3.5 h-3.5 opacity-40" :stroke-width="1.5" />
                {{ t('backend.permissions.none') }}
            </p>
            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.permissions.name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted font-mono w-72">{{ t('backend.permissions.key') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr v-for="permission in moduleEntry.permissions" :key="permission.name" class="group hover:bg-surface-2/40 transition-colors">
                        <td class="px-4 py-2"><span class="text-primary text-sm">{{ t(`backend.permissions.names.${permission.name}`) }}</span></td>
                        <td class="px-4 py-2 w-72"><span class="font-mono text-xs text-accent-400 whitespace-nowrap">{{ permission.name }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
