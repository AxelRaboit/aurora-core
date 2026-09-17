<script setup>
/**
 * The team of a space, in the width of a table cell.
 *
 * Faces rather than a number, because that is what the cell is scanned for: a
 * reader goes down the column looking for the spaces that are theirs, and an
 * initial is recognised where "2 personnes" has to be read. The count was the
 * first attempt and it wrapped in the cell, which is the shape of a label doing
 * a picture's job.
 *
 * Three at most, then a "+n". Past three the circles stop being separable at
 * this size, and the whole point is that they are read at a glance; the names
 * live one click away, where they have room.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";

const props = defineProps({
    members: { type: Array, default: () => [] },
});

defineEmits(["open"]);

const { t } = useI18n();

const MAX_FACES = 3;

const shown = computed(() => props.members.slice(0, MAX_FACES));

const overflow = computed(() => Math.max(0, props.members.length - MAX_FACES));

/** What a screen reader hears, and what the tooltip says. */
const label = computed(() =>
    t(
        "backend.studio.spaces.team_button",
        { count: props.members.length },
        props.members.length,
    ),
);
</script>

<template>
    <button
        v-if="members.length"
        type="button"
        class="group/team flex items-center rounded-full py-0.5 pr-1.5 transition-colors hover:bg-surface-2"
        :title="label"
        :aria-label="label"
        v-on:click="$emit('open')"
    >
        <span class="flex -space-x-2">
            <!-- Un fond opaque sous chaque rond, et c'est tout ce que fait ce
                 conteneur. L'avatar est peint en `bg-accent-600/20` : posé
                 directement sur son voisin, il le laissait transparaître et
                 les deux initiales se chevauchaient. Sur un fond de la couleur
                 de la ligne, il rend exactement ce qu'il rend seul, et celui du
                 dessus masque la part qu'il recouvre - ce que font les piles
                 d'avatars partout ailleurs.

                 La couleur suit le survol comme l'anneau le faisait déjà :
                 les deux disent la même chose, « ce qu'il y a derrière ». -->
            <span
                v-for="member in shown"
                :key="member.userId"
                class="rounded-full bg-surface ring-2 ring-surface transition-colors group-hover/team:bg-surface-2 group-hover/team:ring-surface-2"
            >
                <AppAvatar
                    :name="member.name"
                    :email="member.email"
                    size="sm"
                    class="block"
                />
            </span>
            <span
                v-if="overflow"
                class="flex h-7 w-7 items-center justify-center rounded-full bg-surface-2 text-xs font-medium tabular-nums text-secondary ring-2 ring-surface"
            >
                +{{ overflow }}
            </span>
        </span>
    </button>

    <span v-else class="text-sm text-muted">
        {{ t("backend.studio.spaces.no_members") }}
    </span>
</template>
