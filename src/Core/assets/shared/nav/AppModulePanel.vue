<script setup>
/**
 * The chrome every module panel wears, so six of them do not each invent one.
 *
 * A panel is what a module hangs under its links in the side menu when a list
 * of destinations cannot express what the reader needs - a folder tree, a note
 * list. What they have in common is exactly this: a small heading, one optional
 * action beside it, and the three states of something that fetched its own data.
 *
 * It renders **nothing at all** when the fetch failed, and nothing when the
 * caller says the page already owns this surface. Both are the same judgement:
 * a panel that cannot do its job should take up no room, because the links
 * above it are the navigation and they are unaffected.
 */
import { useI18n } from "vue-i18n";

defineProps({
    /** Heading, already translated. */
    title: { type: String, required: true },
    loading: { type: Boolean, default: false },
    failed: { type: Boolean, default: false },
    /** Loaded, and there is nothing to show. */
    empty: { type: Boolean, default: false },
    /** What to say then, already translated. */
    emptyLabel: { type: String, default: "" },
});

const { t } = useI18n();
</script>

<template>
    <!-- `mt-1`, not `mt-2`: 4px is the clearance the menu gives a row on
         either side of a border - the figure the nav's own `py-1` uses at
         both ends of the list. At 8px the last link above sat visibly
         lower in its space than the first one sat below the border above
         it, and on a module whose panel follows a single highlighted card
         the lopsidedness is the first thing the eye finds. The caller used
         to pass `mt-1` here believing it applied; Tailwind emits `.mt-2`
         after `.mt-1`, so the panel's own class won and the override was
         dead. -->
    <section v-if="!failed" class="mt-1 border-t border-line pt-2">
        <!-- Les commandes passent à la ligne quand elles ne tiennent pas.
             
             Le nom du module et quatre icônes sur une seule ligne, dans une
             colonne de trois cents pixels, laissaient au nom de quoi écrire
             « NOTES MARKDO… ». Une seconde ligne imposée à tout le monde
             aurait coûté un rang à un panneau qui ne porte qu'une icône, et
             ils n'ont pas tous le même nombre. D'où le repli : le titre
             réclame dix rem, et les commandes descendent d'elles-mêmes
             quand la place manque. -->
        <header class="flex flex-wrap items-center gap-x-1.5 gap-y-1 px-3 pb-1">
            <h2
                class="min-w-0 flex-1 basis-40 truncate text-xs font-semibold uppercase tracking-wide text-muted"
            >
                {{ title }}
            </h2>
            <div v-if="$slots.action" class="ml-auto flex shrink-0 items-center gap-0.5">
                <slot name="action" />
            </div>
        </header>

        <p v-if="loading" class="px-3 py-1 text-xs text-muted">
            {{ t("shared.common.loading") }}
        </p>
        <p v-else-if="empty" class="px-3 py-1 text-xs text-muted">
            {{ emptyLabel }}
        </p>
        <div v-else class="flex flex-col gap-0.5">
            <slot />
        </div>

        <!-- Outside the three branches above, and that is the point: a panel's
             modals must exist while the list is empty, because the header's
             action button is exactly what the reader presses to fill it. Put
             them in the default slot and the "+" of a fresh installation sets a
             flag nothing is listening to. -->
        <slot name="overlay" />
    </section>
</template>
