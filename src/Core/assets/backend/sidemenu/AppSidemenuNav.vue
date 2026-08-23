<script setup>
/**
 * The menu's sections and their items - one component for both menus.
 *
 * This loop was written twice: once in the desktop `<aside>` and once in the
 * mobile drawer. Structurally the same, but the drawer's copy was a degraded
 * one - no item descriptions in its tooltips, no `data-sidemenu-active`, and
 * two `<template #tooltip>` blocks handed to `AppNavLink`, which declares no
 * such slot. Those two child links have had no tooltip at all, silently, for as
 * long as the copy has existed. That is what a second copy costs.
 *
 * They could not be merged before `.sidemenu-collapsed` was scoped to
 * `#sidemenu`: while its rules reached the whole document, hiding the desktop
 * menu hid the drawer with it.
 *
 * The helpers arrive as two bags rather than ten function props - `nav` from
 * `useSidemenuNav`, `theme` from `useSidemenuSectionTheme`. Ten props would
 * have to be edited in three files every time one is added.
 */
import { ChevronDown } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";

defineProps({
    /** The sections to draw, already filtered by the caller. */
    sections: { type: Array, required: true },
    /** Everything from `useSidemenuNav` that this loop reads. */
    nav: { type: Object, required: true },
    /** `headerClasses` / `labelClasses` from `useSidemenuSectionTheme`. */
    theme: { type: Object, required: true },
    /**
     * The nav filter's current text. While it is set, section headers are
     * hidden and every matching item shows regardless of whether its section
     * is folded - a search that obeyed the folds would hide its own results.
     */
    navFilter: { type: String, default: "" },
    /**
     * Show each item's description under its label instead of only on hover.
     *
     * Same rule as `AppActionButton`: the label goes bold only when a
     * description sits under it, because bold is what separates the two. An
     * item with no description keeps a normal label - bolding it would promise
     * a second line that never comes.
     */
    showDescriptions: { type: Boolean, default: false },
});
</script>

<template>
    <!-- `gap`, never `space-y`: every row here is wrapped in AppTooltip, whose
         root is `display: contents`, and margins on those are ignored. The
         section gap matches the row gap so the last row of a section is spaced
         like every other - the coloured header band is what separates sections,
         it does not need a gutter as well. -->
    <div v-for="section in sections" :key="section.id" class="flex flex-col gap-0.5">
        <button
            v-if="!navFilter"
            type="button"
            class="si-section-header w-full flex items-center justify-between text-xs font-semibold uppercase tracking-wider transition-colors"
            :class="[theme.headerClasses(section.id), theme.labelClasses(section.id)]"
            v-on:click="nav.toggleSection(section)"
        >
            <span class="truncate">{{ section.label }}</span>
            <ChevronDown
                class="w-3.5 h-3.5 shrink-0 transition-transform"
                :class="{ '-rotate-90': !nav.isSectionExpanded(section) }"
                :stroke-width="2.5"
            />
        </button>

        <template v-for="item in section.items" :key="item.route">
            <template v-if="navFilter || nav.isSectionExpanded(section)">
                <!-- A group parent: the label navigates, the chevron unfolds.
                     Two targets in one row, because the parent is itself a
                     page - collapsing them into one would cost the page. -->
                <template v-if="!navFilter && item.children?.length">
                    <AppTooltip :title="item.label" :description="showDescriptions ? '' : item.description" placement="right">
                        <div
                            class="flex items-center rounded-lg text-sm font-medium transition-colors group relative"
                            :class="nav.itemClasses(item, section.id)"
                        >
                            <a
                                :href="item.path"
                                :data-sidemenu-active="nav.itemIsActive(item) ? 'true' : null"
                                class="flex items-center flex-1 min-w-0 gap-3 py-[0.625rem] pl-3"
                            >
                                <component :is="item.icon" class="w-5 h-5 shrink-0" :class="nav.iconClasses(item, section.id)" :stroke-width="2" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate" :class="showDescriptions && item.description ? 'font-semibold' : ''">{{ item.label }}</span>
                                    <span v-if="showDescriptions && item.description" class="mt-0.5 block text-xs text-muted whitespace-normal">{{ item.description }}</span>
                                </span>
                            </a>
                            <AppIconButton
                                :title="item.label"
                                class="mr-1 opacity-50 hover:opacity-100 hover:!bg-transparent"
                                v-on:click.stop="nav.toggleGroup(item.route)"
                            >
                                <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ '-rotate-90': !nav.isGroupExpanded(item.route) }" :stroke-width="2.5" />
                            </AppIconButton>
                        </div>
                    </AppTooltip>

                    <div v-show="nav.isGroupExpanded(item.route)" class="flex flex-col gap-0.5">
                        <AppNavLink
                            v-for="child in item.children"
                            :key="child.route"
                            :href="child.path"
                            :active="nav.isActive(child.route)"
                            :sidemenu-active="nav.isActive(child.route)"
                            :link-classes-override="nav.itemClasses(child, section.id)"
                            :tooltip-title="child.label"
                            :tooltip-description="showDescriptions ? '' : child.description"
                        >
                            <component :is="child.icon" class="w-4 h-4 shrink-0" :class="nav.iconClasses(child, section.id)" :stroke-width="2" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate" :class="showDescriptions && child.description ? 'font-semibold' : ''">{{ child.label }}</span>
                                <span v-if="showDescriptions && child.description" class="mt-0.5 block text-xs text-muted whitespace-normal">{{ child.description }}</span>
                            </span>
                        </AppNavLink>
                    </div>
                </template>

                <!-- A plain item, or a group parent while filtering: a search
                     result is a destination, not a branch to open. -->
                <AppNavLink
                    v-else
                    :href="item.path"
                    :active="nav.itemIsActive(item)"
                    :sidemenu-active="nav.itemIsActive(item)"
                    :link-classes-override="nav.itemClasses(item, section.id)"
                    :tooltip-title="item.label"
                    :tooltip-description="showDescriptions ? '' : item.description"
                >
                    <component :is="item.icon" class="w-5 h-5 shrink-0" :class="nav.iconClasses(item, section.id)" :stroke-width="2" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate" :class="showDescriptions && item.description ? 'font-semibold' : ''">{{ item.label }}</span>
                        <!-- Not truncated: a description cut at one line is
                             worse than no description, and the row is allowed
                             to grow when the reader asked for the text. -->
                        <span v-if="showDescriptions && item.description" class="mt-0.5 block text-xs text-muted whitespace-normal">{{ item.description }}</span>
                    </span>
                </AppNavLink>
            </template>
        </template>
    </div>
</template>
