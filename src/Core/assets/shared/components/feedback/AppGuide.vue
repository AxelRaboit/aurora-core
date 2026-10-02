<script setup>
/**
 * Un encart « Comment ça marche », à côté de ce qu'il explique.
 *
 * **À côté, pas dans une aide à part.** Une documentation qu'on va chercher
 * ailleurs n'est pas lue au moment où l'on hésite ; un encart posé près du
 * geste l'est. Il dit ce que fait l'écran, dans l'ordre où on s'en sert, et
 * confirme ce qui va se passer avant qu'on clique.
 *
 * **Un seul choix pour tous les encarts** (`useGuidePreference`) : replier
 * celui-ci les replie tous, et le choix est retenu. Tant que le lecteur n'a
 * rien choisi, l'encart suit `open` ; un mode d'emploi d'intégration reste
 * ainsi ouvert tant que rien n'est branché.
 *
 * Le contenu est libre : des étapes (`<ol>`), une phrase, un lien. Le style
 * du texte est posé ici, pour que tous les encarts se lisent pareil.
 */
import { computed } from "vue";
import { BookOpen, ChevronDown } from "lucide-vue-next";
import { useGuidePreference } from "@/shared/composables/useGuidePreference.js";

const props = defineProps({
    title: { type: String, required: true },
    /** Ouvert au départ, tant que le lecteur n'a rien choisi. */
    open: { type: Boolean, default: true },
    /** Accepté et ignoré : le choix d'ouvrir ou de replier est commun à tous les encarts. */
    storageKey: { type: String, default: "" },
});

const { choice, remember } = useGuidePreference();

const isOpen = computed(() => choice.value ?? props.open);

/**
 * `toggle` part aussi quand l'état change par le code (un autre encart vient
 * d'être replié) : seul un écart avec l'état attendu vient du lecteur.
 */
function onToggle(event) {
    if (event.target.open === isOpen.value) return;

    remember(event.target.open);
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
