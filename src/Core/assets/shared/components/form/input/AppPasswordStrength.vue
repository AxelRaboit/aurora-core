<script setup>
import { computed } from "vue";
import { Check } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { PASSWORD_RULES } from "@/shared/utils/validation/passwordRules.js";

const props = defineProps({
    password: { type: String, default: "" },
});

const { t } = useI18n();

const criteria = computed(() =>
    PASSWORD_RULES.map((rule) => ({
        key: rule.key,
        label: t(`shared.password.criteria.${rule.key}`),
        met: props.password.length > 0 && rule.test(props.password),
    })),
);
</script>

<template>
    <!-- Une règle par ligne sur téléphone : à deux colonnes, « Une lettre
         majuscule » tombe dans cent quarante-six pixels et repasse à la ligne,
         ce qui fait une liste de quatre règles sur six lignes mal alignées. -->
    <ul class="grid grid-cols-1 gap-x-4 gap-y-1.5 mt-2.5 sm:grid-cols-2">
        <li
            v-for="criterion in criteria"
            :key="criterion.key"
            class="flex items-center gap-1.5 text-xs transition-colors duration-200"
            :class="criterion.met ? 'text-emerald-500' : 'text-muted'"
        >
            <span
                class="shrink-0 w-3.5 h-3.5 rounded-full flex items-center justify-center transition-colors duration-200"
                :class="criterion.met ? 'bg-emerald-500/20' : 'bg-surface-3'"
            >
                <Check v-if="criterion.met" class="w-2.5 h-2.5" :stroke-width="3" />
                <span v-else class="w-1 h-1 rounded-full bg-current opacity-40" />
            </span>
            {{ criterion.label }}
        </li>
    </ul>
</template>
