<script setup>
import { computed } from "vue";
import { Info, AlertTriangle, AlertCircle, CheckCircle2, Trash2, X } from "lucide-vue-next";

const props = defineProps({
    variant: { type: String, default: "info" },
    icon: { type: [String, Boolean], default: true },
    /**
     * Une croix pour le mettre de côté. Le message ne décide pas pour combien
     * de temps : il émet `dismiss`, et c'est l'appelant qui le cache - pour la
     * visite, le plus souvent, afin qu'il revienne au rechargement.
     */
    dismissible: { type: Boolean, default: false },
    /**
     * Le nom de la croix, pour l'infobulle et les lecteurs d'écran. Passé par
     * l'appelant plutôt que traduit ici : le message se monte partout, y
     * compris dans des tests qui n'installent pas les traductions.
     */
    dismissLabel: { type: String, default: "" },
});

const emit = defineEmits(["dismiss"]);

const VARIANTS = {
    // Expliquer n'est pas alerter.
    //
    // Les cinq autres variantes sont des couleurs d'état : il s'est passé
    // quelque chose, et la couleur dit quoi. Une phrase qui explique comment
    // marche l'écran qu'on a sous les yeux n'annonce rien, et une boîte bleue
    // la fait crier plus fort qu'elle ne parle. La fiche client du workspace
    // s'était écrit son propre bloc gris pour cette raison - à raison sur le
    // fond, et seule de son espèce dans tout le code.
    neutral: "border-line bg-surface-2/40 text-muted",
    info: "border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-300",
    success: "border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300",
    warning: "border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300",
    danger: "border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-300",
    trash: "border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-300",
};

const DEFAULT_ICONS = {
    neutral: Info,
    info: Info,
    success: CheckCircle2,
    warning: AlertTriangle,
    danger: AlertCircle,
    trash: Trash2,
};

const variantClass = computed(() => VARIANTS[props.variant] ?? VARIANTS.info);
const IconComponent = computed(() => (props.icon === false ? null : DEFAULT_ICONS[props.variant] ?? Info));
</script>

<template>
    <div class="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm" :class="variantClass">
        <slot name="icon">
            <component :is="IconComponent" v-if="IconComponent" class="w-4 h-4 shrink-0 mt-0.5" :stroke-width="2" />
        </slot>
        <div class="flex-1 min-w-0">
            <slot />
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2 shrink-0">
            <slot name="actions" />
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="-mr-1.5 -mt-1 shrink-0 rounded-md p-1.5 opacity-70 transition-opacity hover:opacity-100 focus-visible:opacity-100"
            :title="dismissLabel || undefined"
            :aria-label="dismissLabel || undefined"
            v-on:click="emit('dismiss')"
        >
            <X class="h-4 w-4" :stroke-width="2" />
        </button>
    </div>
</template>
