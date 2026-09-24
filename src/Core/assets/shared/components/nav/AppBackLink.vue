<script setup>
/**
 * Le lien qui remonte d'un écran.
 *
 * Quatre écrans le portaient, sous quatre formes : un bouton fantôme sur
 * l'éditeur de publication, une ancre avec une flèche dans la médiathèque,
 * une ancre avec un chevron et un libellé qui s'efface sur téléphone dans un
 * espace client, et un bouton de texte dans les notes. Le même geste, quatre
 * dessins, dont un seul est juste.
 *
 * **Ce n'est pas une action, c'est une navigation.** Un bouton plein ou
 * fantôme le met au même niveau visuel qu'« Enregistrer », posé à un
 * centimètre de là : sur un téléphone où les deux se retrouvent l'un sous
 * l'autre, l'écran n'a plus de hiérarchie. Un lien discret le dit pour ce
 * qu'il est.
 *
 * **Le libellé s'efface sous `sm`, partout, sans exception.** C'est la
 * trouvaille de l'espace client, reprise ici : « Retour à la liste » prend
 * près de la moitié de la barre pour un mot que le chevron dit déjà, et sur
 * un téléphone cette moitié manque ailleurs. Il reste lu par un lecteur
 * d'écran dans les deux cas, parce que `aria-label` le porte.
 *
 * Pas d'échappatoire pour le garder visible : une option pour faire
 * autrement est une invitation à ce que chaque écran décide, et c'est
 * exactement ce que ce composant vient corriger.
 */
import { ChevronLeft } from "lucide-vue-next";

defineProps({
    /** Une adresse, ou rien : sans elle, le composant émet `back`. */
    href: { type: String, default: "" },
    label: { type: String, required: true },
});

const emit = defineEmits(["back"]);
</script>

<template>
    <!-- Une ancre quand il y a une adresse, un bouton sinon : le carnet de
         notes revient à sa bibliothèque sans changer de page, et une ancre
         vide serait un lien qui ne mène nulle part pour qui navigue au
         clavier. Les deux portent le même dessin. -->
    <component
        :is="href ? 'a' : 'button'"
        :href="href || undefined"
        :type="href ? undefined : 'button'"
        :aria-label="label"
        class="-ml-2 flex shrink-0 items-center gap-1.5 rounded-md px-2 py-2 text-sm text-muted transition-colors hover:bg-surface-2 hover:text-primary sm:py-1"
        v-on:click="href ? undefined : emit('back')"
    >
        <ChevronLeft class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
        <span class="hidden sm:inline">{{ label }}</span>
    </component>
</template>
