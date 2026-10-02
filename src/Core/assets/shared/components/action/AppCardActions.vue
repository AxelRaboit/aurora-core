<script setup>
/**
 * Les gestes d'une fiche, posés à plat.
 *
 * **La carte d'un téléphone est déjà la feuille d'actions ouverte.** Dans un
 * tableau, les gestes se rangent derrière trois points parce qu'une colonne
 * n'a pas la largeur de les nommer ; une carte, elle, occupe toute la ligne et
 * la place ne manque pas. Les cacher derrière un bouton y ajoute un geste pour
 * n'économiser aucun pixel, et pose une feuille modale par-dessus la liste
 * qu'on était en train de lire - un menu dans un menu.
 *
 * C'est le corps de {@see AppActionSheet}, sans sa modale : mêmes lignes, même
 * ordre, mêmes couleurs, parce que c'est la même chose montrée sans être
 * ouverte. Un lecteur qui passe du tableau à la carte retrouve les mêmes mots
 * au même endroit.
 *
 * Une action a la forme que {@see AppActionSheet} documente ;
 * {@see AppActionButton} dessine chaque ligne.
 *
 * **Sans les descriptions, elles.** Une phrase sous chaque geste a sa place
 * dans une feuille qu'on vient d'ouvrir, devant une décision ; répétée sous
 * chaque carte d'une liste de neuf, elle double la hauteur de la page pour
 * redire neuf fois ce qu'on a lu la première. Le titre reste, et la phrase
 * attend la feuille.
 *
 * **Centrés, parce que ce sont des boutons et non des entrées de liste.** Dans
 * la feuille, on cherche un geste parmi d'autres et les libellés s'alignent sur
 * un bord commun ; sur une carte, chaque rangée est un geste qu'on vise, et la
 * paire icône + mot se pose au milieu de sa ligne. L'icône voyage avec son mot,
 * jamais accrochée au bord pendant que le texte se déplace.
 *
 * **Sur téléphone, toujours l'un sous l'autre, en vrais boutons pleine
 * largeur** (décision d'Axel du 02/10/2026). Deux gestes se partageaient la
 * ligne, et posés sans fond ni filet ils se lisaient comme deux mots au milieu
 * de la carte, pas comme deux cibles. Chacun prend désormais sa ligne, avec la
 * surface et le filet d'un bouton secondaire ; un bouton n'est jamais
 * transparent sur téléphone. À partir de `sm`, la carte retrouve la rangée
 * légère d'avant, deux gestes côte à côte quand ils sont deux.
 *
 * **Au-delà de quatre ou cinq gestes**, la feuille reprend l'avantage : une
 * carte qui fait deux écrans de haut n'est plus une carte. Les listes qui en
 * arrivent là gardent {@see AppRowActions}.
 */
import { computed } from "vue";
import AppActionButton from "./AppActionButton.vue";

const props = defineProps({
    /** Ce que la fiche offre, dans l'ordre où on doit les lire. */
    actions: { type: Array, required: true },
});

const paired = computed(() => 2 === props.actions.length);

/**
 * Fermer avant d'agir, comme dans la feuille.
 *
 * Ici rien n'est à fermer : la carte reste. Le `onSelect` est donc appelé
 * directement, et le garde `disabled` / `loading` est celui du bouton.
 */
function run(action) {
    if (action.disabled || action.loading) return;

    action.onSelect?.();
}
</script>

<template>
    <div class="grid grid-cols-1 gap-2 sm:gap-0.5" :class="paired ? 'sm:grid-cols-2' : ''">
        <AppActionButton
            v-for="action in actions"
            :key="action.key"
            boxed
            align="center"
            :title="action.title"
            :color="action.color ?? 'default'"
            :href="action.href"
            :disabled="action.disabled ?? false"
            :loading="action.loading ?? false"
            v-on:click="run(action)"
        >
            <template v-if="action.icon" #icon>
                <component :is="action.icon" class="w-4 h-4" :stroke-width="2" />
            </template>
        </AppActionButton>
    </div>
</template>
