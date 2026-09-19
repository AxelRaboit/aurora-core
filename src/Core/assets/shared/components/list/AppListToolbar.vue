<script setup>
/**
 * 2-column responsive toolbar for admin list pages. Mobile = stacked,
 * desktop (sm+) = search left (1fr) + actions right (auto).
 *
 * Default slot = left content (typically AppSearchInput).
 * `actions` slot = right content (typically one or more AppButton).
 * `inline` slot = stays **beside** the search, mobile included.
 *
 * The `inline` slot exists for the controls that belong to the search rather
 * than beside it - a view toggle, a scope switch. Stacked under the field on a
 * phone they read as a second filter and eat a row; the primary action is the
 * one that earns its own row, and it keeps `actions`.
 *
 * The component is layout-only; consumers compose the search input and
 * action buttons themselves so it stays usable for any admin list page - it
 * decides only where things sit, which on a phone means: stacked, and each
 * action across the full line.
 */
import { useSlots } from "vue";

const slots = useSlots();
</script>

<template>
    <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
        <div v-if="slots.inline" class="flex items-center gap-2 min-w-0">
            <div class="flex-1 min-w-0">
                <slot />
            </div>
            <slot name="inline" />
        </div>
        <slot v-else />

        <!-- **Les actions dans leur propre boîte, pleine largeur sur
             téléphone.** Posées directement dans la grille, deux boutons
             devenaient deux cellules et cassaient la colonne de droite ; et
             chacun gardait sa largeur naturelle, donc un « Actions » de
             quatre-vingt-dix pixels collé à gauche d'un vide de deux cent
             cinquante. Ici ils s'empilent et prennent la ligne sous `sm`, et
             retrouvent leur taille dès qu'il y a la place - la même réponse
             que {@see AppModalFooter}, au même endroit du geste. -->
        <div
            v-if="slots.actions"
            class="flex flex-col gap-2 sm:flex-row sm:items-center *:w-full sm:*:w-auto"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
