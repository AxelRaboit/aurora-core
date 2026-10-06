<script setup>
/**
 * Personal or shared: the choice of a Studio deliverable's shelf, the same at
 * creation and in the settings.
 *
 * Two large boxes rather than a selector: the choice matters (who reads it),
 * and the sentence explaining it must be read, not guessed behind a click.
 */
import { useI18n } from "vue-i18n";
import { Lock, Users } from "lucide-vue-next";

const scope = defineModel({ type: String, default: "personal" });

defineProps({
    /** Without the right to change this choice, the boxes can be read and do not move. */
    disabled: { type: Boolean, default: false },
});

const SCOPES = ["personal", "shared"];

const { t } = useI18n();
</script>

<template>
    <div class="grid gap-2 sm:grid-cols-2" role="group" :aria-label="t('suite.studio.deliverables.scope.label')">
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
                {{ t(`suite.studio.deliverables.scope.${value}`) }}
            </span>
            <span class="mt-0.5 block text-xs text-muted">{{ t(`suite.studio.deliverables.scope.${value}_hint`) }}</span>
        </button>
    </div>
</template>
