import { nextTick, ref } from "vue";

/**
 * Un contrôle qui vit replié en icône et se déploie au clic.
 *
 * La barre de la bibliothèque porte six choses ; celles dont on se sert par
 * à-coups - chercher, changer de tri - n'ont pas à occuper leur largeur en
 * permanence. C'est le geste de Craft, et il vaut deux fois ici, donc il
 * vit à un seul endroit.
 *
 * Le repli reste à l'appelant : une recherche ne se referme pas tant qu'on
 * a tapé dedans, un tri se referme dès qu'on a choisi. Ce qui est commun,
 * c'est l'ouverture et le fait de donner le clavier à ce qui apparaît.
 */
export function useFoldable() {
    const open = ref(false);
    const box = ref(null);

    /**
     * Déploie, puis donne le curseur au champ qui vient d'apparaître.
     *
     * Le `nextTick` n'est pas décoratif : le champ n'existe pas encore au
     * moment du clic, et `focus()` sur un élément absent ne fait rien.
     */
    async function reveal(selector = "input") {
        open.value = true;

        await nextTick();

        box.value?.querySelector(selector)?.focus();
    }

    function fold() {
        open.value = false;
    }

    return { open, box, reveal, fold };
}
