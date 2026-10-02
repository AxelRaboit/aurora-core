<script setup>
const props = defineProps({
    color: { type: String, default: "default" },
    // Une seule taille : trente pixels au doigt, l'ancien serrage à la souris.
    // Une variante `compact` a existé et n'a jamais été appelée - zéro fois sur
    // quatre-vingt-dix-sept boutons - donc elle promettait un choix que
    // personne ne faisait et qu'il aurait fallu maintenir.
    size: { type: String, default: "md" },
    title: { type: String, default: null },
    ariaLabel: { type: String, default: null },
    href: { type: String, default: null },
    /**
     * Un interrupteur allumé (panneau ouvert, vue choisie) : l'icône prend la
     * couleur d'accent sur un fond teinté. Remplace les `variant="primary"`
     * que des appels passaient et que ce composant n'a jamais lus.
     */
    active: { type: Boolean, default: false },
    /**
     * L'icône, quand l'appel ne la passe pas dans le slot. Quinze boutons de
     * l'éditeur de grille (monter, descendre, retirer un onglet, une image,
     * une diapositive) passaient `:icon` à un composant qui ne le lisait pas :
     * ils s'affichaient vides (vu le 02/10/2026).
     */
    icon: { type: [Object, Function], default: null },
});

const colors = {
    default: { text: "text-secondary hover:text-primary",    bg: "hover:bg-surface-2" },
    sky:     { text: "text-secondary hover:text-sky-400",     bg: "hover:bg-surface-2" },
    accent:  { text: "text-secondary hover:text-accent-400",  bg: "hover:bg-surface-2" },
    rose:    { text: "text-secondary hover:text-rose-400",    bg: "hover:bg-rose-500/10" },
    // Le nom que cinq appels de l'éditeur de grille utilisaient : il retombait
    // sur `default` et leurs corbeilles ne rougissaient jamais.
    danger:  { text: "text-secondary hover:text-rose-400",    bg: "hover:bg-rose-500/10" },
    emerald: { text: "text-secondary hover:text-emerald-400", bg: "hover:bg-emerald-500/10" },
    amber:   { text: "text-secondary hover:text-amber-400",   bg: "hover:bg-surface-2" },
    // For use on bright, non-Aurora surfaces (post-it sticky notes, light
    // overlays, custom-colored cards). Aurora tokens (text-secondary etc.)
    // assume a dark surface and become invisible on bright backgrounds -
    // this variant ships a dark-on-light palette tuned for that case.
    "on-light": { text: "text-black/50 hover:text-black/80",  bg: "hover:bg-black/10" },
};

/**
 * **Trente pixels sous le pouce, la taille d'avant à la souris.**
 *
 * Six pixels de rembourrage autour d'une icône de quatorze font une cible de
 * vingt-six, ce qui va très bien à un curseur et mal à un doigt : c'est la
 * mesure qui a fait passer les onglets d'un espace à trente ce matin, et ces
 * quatre-vingt-dix-sept boutons y échappaient encore.
 *
 * Un minimum et non une taille fixe : une icône de seize pixels garde son air
 * autour d'elle au lieu d'être rognée. Et seulement sous `sm`, parce que
 * grossir toutes les barres d'outils du back-office pour une précision que la
 * souris a déjà serait payer un problème que personne n'a.
 */
const sizes = {
    md: "p-1.5 min-h-7.5 min-w-7.5 justify-center sm:min-h-0 sm:min-w-0",
};

// Always project a label to assistive tech: prefer explicit ariaLabel, fall back to title.
// Components that pass neither will render an unlabelled button - caught by lint:a11y in CI.
const computedAriaLabel = props.ariaLabel ?? props.title ?? undefined;
const resolvedColor = colors[props.color] ?? colors.default;
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        v-bind="href ? { href } : { type: 'button' }"
        :title="title"
        :aria-label="computedAriaLabel"
        class="rounded transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed"
        :class="[sizes[size] ?? sizes.md, active ? 'text-accent-400 bg-accent-500/15' : resolvedColor.text, resolvedColor.bg]"
        :aria-pressed="active || undefined"
    >
        <slot>
            <component :is="icon" v-if="icon" class="w-4 h-4" :stroke-width="2" />
        </slot>
    </component>
</template>
