<script setup>
/**
 * `gap`, never `space-y`: the rows drawn by `AppNavLink` are wrapped in
 * `AppTooltip`, whose root is `display: contents`, and margins on those are
 * ignored. The wrapper is still there even though this menu passes it nothing -
 * `AppNavLink` renders it for every caller - so the constraint stands. The
 * section gap matches the row gap so the last row of a section is spaced like
 * every other: the section header is what separates sections, it does not
 * need a gutter as well.
 *
 * The note is here rather than above the root so it is not rendered into the page
 * as a comment node. It does not change attribute fallthrough: the root is a
 * `v-for`, so this component is multi-root regardless and cannot take a class
 * from its parent.
 */
/**
 * The menu's sections and their items - one component for both menus.
 *
 * This loop was written twice: once in the desktop `<aside>` and once in the
 * mobile drawer. Structurally the same, but the drawer's copy was a degraded one
 * - no item descriptions, no `data-sidemenu-active`, and two `<template
 * #tooltip>` blocks handed to `AppNavLink`, which declares no such slot. That is
 * what a second copy costs.
 *
 * They could not be merged before `.sidemenu-collapsed` was scoped to
 * `#sidemenu`: while its rules reached the whole document, hiding the desktop
 * menu hid the drawer with it.
 *
 * **No hover tooltip anywhere in the column.** It repeated the label the row
 * already shows, and its only other job - carrying the description - is done by the row itself, which always shows
 * the text under the label where it can be read without hunting for it (the
 * "show descriptions" switch that once made it optional went with the visual
 * redesign of the suite, 10/10/2026). Two ways to see the same thing
 * meant the tooltip had to be silenced whenever the switch was on, which is the
 * shape of a feature that has been replaced. The rows lost theirs first; the
 * account block and the "view site" link kept theirs a while longer, repeating
 * their own label to nobody's benefit.
 *
 * The helpers arrive as two bags rather than ten function props - `nav` from
 * `useSidemenuNav`, `theme` from `useSidemenuSectionTheme`. Ten props would
 * have to be edited in three files every time one is added.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";

/**
 * Which palette a section borrows.
 *
 * A project section is its own colour, so the two are the same string. A module
 * view's groups all borrow the module's, so they carry a `themeId` that differs
 * from the `id` their fold state is keyed on - four groups inside the GED are
 * four fold states and one lime.
 */
function themeId(section) {
    return section.themeId ?? section.id;
}

/**
 * Whether this section can be folded away.
 *
 * False for a module group with no header: the header *is* the control, so a
 * headerless group that started folded could never be opened again.
 */
function isFoldable(section) {
    return false !== section.foldable;
}

/**
 * A section holding a single plain entry: Calendrier > Calendrier, Notes >
 * Notes Markdown. Its header said the same thing one line above the entry,
 * so the entry stands alone with the section's dot before its name, where
 * the header's dot sat (visual redesign of the suite, 10/10/2026).
 *
 * Folded, it folds like any other: the header comes back in place of the
 * entry, so a folded menu is a plain list of sections, and a click on it
 * opens the entry again. Shown unfolded with no header, it could not be
 * folded on its own, and "fold all" left those entries standing (Axel,
 * 10/10/2026).
 */
function isSingle(section) {
    return isFoldable(section) && 1 === section.items.length && !section.items[0].children?.length;
}

const { locale } = useI18n();

/**
 * An entry's figure, in the reader's language: « 1 204 » in French, « 1,204 »
 * in English. The locale is vue-i18n's, never the browser's - a suite in
 * French reads French numbers whatever the browser is set to.
 */
const countFormat = computed(() => new Intl.NumberFormat(locale.value));

defineProps({
    /** The sections to draw, already filtered by the caller. */
    sections: { type: Array, required: true },
    /** Everything from `useSidemenuNav` that this loop reads. */
    nav: { type: Object, required: true },
    /** `headerClasses` / `labelClasses` / `dotClasses` from `useSidemenuSectionTheme`. */
    theme: { type: Object, required: true },
    /**
     * The nav filter's current text. While it is set, section headers are
     * hidden and every matching item shows regardless of whether its section
     * is folded - a search that obeyed the folds would hide its own results.
     */
    navFilter: { type: String, default: "" },
});

/*
 * **No icon on a row** (visual redesign of the suite, 10/10/2026). The menu
 * folds away entirely rather than to a rail, so an icon never stood in for a
 * hidden label; beside a name and its description it was a third thing to read
 * on every line, twenty times down the column. The section's dot and the name
 * carry the row. Icons stay where they still do work: the search palette,
 * where results of every kind are mixed, and the folder trees of a module view.
 */
</script>

<template>
    <div v-for="section in sections" :key="section.id" class="flex flex-col gap-0.5">
        <button
            v-if="!navFilter && isFoldable(section) && (!isSingle(section) || !nav.isSectionExpanded(section))"
            type="button"
            class="si-section-header w-full flex items-center justify-between text-xs font-semibold uppercase tracking-wider transition-colors"
            :class="[theme.headerClasses(themeId(section)), theme.labelClasses(themeId(section))]"
            :aria-expanded="nav.isSectionExpanded(section) ? 'true' : 'false'"
            v-on:click="nav.toggleSection(section)"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span class="size-2 shrink-0 rounded-full" :class="theme.dotClasses(themeId(section))" aria-hidden="true" />
                <span class="truncate">{{ section.label }}</span>
            </span>
            <ChevronDown
                class="w-3.5 h-3.5 shrink-0 transition-transform"
                :class="{ '-rotate-90': !nav.isSectionExpanded(section) }"
                :stroke-width="2.5"
            />
        </button>

        <template v-for="item in section.items" :key="item.route">
            <template v-if="navFilter || !isFoldable(section) || nav.isSectionExpanded(section)">
                <!-- A group parent: the label navigates, the chevron unfolds.
                     Two targets in one row, because the parent is itself a
                     page - collapsing them into one would cost the page. -->
                <template v-if="!navFilter && item.children?.length">
                    <div
                        class="flex items-center rounded-lg text-sm font-medium transition-colors group relative"
                        :class="nav.itemClasses(item, themeId(section))"
                    >
                        <a
                            :href="item.path"
                            :data-sidemenu-active="nav.itemIsActive(item) ? 'true' : null"
                            :aria-current="nav.itemIsCurrent(item) ? 'page' : undefined"
                            class="flex items-center flex-1 min-w-0 gap-3 py-[0.4375rem] pl-3"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline gap-2">
                                    <span class="min-w-0 flex-1 truncate" :class="item.description ? 'font-semibold' : ''">{{ item.label }}</span>
                                    <span v-if="null !== (item.count ?? null)" data-nav-count class="shrink-0 text-xs font-medium tabular-nums text-secondary">{{ countFormat.format(item.count) }}</span>
                                </span>
                                <span v-if="item.description" class="sidemenu-description mt-0.5 block text-xs font-normal text-secondary" :title="item.description">{{ item.description }}</span>
                            </span>
                        </a>
                        <!-- `title` stays: it is the accessible name of a button
                             without text, not a tooltip. Without it, a screen
                             reader announces "button" and nothing else. -->
                        <AppIconButton
                            :title="item.label"
                            :aria-expanded="nav.isGroupExpanded(item.route) ? 'true' : 'false'"
                            class="mr-1 opacity-50 hover:opacity-100 hover:!bg-transparent"
                            v-on:click.stop="nav.toggleGroup(item.route)"
                        >
                            <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ '-rotate-90': !nav.isGroupExpanded(item.route) }" :stroke-width="2.5" />
                        </AppIconButton>
                    </div>

                    <div v-show="nav.isGroupExpanded(item.route)" class="flex flex-col gap-0.5">
                        <AppNavLink
                            v-for="child in item.children"
                            :key="child.route"
                            :href="child.path"
                            :active="nav.itemIsCurrent(child)"
                            :sidemenu-active="nav.itemIsCurrent(child)"
                            :link-classes-override="nav.itemClasses(child, themeId(section))"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline gap-2">
                                    <span class="min-w-0 flex-1 truncate" :class="child.description ? 'font-semibold' : ''">{{ child.label }}</span>
                                    <span v-if="null !== (child.count ?? null)" data-nav-count class="shrink-0 text-xs font-medium tabular-nums text-secondary">{{ countFormat.format(child.count) }}</span>
                                </span>
                                <span v-if="child.description" class="sidemenu-description mt-0.5 block text-xs font-normal text-secondary" :title="child.description">{{ child.description }}</span>
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
                    :link-classes-override="nav.itemClasses(item, themeId(section))"
                >
                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline gap-2">
                            <span
                                v-if="isSingle(section) && !navFilter"
                                class="size-2 shrink-0 self-center rounded-full"
                                :class="theme.dotClasses(themeId(section))"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate" :class="item.description ? 'font-semibold' : ''">{{ item.label }}</span>
                            <span v-if="null !== (item.count ?? null)" data-nav-count class="shrink-0 text-xs font-medium tabular-nums text-secondary">{{ countFormat.format(item.count) }}</span>
                        </span>
                        <!-- Two lines at most, the whole sentence on hover:
                             one line cut a description in the middle, and an
                             unbounded one let a long sentence stretch a row
                             now that every row carries one. -->
                        <span v-if="item.description" class="sidemenu-description mt-0.5 block text-xs font-normal text-secondary" :title="item.description">{{ item.description }}</span>
                    </span>
                </AppNavLink>
            </template>
        </template>
    </div>
</template>
