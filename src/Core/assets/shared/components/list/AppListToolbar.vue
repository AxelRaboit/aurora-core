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
import AppPageHeading from "@/shared/components/display/AppPageHeading.vue";

const props = defineProps({
    /**
     * The screen's name. Given one, the toolbar opens with the page heading
     * (`AppPageHeading`) and the commands move up beside it; the search and
     * the filters then hold their own line below (visual redesign of the
     * suite, 10/10/2026). Without it, the toolbar draws as it always did.
     */
    title: { type: String, default: "" },
    /** The line under the name: the list's figures, or what it is for. */
    subtitle: { type: String, default: "" },
});

const slots = useSlots();
</script>

<template>
    <div v-if="props.title" class="flex flex-col gap-4">
        <AppPageHeading :title="props.title" :subtitle="props.subtitle">
            <template v-if="slots.actions" #actions>
                <slot name="actions" />
            </template>
        </AppPageHeading>
        <slot name="above" />
        <div v-if="slots.inline" class="flex flex-col gap-2 min-w-0 sm:flex-row sm:flex-wrap sm:items-center">
            <div class="flex-1 min-w-0 sm:min-w-72">
                <slot />
            </div>
            <slot name="inline" />
        </div>
        <slot v-else />
    </div>
    <div v-else class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
        <!-- **The filters go under the search on a phone.** Placed next
             to it, two twelve-rem selects took the three hundred and fifty
             pixels of the line and the search dropped to zero, its icon
             stuck behind them (contract list, 02/10/2026). -->
        <!-- **The search never shrinks under eighteen rem.** Four filters
             beside it took the line and left a field too narrow for its own
             placeholder (posts and GED lists, UI audit of 07/10/2026). The
             row wraps instead: the filters go to the next line, the search
             keeps the width it needs. -->
        <div v-if="slots.inline" class="flex flex-col gap-2 min-w-0 sm:flex-row sm:flex-wrap sm:items-center">
            <div class="flex-1 min-w-0 sm:min-w-72">
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
            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:self-start *:w-full sm:*:w-auto"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
