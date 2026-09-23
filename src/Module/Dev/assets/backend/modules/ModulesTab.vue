<script setup>
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { Lock, ChevronDown, Globe } from "lucide-vue-next";
import { resolveNavIcon, moduleIconColorClass, moduleHeaderClass, moduleIdFromToggleKey } from "@/shared/nav/navMeta.js";
import { useModules } from "./composables/useModules.js";
import { useCollapsibleSections } from "./composables/useCollapsibleSections.js";

const { t } = useI18n();

const props = defineProps({
    modulesPath: { type: String, required: true },
    moduleUpdatePath: { type: String, required: true },
    moduleVerifyPasswordPath: { type: String, required: true },
    initialData: { type: Object, default: null },
});

const modules = useModules(
    props.modulesPath,
    props.moduleUpdatePath,
    props.moduleVerifyPasswordPath,
    props.initialData,
);

const sections = useCollapsibleSections();

onMounted(() => {
    if (!modules.parameters.value.length) modules.load();
});
</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-secondary">{{ t("backend.settings.modules_intro") }}</p>
        <AppSearchInput
            v-model="modules.searchInput.value"
            :placeholder="t('backend.settings.modules_search_placeholder')"
        />

        <AppNoData v-if="!modules.filteredParameters.value.length" :message="t('backend.settings.modules_empty')" />

        <div
            v-for="parameter in modules.filteredParameters.value"
            :key="parameter.key"
            class="aurora-card overflow-hidden"
        >
            <!-- Parent module header (clickable to collapse/expand) -->
            <div
                class="flex items-center gap-3 px-4 py-3 border-l-4 transition-colors cursor-pointer select-none"
                :class="[moduleHeaderClass(moduleIdFromToggleKey(parameter.key)), { 'opacity-60': modules.isLocked(parameter) }]"
                v-on:click="parameter.subModules?.length && sections.toggle(parameter.key)"
            >
                <ChevronDown
                    v-if="parameter.subModules?.length"
                    class="w-4 h-4 text-muted transition-transform shrink-0"
                    :class="{ '-rotate-90': !sections.isExpanded(parameter.key) }"
                    :stroke-width="2"
                />
                <span v-else class="w-4 shrink-0" />

                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-primary flex items-center gap-1.5 flex-wrap">
                        {{ parameter.label }}
                        <AppBadge v-if="parameter.type" :color="parameter.type === 'frontend' ? 'emerald' : 'slate'">
                            {{ t(`backend.settings.module_type.${parameter.type}`) }}
                        </AppBadge>
                        <Lock v-if="modules.isLocked(parameter)" class="w-3.5 h-3.5 text-muted" :stroke-width="2" />
                    </p>
                    <p v-if="parameter.description" class="text-xs text-muted mt-0.5">{{ parameter.description }}</p>
                    <p v-if="modules.isLocked(parameter)" class="text-xs text-warning mt-0.5">{{ modules.lockReason(parameter) }}</p>
                    <div
                        v-if="parameter.navItems?.length || (parameter.subModules ?? []).some((sub) => sub.type === 'frontend')"
                        class="flex flex-wrap gap-1 mt-1.5"
                    >
                        <!-- Backend features (module-coloured icon) -->
                        <span
                            v-for="item in parameter.navItems"
                            :key="item.labelKey"
                            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs text-muted bg-surface-alt border border-line"
                        >
                            <component
                                :is="resolveNavIcon(item.icon)"
                                class="w-3 h-3 shrink-0"
                                :class="moduleIconColorClass(moduleIdFromToggleKey(parameter.key))"
                                :stroke-width="2"
                            />
                            {{ t(item.labelKey) }}
                        </span>
                        <!-- Public/frontend toggles (differentiated: globe + emerald) -->
                        <span
                            v-for="sub in (parameter.subModules ?? []).filter((s) => s.type === 'frontend')"
                            :key="sub.key"
                            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs text-emerald-400 bg-emerald-500/10 border border-emerald-500/30"
                        >
                            <Globe class="w-3 h-3 shrink-0" :stroke-width="2" />
                            {{ sub.label }}
                        </span>
                    </div>
                </div>

                <div class="shrink-0" v-on:click.stop>
                    <AppToggle
                        :model-value="!modules.isLocked(parameter) && modules.fieldValues[parameter.key] === '1'"
                        :disabled="modules.isLocked(parameter) || modules.saving.value"
                        v-on:update:model-value="modules.onToggle(parameter, $event)"
                    />
                </div>
            </div>

            <!-- Sub-modules (collapsible) -->
            <div
                v-if="parameter.subModules?.length && sections.isExpanded(parameter.key)"
                class="border-t border-line bg-surface-alt/30 divide-y divide-line/40"
            >
                <div
                    v-for="sub in parameter.subModules"
                    :key="sub.key"
                    class="flex items-center gap-3 pl-11 pr-4 py-2.5 hover:bg-surface-2 transition-colors"
                    :class="{ 'opacity-60': modules.isLocked(sub) }"
                >
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-primary flex items-center gap-1.5 flex-wrap">
                            {{ sub.label }}
                            <AppBadge v-if="sub.type" :color="sub.type === 'frontend' ? 'emerald' : 'slate'">
                                {{ t(`backend.settings.module_type.${sub.type}`) }}
                            </AppBadge>
                            <Lock v-if="modules.isLocked(sub)" class="w-3.5 h-3.5 text-muted" :stroke-width="2" />
                        </p>
                        <p v-if="sub.description" class="text-xs text-muted mt-0.5">{{ sub.description }}</p>
                        <p v-if="modules.isLocked(sub)" class="text-xs text-warning mt-0.5">{{ modules.lockReason(sub) }}</p>
                    </div>
                    <AppToggle
                        :model-value="!modules.isLocked(sub) && modules.fieldValues[sub.key] === '1'"
                        :disabled="modules.isLocked(sub) || modules.saving.value"
                        v-on:update:model-value="modules.onToggle(sub, $event)"
                    />
                </div>
            </div>
        </div>

        <AppModal
            :show="modules.showPasswordModal.value"
            :title="t('backend.settings.confirm_password')"
            max-width="sm"
            v-on:close="modules.showPasswordModal.value = false"
        >
            <p class="text-sm text-muted">{{ t("backend.settings.confirm_password_description") }}</p>
            <AppInput
                v-model="modules.password.value"
                type="password"
                :placeholder="t('backend.settings.confirm_password_placeholder')"
                :error="modules.passwordError.value"
                v-on:keydown.enter="modules.confirmPassword"
            />
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="modules.showPasswordModal.value = false">
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="modules.verifying.value" v-on:click="modules.confirmPassword">
                        {{ t("backend.settings.confirm_password_confirm") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
