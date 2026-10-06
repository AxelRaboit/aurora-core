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
        <!-- **The filters go under the search on a phone.** Placed next
             to it, two twelve-rem selects took the three hundred and fifty
             pixels of the line and the search dropped to zero, its icon
             stuck behind them (contract list, 02/10/2026). -->
        <div v-if="slots.inline" class="flex flex-col gap-2 min-w-0 sm:flex-row sm:items-center">
            <div class="flex-1 min-w-0">
                <slot />
            </div>
            <slot name="inline" />
        </div>
        <slot v-else />

        <!-- **The actions in their own box, full width on a phone.**
             Placed directly in the grid, two buttons became two cells and
             broke the right-hand column; and each kept its natural width,
             so a ninety-pixel "Actions" stuck to the left of a two hundred
             and fifty pixel gap. Here they stack and take the line below
             `sm`, and get their size back as soon as there is room - the
             same answer as {@see AppModalFooter}, at the same point of the
             gesture. -->
        <div
            v-if="slots.actions"
            class="flex flex-col gap-2 sm:flex-row sm:items-center *:w-full sm:*:w-auto"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
