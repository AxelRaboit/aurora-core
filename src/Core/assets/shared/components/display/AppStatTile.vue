<script setup>
/**
 * Un chiffre du tableau de bord, avec ce qu'il compte.
 *
 * **La même tuile pour tous les panneaux.** Chacun la redessinait, et le
 * détail qui s'était perdu en route est l'alignement : un libellé qui passe
 * sur deux lignes poussait son nombre d'une ligne vers le bas, et deux tuiles
 * côte à côte affichaient leurs chiffres à deux hauteurs différentes. Une
 * rangée de tuiles est un objet répété : mêmes bords, mêmes lignes de base.
 *
 * Le libellé prend la place qu'il lui faut et le nombre se pose au bas de la
 * tuile, donc l'alignement ne dépend plus de la longueur des mots.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    icon: { type: [Object, Function], default: null },
    /**
     * Une nuance quand le chiffre demande une réaction plutôt qu'une lecture.
     * Discrète : un tableau de bord où tout est rouge ne dit plus rien.
     */
    tone: { type: String, default: "default" },
});

const TONES = {
    default: "text-primary",
    attention: "text-accent",
};
</script>

<template>
    <div class="aurora-card flex flex-col p-4">
        <div class="flex flex-1 items-start gap-2 text-xs uppercase tracking-wide text-secondary">
            <component :is="icon" v-if="icon" class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="2" />
            <span>{{ label }}</span>
        </div>
        <p class="mt-2 text-2xl font-semibold tabular-nums" :class="TONES[tone] ?? TONES.default">
            {{ value }}
        </p>
    </div>
</template>
