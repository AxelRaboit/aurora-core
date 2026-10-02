<script setup>
import { computed } from "vue";
import { Loader2 } from "lucide-vue-next";

const props = defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    href: { type: String, default: null },
    /**
     * Visual "selected" state. Currently honored by the `nav` variant -
     * swaps the inactive surface styling for the accent-tinted look used
     * by sidemenu lists (taxonomies / post types).
     */
    active: { type: Boolean, default: false },
    /**
     * Le nom du bouton, quand il accompagne une icône passée dans le slot.
     * Obligatoire avec `iconOnlyOnPhone` : c'est lui qui reste en infobulle et
     * pour les lecteurs d'écran quand le mot disparaît.
     */
    label: { type: String, default: null },
    /**
     * **Une commande de barre, en icône seule sous `sm`** (02/10/2026). Le mot
     * part, le bouton devient un carré de la hauteur de ses voisins, et garde
     * son fond ou son filet : dans une barre, une commande est un vrai bouton.
     * Réservé aux barres qui restent horizontales ; une commande pleine largeur
     * garde son nom (voir {@see AppPageActions}).
     */
    iconOnlyOnPhone: { type: Boolean, default: false },
    /**
     * Toujours en icône seule, à toutes les largeurs : une bascule de barre
     * (favori) dont le nom tiendrait mal à côté des autres. Même carré, même
     * fond, le nom en infobulle et pour les lecteurs d'écran.
     */
    iconOnly: { type: Boolean, default: false },
});

/**
 * Click handler that fixes the common modal-footer-submit pattern: a
 * `type="submit"` button placed in `<AppModalFooter>` lives OUTSIDE the
 * `<form>` it logically belongs to (form sits in the modal's default
 * slot, footer in `#footer` - they are siblings). Per HTML spec, a
 * submit button without a form owner does nothing on click. To keep
 * the natural authoring pattern working, manually call `requestSubmit()`
 * on the form located in the same modal/dialog scope.
 *
 * No-op when the button is already inside a `<form>` (native browser
 * handling takes over) or when `type !== "submit"`.
 */
function onClick(event) {
    if (props.type !== 'submit') return;
    if (props.disabled || props.loading) return;

    const button = event.currentTarget;
    if (button.form) return; // browser will submit natively

    // Look for the closest dialog/modal scope; fall back to document.
    const scope = button.closest('[role="dialog"]') ?? document;
    const form = scope.querySelector('form');
    if (form) {
        event.preventDefault();
        // `requestSubmit()` without the submitter arg - passing the button
        // would throw `NotFoundError: not owned by this form element`
        // precisely because the button lives in a sibling slot
        // (e.g. AppModal footer), which is the case we're working around.
        form.requestSubmit();
    }
}

const base = 'inline-flex items-center justify-center gap-2 rounded-lg transition duration-150 ease-in-out focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed';

const variants = {
    // **Un filet transparent sur les boutons pleins** (02/10/2026) : sans lui,
    // un primary faisait deux pixels de moins qu'un secondary ou qu'un ghost
    // posé à côté (36 contre 38 en md), et une barre d'entête alignait trois
    // hauteurs différentes. Tous les boutons d'une taille ont la même hauteur.
    primary: 'bg-accent-600 hover:bg-accent-700 text-white border border-transparent focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-accent-500',
    secondary: 'bg-surface-3 hover:bg-surface-2 text-primary border border-line focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-base',
    danger: 'bg-rose-600 hover:bg-rose-500 text-white border border-transparent focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-rose-500',
    'danger-outline': 'bg-transparent hover:bg-rose-500/10 text-rose-400 border border-line focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-rose-500',

    accent: 'bg-accent hover:bg-accent-hover text-white border border-transparent focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-accent',
    // **Jamais transparent sur téléphone.** Un bouton fantôme se lit à la
    // souris : il attend le survol pour exister, et sa place dans une barre
    // dense dit déjà que c'en est un. Au doigt, il n'y a pas de survol, et un
    // bouton pleine largeur sans fond ressemble à une ligne de texte - on ne
    // sait pas qu'on peut appuyer. Sous `sm` il porte donc une surface sourde
    // et un filet ; au-dessus, il redevient le fantôme qu'il doit être.
    ghost: 'bg-surface-2 border border-line text-secondary hover:bg-surface-2 hover:text-primary sm:border-transparent sm:bg-transparent',
    dashed: 'bg-transparent border-2 border-dashed border-line text-secondary hover:bg-surface-2 hover:text-primary',
    link: 'bg-transparent text-muted hover:text-secondary underline p-0 text-sm',
    'link-accent': 'bg-transparent text-accent hover:underline p-0 text-sm',
    icon: 'bg-transparent p-0',
    // Sidemenu list entry: full-width, left-aligned, surface card. Combine with `active` to highlight the selected row.
    nav: '!justify-start text-left w-full bg-surface hover:bg-surface-2 text-primary border border-line',
    // Frontend - use inside a parent that sets the text color via CSS variable
    'front-ghost': 'bg-transparent hover:opacity-80 transition-opacity',
    'front-primary': 'bg-transparent text-primary hover:opacity-80 transition-opacity',
    'front-accent': 'bg-transparent font-medium text-accent hover:opacity-80 transition-opacity',
};

const activeStyles = {
    nav: 'bg-accent-600/15 hover:bg-accent-600/15 text-accent-400 border-accent-600/30',
    // Une bascule de barre allumée (panneau ouvert) : la teinte d'accent, le
    // même dessin que l'onglet actif d'un sélecteur posé à côté.
    secondary: 'bg-accent-600/15 hover:bg-accent-600/20 text-accent-400 border-accent-600/30',
};

const sizes = {
    sm: 'py-1.5 px-3 text-xs',
    md: 'py-2 px-4 text-sm',
    lg: 'py-3 px-6 text-base',
    nav: 'px-3 py-2 text-sm',
    none: '',
};

// Le carré d'une commande en icône seule, à la hauteur de la taille : un
// bouton md fait 38 px de haut avec son filet, donc 38 de large.
// La hauteur aussi : sans le mot, il ne reste que l'icône (16 px) pour la
// donner, et le carré tombait à 34 px de haut à côté de voisins de 38.
const squares = {
    sm: 'max-sm:size-7.5 max-sm:p-0',
    md: 'max-sm:size-9.5 max-sm:p-0',
    lg: 'max-sm:size-12.5 max-sm:p-0',
};
const alwaysSquares = {
    sm: 'size-7.5 p-0',
    md: 'size-9.5 p-0',
    lg: 'size-12.5 p-0',
};

// `icon` porte son propre rembourrage : celui de la taille passait devant (Tailwind
// range `padding` avant `padding-inline`) et donnait une cible de 26 × 38.
// Pareil pour `iconOnly` : son `p-0` passait après le `px-4` de la taille et
// l'icône tombait à six pixels de large (vu le 02/10/2026). Les variantes
// `max-sm:` d'`iconOnlyOnPhone` arrivent après dans la feuille et gagnent seules.
const sizeClass = computed(() => ('icon' === props.variant || props.iconOnly ? '' : (sizes[props.size] ?? sizes.md)));
const squareClass = computed(() => {
    if (props.iconOnly) return alwaysSquares[props.size] ?? alwaysSquares.md;

    return props.iconOnlyOnPhone ? (squares[props.size] ?? squares.md) : '';
});
// Le mot part sous `sm` (ou toujours) mais reste lu : jamais `hidden`.
const labelClass = computed(() => {
    if (props.iconOnly) return 'sr-only';

    return props.iconOnlyOnPhone ? 'sr-only sm:not-sr-only' : '';
});
const titleText = computed(() => (props.iconOnly || props.iconOnlyOnPhone ? props.label : undefined));
</script>

<template>
    <a
        v-if="href"
        :href="href"
        :title="titleText"
        :class="[base, variants[variant] ?? variants.primary, sizeClass, squareClass, active ? activeStyles[variant] ?? '' : '']"
    >
        <slot />
        <span v-if="label" :class="labelClass">{{ label }}</span>
    </a>
    <button
        v-else
        :type="type"
        :disabled="disabled || loading"
        :title="titleText"
        :class="[base, variants[variant] ?? variants.primary, sizeClass, squareClass, active ? activeStyles[variant] ?? '' : '']"
        v-on:click="onClick"
    >
        <Loader2 v-if="loading" class="animate-spin h-4 w-4" :stroke-width="2" />
        <slot />
        <span v-if="label" :class="labelClass">{{ label }}</span>
    </button>
</template>
