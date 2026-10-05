<script setup>
/**
 * Perso ou partagé : le choix du rayon d'un livrable de Studio, le même à la
 * création et dans les réglages.
 *
 * Deux grandes cases plutôt qu'un sélecteur : le choix engage (qui le lit),
 * et la phrase qui l'explique doit être lue, pas devinée derrière un clic.
 */
import { useI18n } from "vue-i18n";
import { Lock, Users } from "lucide-vue-next";

const scope = defineModel({ type: String, default: "personal" });

defineProps({
    /** Sans le droit de changer ce choix, les cases se lisent et ne bougent pas. */
    disabled: { type: Boolean, default: false },
});

const SCOPES = ["personal", "shared"];

const { t } = useI18n();
</script>

<template>
    <div class="grid gap-2 sm:grid-cols-2" role="group" :aria-label="t('backend.studio.deliverables.scope.label')">
        <button
            v-for="value in SCOPES"
            :key="value"
            type="button"
            class="rounded-lg border p-3 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60"
            :class="scope === value ? 'border-accent bg-accent/10' : 'border-line hover:border-line-strong'"
            :aria-pressed="scope === value"
            :disabled="disabled"
            v-on:click="scope = value"
        >
            <span class="flex items-center gap-1.5 text-sm font-medium text-primary">
                <component :is="'shared' === value ? Users : Lock" class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t(`backend.studio.deliverables.scope.${value}`) }}
            </span>
            <span class="mt-0.5 block text-xs text-muted">{{ t(`backend.studio.deliverables.scope.${value}_hint`) }}</span>
        </button>
    </div>
</template>
