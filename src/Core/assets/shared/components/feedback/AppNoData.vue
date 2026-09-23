<script setup>
import { Inbox } from "lucide-vue-next";

defineProps({
    message: { type: String, default: "Aucune donnée à afficher." },
    /** Secondary line under the message - usually what to do about the emptiness. */
    hint: { type: String, default: "" },
    /**
     * Ce qu'on dessine au-dessus, quand la boîte vide ne dit pas assez.
     *
     * Huit écrans en passaient déjà un, et le recevaient en attribut inconnu
     * : l'icône n'apparaissait pas, et comme ces mêmes appels nommaient leur
     * texte `title` plutôt que `message`, ils affichaient tous la phrase par
     * défaut à la place de la leur. Un vide qui ne dit rien ressemble à une
     * page cassée, ce qui est exactement ce qu'Axel a signalé le 23/09.
     */
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <div class="w-full flex flex-col items-center justify-center gap-3 py-10 text-muted text-center">
        <component :is="icon ?? Inbox" class="w-8 h-8 opacity-40" :stroke-width="1.5" />
        <!-- Message and hint share a tighter group of their own: they are one
             statement, and the outer gap-3 would read as two. -->
        <div class="flex flex-col gap-1">
            <p class="text-sm">{{ message }}</p>
            <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        </div>
        <!-- The way out of the emptiness, when there is one. A page whose whole
             content is "nothing here yet" is the right place for the button that
             creates the first thing, rather than making the reader hunt for it
             in a sidebar that holds nothing else. -->
        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
