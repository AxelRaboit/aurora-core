<script setup>
/**
 * La barre d'entête d'un écran : le retour à gauche, les commandes à droite.
 *
 * **Une seule disposition, partout** (décision d'Axel du 02/10/2026). Avant
 * elle, chaque écran avait la sienne : le retour à gauche et les commandes
 * poussées à droite par un `justify-between` (publication), par un espaceur
 * (galerie), par un `ml-auto` (notes), ou collées derrière le retour (trame,
 * texte adapté), et sur ordinateur leur place dépendait de la longueur du
 * titre. Le titre n'entre pas dans la barre : il vient en dessous, sur sa
 * propre ligne, où il peut être long sans rien pousser.
 *
 * Les commandes y sont de vrais boutons, de la même taille (md, 38 px), et
 * passent en icône seule sous `sm` (`AppButton` `icon-only-on-phone`,
 * `AppPageActions` `icon-only-on-phone`) : la barre tient alors sur une ligne
 * à 375 px. Le retour reste une navigation, un chevron nu ({@see AppBackLink}).
 *
 * `start` reçoit ce qui accompagne le retour à gauche (rarement) ; le slot
 * par défaut, les commandes, dans l'ordre de lecture : la feuille « Actions »
 * puis le verbe principal, le plus à droite.
 */
import AppBackLink from "./AppBackLink.vue";

defineProps({
    /** Où mène le retour. Sans adresse, le retour émet `back`. */
    backHref: { type: String, default: null },
    /** Le nom du retour : visible à partir de `sm`, toujours lu par les lecteurs d'écran. */
    backLabel: { type: String, default: null },
});

const emit = defineEmits(["back"]);
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <AppBackLink v-if="backLabel" :href="backHref" :label="backLabel" v-on:back="emit('back')" />
        <slot name="start" />
        <div class="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2">
            <slot />
        </div>
    </div>
</template>
