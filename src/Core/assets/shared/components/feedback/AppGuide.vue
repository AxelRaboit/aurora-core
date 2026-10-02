<script setup>
/**
 * Un encart « Comment ça marche », à côté de ce qu'il explique.
 *
 * **À côté, pas dans une aide à part.** Une documentation qu'on va chercher
 * ailleurs n'est pas lue au moment où l'on hésite ; un encart posé près du
 * geste l'est. Il dit ce que fait l'écran, dans l'ordre où on s'en sert, et
 * confirme ce qui va se passer avant qu'on clique.
 *
 * **Repliable, et retenu.** Un mode d'emploi sert tant qu'on découvre ; relu
 * à chaque visite, il devient du bruit. Avec `storageKey`, le choix d'ouvrir
 * ou de replier est gardé dans le navigateur ; sans lui, l'encart suit
 * `open`. Le stockage peut manquer (navigation privée) : l'encart retombe
 * alors sur `open`, sans erreur.
 *
 * Le contenu est libre : des étapes (`<ol>`), une phrase, un lien. Le style
 * du texte est posé ici, pour que tous les encarts se lisent pareil.
 */
import { ref, watch } from "vue";
import { BookOpen, ChevronDown } from "lucide-vue-next";

const props = defineProps({
    title: { type: String, required: true },
    /** Ouvert au départ ; ignoré quand le lecteur a déjà choisi (voir `storageKey`). */
    open: { type: Boolean, default: true },
    /** Une clé pour retenir le choix du lecteur d'une visite à l'autre. */
    storageKey: { type: String, default: "" },
});

const STORAGE_PREFIX = "aurora.guide.";

function stored() {
    if (!props.storageKey) return null;
    try {
        const value = window.localStorage.getItem(STORAGE_PREFIX + props.storageKey);

        return null === value ? null : "1" === value;
    } catch {
        return null;
    }
}

const isOpen = ref(stored() ?? props.open);

// Sans choix retenu, l'encart suit son appelant : un mode d'emploi qui se
// replie une fois l'intégration branchée, par exemple.
watch(
    () => props.open,
    (value) => {
        if (null === stored()) isOpen.value = value;
    },
);

function onToggle(event) {
    isOpen.value = event.target.open;
    if (!props.storageKey) return;
    try {
        window.localStorage.setItem(STORAGE_PREFIX + props.storageKey, isOpen.value ? "1" : "0");
    } catch {
        // Le stockage refusé ne change rien à l'encart, seulement à la mémoire.
    }
}
</script>

<template>
    <!-- Une région nommée plutôt qu'un <aside> ou une <section> : les écrans
         gardent ces balises pour leurs volets et leurs groupes, et leurs
         tests les comptent. `data-guide` le désigne sans ambiguïté. -->
    <div data-guide role="region" class="min-w-0 rounded-lg border border-dashed border-line p-3 sm:p-4" :aria-label="title">
        <details :open="isOpen" class="group" v-on:toggle="onToggle">
            <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-primary [&::-webkit-details-marker]:hidden">
                <BookOpen class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                <span class="min-w-0 flex-1">{{ title }}</span>
                <ChevronDown class="h-4 w-4 shrink-0 text-muted transition-transform group-open:rotate-180" :stroke-width="2" />
            </summary>
            <div class="mt-3 flex flex-col gap-3 text-sm text-secondary">
                <slot />
            </div>
        </details>
    </div>
</template>
