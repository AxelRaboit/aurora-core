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
 * **Deux gestes se partagent la ligne, trois et plus s'empilent.** La règle
 * suit ce que la largeur permet : à deux, chaque moitié fait cent soixante
 * pixels, assez pour « Supprimer » et juste ce qu'il faut pour que la rangée
 * remplisse la carte au lieu d'étirer deux mots sur trois cent quarante. À
 * trois, les colonnes tomberaient à cent dix et « Prévisualiser » n'y tient
 * plus : l'empilement rend à chaque geste une cible franche.
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
    <div class="grid gap-0.5" :class="paired ? 'grid-cols-2' : 'grid-cols-1'">
        <AppActionButton
            v-for="action in actions"
            :key="action.key"
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
