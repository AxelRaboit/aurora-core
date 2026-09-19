<script setup>
/**
 * Choisir quelqu'un : pour lui parler en privé, ou pour l'ajouter à un canal.
 *
 * **Une modale plutôt qu'une liste qui se déplie dans le rail.** Le rail fait
 * deux cents pixels et sert à naviguer ; y faire pousser un second menu déplace
 * ce qu'on regardait et donne deux listes qui se ressemblent. Une modale pose
 * la question au milieu de l'écran, avec de la place pour les noms, et rend la
 * main au même endroit.
 *
 * Un seul composant pour les deux usages, parce que c'est la même question posée
 * deux fois : le titre change, pas le geste.
 */
import { useI18n } from "vue-i18n";
import { Users } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    /**
     * Pourquoi la question est posée, renvoyé tel quel avec la réponse.
     *
     * **Rendu plutôt que relu.** L'appelant tenait l'intention dans une
     * variable et la relisait au moment du clic ; entre les deux, la fermeture
     * de la modale la remettait à zéro, et « Ajouter quelqu'un » finissait par
     * ouvrir une conversation privée. Une réponse qui porte sa question ne peut
     * pas se tromper de question.
     */
    purpose: { type: String, default: null },
    /** Qui peut être choisi. Vide quand il n'y a personne à proposer. */
    people: { type: Array, default: () => [] },
    emptyLabel: { type: String, default: "" },
});

const emit = defineEmits(["close", "pick"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :title="title"
        :icon="Users"
        v-on:close="emit('close')"
    >
        <p v-if="!people.length" class="text-sm text-muted">
            {{ emptyLabel || t("shared.space_chat.channels.nobody_left") }}
        </p>

        <ul v-else class="flex flex-col gap-1">
            <li v-for="person in people" :key="person.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-primary transition-colors hover:bg-surface-2/70"
                    v-on:click="emit('pick', { id: person.id, purpose })"
                >
                    <!-- L'initiale plutôt qu'un avatar : il n'y a pas de photo
                         dans un espace, et un rond vide serait un trou. -->
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-2 text-xs font-medium text-secondary"
                    >
                        {{ (person.label ?? "?").slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ person.label }}</span>
                </button>
            </li>
        </ul>
    </AppModal>
</template>
