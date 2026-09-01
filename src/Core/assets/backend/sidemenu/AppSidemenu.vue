<script setup>
import "./sidemenu.css";

defineOptions({ inheritAttrs: false });
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useLayoutMount } from "@/shared/composables/useLayoutMount.js";
import { useTheme } from "@/shared/composables/useTheme.js";
import { searchSections } from "@/shared/search/searchSectionRegistry.js";
import { useResizable } from "@/shared/composables/useResizable.js";
import { useBackendSearch } from "@core/backend/sidemenu/composables/useBackendSearch.js";
import { useSidemenuCollapse } from "@core/backend/sidemenu/composables/useSidemenuCollapse.js";
import { useSidemenuDescriptions } from "@core/backend/sidemenu/composables/useSidemenuDescriptions.js";
import { useSidemenuNav } from "@core/backend/sidemenu/composables/useSidemenuNav.js";
import { useSidemenuSectionTheme } from "@core/backend/sidemenu/composables/useSidemenuSectionTheme.js";
import { useSidemenuLiveColors } from "@core/backend/sidemenu/composables/useSidemenuLiveColors.js";
import AppLogo from "@/shared/components/display/AppLogo.vue";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppNavButton from "@/shared/components/nav/AppNavButton.vue";
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";
import AppNotificationsBell from "@core/backend/notifications/AppNotificationsBell.vue";
import AppSidemenuAccount from "./AppSidemenuAccount.vue";
import AppSidemenuNav from "./AppSidemenuNav.vue";
import { getModulePanel } from "@/shared/nav/modulePanelRegistry.js";
import {
    AlarmClock,
    CalendarDays,
    CheckSquare,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    FileText,
    Filter,
    FolderKanban,
    Globe,
    Image,
    Layers,
    Loader2,
    LogOut,
    Mail,
    Moon,
    PanelLeft,
    PanelLeftClose,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Sun,
    Tags as TagsIcon,
    User,
    X,
} from "lucide-vue-next";
import { statusBadge } from "@/shared/utils/format/statusStyles.js";
import { highlightMatch } from "@/shared/utils/format/highlightMatch.js";
import { modKeyLabel } from "@/shared/utils/platform.js";

const props = defineProps({
    navSections: { type: Array, default: () => [] },
    userName: { type: String, default: "" },
    userEmail: { type: String, default: "" },
    userPhotoUrl: { type: String, default: "" },
    activeRoute: { type: String, default: "" },
    logoutCsrf: { type: String, default: "" },
    frontPath: { type: String, default: "/" },
    hasEnabledFronts: { type: Boolean, default: true },
    profilePath: { type: String, default: "/backend/general/profile" },
    sidemenuPreferencesPath: { type: String, default: "/backend/general/profile/sidemenu" },
    sidemenuCollapsedPath: { type: String, default: "/backend/general/profile/sidemenu/collapsed" },
    sidemenuDescriptionsPath: { type: String, default: "/backend/general/profile/sidemenu/descriptions" },
    sidemenuShowDescriptions: { type: Boolean, default: true },
    logoutPath: { type: String, default: "/logout" },
    mailpitUrl: { type: String, default: "" },
    siteName: { type: String, default: "Aurora" },
    siteLogoUrl: { type: String, default: "" },
    appVersion: { type: String, default: "" },
    searchPath: { type: String, default: "/backend/general/search" },
    notificationsListPath: { type: String, default: "" },
    notificationsMarkReadPath: { type: String, default: "" },
    notificationsMarkAllReadPath: { type: String, default: "" },
    notificationsDeletePath: { type: String, default: "" },
    notificationsDeleteAllPath: { type: String, default: "" },
    navSectionAliases: { type: Object, default: () => ({}) },
    navItemAliases: { type: Object, default: () => ({}) },
    /** Per-section colour overrides - `{sectionId: colorName}`. */
    navSectionColors: { type: Object, default: () => ({}) },
    /**
     * The open module's own menu, resolved server-side by `ModuleNavResolver`,
     * or null when the column stays in its project view.
     *
     * Null on every page until a module implements
     * `ModuleNavViewProviderInterface` - which is what makes this addition
     * invisible on screen for now.
     */
    moduleNavView: { type: Object, default: null },
});

const { t, d } = useI18n();
const { theme, toggle: toggleTheme } = useTheme();
const { liveSectionColors } = useSidemenuLiveColors(props.navSectionColors);
const { mobileOpen, openMobile, closeMobile } = useSidemenuCollapse(props.sidemenuCollapsedPath);
const { showDescriptions, toggleDescriptions } = useSidemenuDescriptions(
    props.sidemenuDescriptionsPath,
    props.sidemenuShowDescriptions,
);

const { dragging: sidemenuDragging, startResize: startSidemenuResize, reset: resetSidemenuWidth } = useResizable({
    key: "aurora-sidemenu-width",
    // À garder égal au `--sidemenu-width` de sidemenu.css, cf. le commentaire
    // qui y est : une divergence se voit comme un saut au chargement.
    //
    // Le défaut est le maximum : le menu porte maintenant des arborescences et
    // des recherches, pas seulement des liens. Une largeur déjà choisie est
    // conservée - ceci ne déplace que ceux qui n'ont jamais tiré la poignée.
    defaultValue: 480,
    min: 200,
    max: 480,
    onChange: (px) => { document.documentElement.style.setProperty("--sidemenu-width", `${px}px`); },
});

watch(sidemenuDragging, (dragging) => {
    document.documentElement.classList.toggle("sidemenu-resizing", dragging);
});

useLayoutMount();

const nav = useSidemenuNav(props.navSections, props.activeRoute, props.navSectionAliases, props.navItemAliases, liveSectionColors, props.moduleNavView);

const {
    dashboardPath, activeSections, navItems, navFilter, displayedSections,
    inModuleView, hasModuleView, moduleLabel, moduleId, backToProject, enterModuleView,
    isAccountExpanded, toggleAccount,
    isActive, isActiveExact,
} = nav;

/**
 * The component a module named for its own panel, when it registered one.
 *
 * Resolved once: the payload belongs to the page, so it cannot change without a
 * navigation. Null for a links-only view, and also null for a name nothing
 * claimed - in which case the links still draw, because a missing panel must
 * not cost the reader the navigation that did resolve.
 */
const modulePanel = getModulePanel(nav.panelComponent);

const sectionTheme = useSidemenuSectionTheme(liveSectionColors);

/**
 * The two sections core owns, plus whatever modules registered.
 *
 * `recent` and `nav` are not module data - they are the palette's own memory and
 * the application's own pages - so they stay here. Everything else comes from the
 * registry, which is what stops a new section needing an edit in this file.
 */
const CORE_SECTIONS = {
    recent: { icon: Clock, labelKey: "backend.search.sections.recent" },
    nav: { icon: Layers, labelKey: "backend.search.sections.nav" },
};

const sectionConfig = computed(() => {
    const config = { ...CORE_SECTIONS };

    for (const section of searchSections()) {
        config[section.kind] = { icon: section.icon, labelKey: section.labelKey };
    }

    return config;
});

const {
    searchOpen, searchQuery, searchLoading,
    searchHighlightedIndex, searchInputRef,
    sections, flatResults, totalResults,
    openPalette, closePalette, activateResult, entryIndex,
} = useBackendSearch({ searchPath: props.searchPath, navItems, currentRoute: props.activeRoute });

function openSearchFromMobile() {
    closeMobile();
    openPalette();
}
</script>

<template>
    <!-- `keydown.esc` on the aside, not on `window`: leaving the module view is
         a gesture aimed at the column, and a global handler would fire it while
         someone was typing in the page. Keydown from a focused row bubbles here,
         which is exactly the scope wanted - "Escape, while I am in the menu". -->
    <aside
        id="sidemenu"
        class="hidden lg:flex flex-col fixed inset-y-0 left-0 bg-surface border-r border-line z-30 overflow-hidden"
        v-on:keydown.esc="backToProject"
    >
        <div class="sh-wrap flex items-center h-16 border-b border-line shrink-0 transition-all duration-200">
            <a :href="dashboardPath" class="flex items-center gap-2.5 min-w-0 flex-1">
                <img v-if="siteLogoUrl" :src="siteLogoUrl" alt="Logo" class="h-8 w-8 shrink-0 object-cover rounded-xl">
                <AppLogo v-else :size="32" class="shrink-0" />
                <div class="flex flex-col min-w-0">
                    <span class="text-primary font-bold text-lg tracking-tight truncate leading-tight">{{ siteName }}</span>
                    <span v-if="appVersion" class="text-xs text-muted/50 leading-none">{{ appVersion }}</span>
                </div>
            </a>
        </div>

        <!-- `h-10`, to the pixel, because this row and the breadcrumb band are
             meant to read as one line across the page: the menu's header and
             the page header's upper band are both `h-16`, so whatever comes next
             on each side has to match too. The link already measures 40px on its
             own - `.si` gives it 0.624rem above and below a 20px row - so the
             `py-2` this used to carry was the whole discrepancy. Pinned rather
             than left implicit so a change to `.si` cannot quietly break the
             alignment.

             The row keeps its height, so the *link* has to be shorter than it:
             at a full 40px its hover fill reaches both borders and the rounded
             corners clip against them. `.sh-view-site` trims it to 32px, which
             centres with 4px of clearance - see sidemenu.css. -->
        <div v-if="hasEnabledFronts" class="sh-view-site h-10 flex items-center px-3 border-b border-line shrink-0">
            <AppNavLink
                :href="frontPath"
                target="_blank"
                hover-color="emerald"
                :tooltip-title="t('backend.nav.view_site')"
            >
                <Globe class="w-5 h-5 shrink-0 text-muted group-hover:text-emerald-400 transition-colors" :stroke-width="2" />
                <span class="si-label truncate">{{ t("backend.nav.view_site") }}</span>
            </AppNavLink>
        </div>

        <!-- Shown only inside a module. Two rows, because they answer two
             different questions and merging them would make the module's name a
             button that leaves it: the first says where the column is, the
             second is the way out. The name borrows the module's section colour
             from the same registry the project view uses - the reader already
             reads lime as "GED", so it is reused rather than re-invented. -->
        <div v-if="inModuleView" class="px-3 py-2 border-b border-line shrink-0 flex flex-col gap-1">
            <div
                class="si-section-header flex items-center gap-2 text-xs font-semibold uppercase tracking-wider"
                :class="[sectionTheme.headerClasses(moduleId), sectionTheme.labelClasses(moduleId)]"
            >
                <span class="truncate">{{ moduleLabel }}</span>
            </div>
            <button
                type="button"
                class="w-full flex items-center gap-1.5 px-2 py-1 rounded-md text-xs text-muted hover:text-primary hover:bg-surface-2 transition-colors"
                v-on:click="backToProject"
            >
                <ChevronLeft class="w-3.5 h-3.5 shrink-0" :stroke-width="2.5" />
                <span class="truncate">{{ t("backend.nav.back_to_modules") }}</span>
            </button>
        </div>

        <!-- The door swings both ways.

             `backToProject` above had no counterpart: leaving the module view
             was one press of Escape, and nothing short of reloading the page
             brought it back. `enterModuleView` existed and was tested from the
             day the view shipped - it simply had no control wired to it, which
             is invisible until something the reader needs lives only in that
             view. A folder they cannot create is how it surfaced. -->
        <button
            v-if="hasModuleView && !inModuleView"
            type="button"
            class="mx-3 my-2 flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted transition-colors hover:bg-surface-2 hover:text-primary"
            v-on:click="enterModuleView"
        >
            <ChevronRight class="w-3.5 h-3.5 shrink-0" :stroke-width="2.5" />
            <span class="truncate">{{ t("backend.nav.back_to_module", { module: moduleLabel }) }}</span>
        </button>

        <div class="sh-search-section px-3 py-2 border-b border-line shrink-0 space-y-1.5">
            <div class="relative flex items-center">
                <Filter class="absolute left-2.5 w-3 h-3 text-muted pointer-events-none" :stroke-width="2" />
                <input
                    v-model="navFilter"
                    type="text"
                    :placeholder="t('backend.nav.filter_nav')"
                    class="w-full pl-7 pr-6 py-1.5 rounded-md text-xs bg-surface-2/60 border border-line/40 text-primary placeholder:text-muted focus:outline-none focus:border-line focus:bg-surface-2 transition-colors"
                >
                <button v-if="navFilter" type="button" class="absolute right-2 text-muted hover:text-primary transition-colors" v-on:click="navFilter = ''">
                    <X class="w-3 h-3" :stroke-width="2.5" />
                </button>
            </div>

            <!-- Beside the filter because both act on the menu itself rather
                 than leading anywhere. `AppToggle` carries its own label above
                 the switch, which is a form layout; here the row is tight, so
                 the label sits alongside instead. -->
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs text-muted truncate">{{ t("backend.nav.show_descriptions") }}</span>
                <AppToggle
                    :model-value="showDescriptions"
                    v-on:update:model-value="toggleDescriptions"
                />
            </div>
        </div>

        <!-- `py-1`, not `py-4`: 16px of padding left the first header and the
             last row of the last section standing 16px off their borders while
             every row between them sat 2px from its neighbour - the same odd
             gap as the section gutter, at the two ends of the list. 4px is the
             clearance a row's hover fill needs to keep off a border, the figure
             the "view site" row above already uses. -->
        <nav class="sidemenu-nav flex flex-col gap-0.5 flex-1 min-h-0 overflow-y-auto scrollbar-thin py-1">
            <p v-if="navFilter && !displayedSections.length" class="px-3 text-xs text-muted">
                {{ t("backend.nav.filter_nav_empty") }}
            </p>
            <AppSidemenuNav
                :sections="displayedSections"
                :nav="nav"
                :theme="sectionTheme"
                :nav-filter="navFilter"
                :show-descriptions="showDescriptions"
            />

            <!-- What a list of links cannot express: a folder tree, a note list.
                 Inside the same scroll area as the rows above so the column
                 scrolls as one thing, and hidden while filtering - the filter
                 searches rows, and leaving a tree open beside three results
                 would suggest it had been searched too. -->
            <component
                :is="modulePanel"
                v-if="modulePanel && inModuleView && !navFilter"
                class="mt-1"
            />
        </nav>

        <div class="sidemenu-bottom shrink-0 border-t border-line py-3">
            <AppSidemenuAccount
                :user-name="userName"
                :user-email="userEmail"
                :user-photo-url="userPhotoUrl"
                :mailpit-url="mailpitUrl"
                :profile-path="profilePath"
                :preferences-path="sidemenuPreferencesPath"
                :logout-path="logoutPath"
                :logout-csrf="logoutCsrf"
                :profile-active="isActiveExact('backend_general_profile')"
                :preferences-active="isActive('backend_general_profile_sidemenu')"
                :theme="theme"
                :expanded="isAccountExpanded()"
                v-on:toggle="toggleAccount"
                v-on:toggle-theme="toggleTheme"
            />
        </div>

        <!-- The application's own copyright, from the name set in the
             settings. It used to be a link to the author's GitHub, which read
             as the product's credit line on somebody else's deployment: what a
             reader wants there is whose application this is. -->
        <div class="flex justify-center py-2 border-t border-line/30">
            <span class="text-xs text-muted/40 tracking-wide select-none">
                {{ t('shared.common.built_with', { year: new Date().getFullYear(), siteName }) }}
            </span>
        </div>

        <div
            class="sidemenu-resize-handle"
            :class="{ 'is-dragging': sidemenuDragging }"
            :title="t('backend.nav.resize_hint')"
            v-on:pointerdown="startSidemenuResize"
            v-on:dblclick="resetSidemenuWidth"
        />
    </aside>

    <div class="lg:hidden fixed top-0 inset-x-0 h-14 bg-surface border-b border-line z-30 flex items-center justify-between px-4">
        <a :href="dashboardPath" class="flex items-center gap-2">
            <AppLogo :size="28" />
            <span class="text-primary font-bold text-base tracking-tight">{{ siteName }}</span>
        </a>
        <div class="flex items-center gap-1">
            <AppButton variant="ghost" size="none" class="p-2" v-on:click="openPalette">
                <Search class="w-5 h-5" :stroke-width="2" />
            </AppButton>
            <!-- The bell belongs here too. On desktop it moved to the page
                 header, which is hidden below the large breakpoint - so without
                 this, a phone had no way to reach notifications at all. -->
            <AppNotificationsBell
                v-if="notificationsListPath"
                :list-path="notificationsListPath"
                :mark-read-path="notificationsMarkReadPath"
                :mark-all-read-path="notificationsMarkAllReadPath"
                :delete-path="notificationsDeletePath"
                :delete-all-path="notificationsDeleteAllPath"
            />
            <!-- The desktop toggle's icons, and its alternation: an open
                 panel when the drawer is open, a closed one when it is shut.
                 Both controls open the same menu, so showing two different
                 glyphs for it would be two names for one thing.

                 The icon shows the state, not the action - same reason as on
                 desktop: a control that announced what it would do flips under
                 the finger at the moment of tapping. -->
            <AppButton
                variant="ghost"
                size="none"
                class="p-2"
                :title="mobileOpen ? t('backend.nav.collapse_menu') : t('backend.nav.expand_menu')"
                v-on:click="mobileOpen ? closeMobile() : openMobile()"
            >
                <PanelLeftClose v-if="mobileOpen" class="w-5 h-5" :stroke-width="2" />
                <PanelLeft v-else class="w-5 h-5" :stroke-width="2" />
            </AppButton>
        </div>
    </div>

    <div
        class="lg:hidden fixed inset-0 z-50 transition-opacity duration-200"
        :class="mobileOpen ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'"
    >
        <div class="absolute inset-0 bg-black/60" v-on:click="closeMobile" />
        <div
            class="relative w-[480px] max-w-[85vw] bg-surface h-full flex flex-col shadow-2xl transition-transform duration-200"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex items-center justify-between px-4 h-16 border-b border-line shrink-0">
                <div class="flex items-center gap-2.5">
                    <AppLogo :size="32" />
                    <div class="flex flex-col">
                        <span class="text-primary font-bold text-lg tracking-tight">{{ siteName }}</span>
                        <span v-if="appVersion" class="text-xs text-muted/50 leading-none">{{ appVersion }}</span>
                    </div>
                </div>
                <AppButton variant="ghost" size="none" class="p-1.5" v-on:click="closeMobile">
                    <X class="w-5 h-5" :stroke-width="2" />
                </AppButton>
            </div>

            <div class="shrink-0 px-3 pt-3 pb-1 space-y-1">
                <button
                    type="button"
                    class="w-full flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm text-muted border border-line/60 hover:border-line hover:text-primary hover:bg-surface-2 transition-colors"
                    v-on:click="openSearchFromMobile"
                >
                    <Search class="w-4 h-4 shrink-0" :stroke-width="2" />
                    <span class="flex-1 text-left">{{ t("backend.search.button") }}</span>
                </button>
                <a
                    v-if="hasEnabledFronts"
                    :href="frontPath"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-secondary hover:text-emerald-400 hover:bg-emerald-500/10 transition-colors"
                >
                    <Globe class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    {{ t("backend.nav.view_site") }}
                </a>
                <hr class="border-line mt-1">
            </div>

            <nav class="flex flex-col gap-0.5 flex-1 overflow-y-auto scrollbar-thin px-3 py-2">
                <!-- The same component the aside uses. Its own copy was a
                     degraded one: no item descriptions in the tooltips, no
                     `data-sidemenu-active`, and two dead `#tooltip` slots
                     `AppNavLink` never declared - so those child links had no
                     tooltip at all. No filter here: the drawer has none. -->
                <!-- `activeSections`, not `groupedSections`: the drawer shows
                     whichever view the column is on, so a phone is not sent back
                     to the project menu on a page the desktop shows a module menu
                     for. Not `displayedSections` - that one is filtered, and the
                     drawer has no filter to explain the missing rows. -->
                <AppSidemenuNav
                    :sections="activeSections"
                    :nav="nav"
                    :theme="sectionTheme"
                    :show-descriptions="showDescriptions"
                />
            </nav>

            <!-- The same component the aside uses. It carried its own copy
                 in plain markup until the collapsed rules were scoped to
                 `#sidemenu`; before that, hiding the menu on a desktop session
                 would have hidden this drawer on a phone. -->
            <div class="shrink-0 border-t border-line px-3 py-3">
                <AppSidemenuAccount
                    :user-name="userName"
                    :user-email="userEmail"
                    :user-photo-url="userPhotoUrl"
                    :mailpit-url="mailpitUrl"
                    :profile-path="profilePath"
                    :preferences-path="sidemenuPreferencesPath"
                    :logout-path="logoutPath"
                    :logout-csrf="logoutCsrf"
                    :profile-active="isActiveExact('backend_general_profile')"
                    :preferences-active="isActive('backend_general_profile_sidemenu')"
                    :theme="theme"
                    :expanded="isAccountExpanded()"
                    v-on:toggle="toggleAccount"
                    v-on:toggle-theme="toggleTheme"
                />
            </div>
        </div>
    </div>

    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="searchOpen" class="fixed inset-0 z-50 flex items-start justify-center pt-20 px-4" v-on:click.self="closePalette">
                <div class="fixed inset-0 bg-black/60" v-on:click="closePalette" />

                <div class="relative w-full max-w-2xl bg-surface border border-line rounded-xl shadow-2xl overflow-hidden flex flex-col max-h-[70vh]">
                    <div class="flex items-center gap-3 px-4 py-3 border-b border-line">
                        <Search class="w-4 h-4 text-muted shrink-0" :stroke-width="2" />
                        <input
                            ref="searchInputRef"
                            v-model="searchQuery"
                            type="text"
                            :placeholder="t('backend.search.placeholder')"
                            class="flex-1 bg-transparent border-0 outline-none text-primary placeholder-muted text-sm"
                        >
                        <Loader2 v-if="searchLoading" class="w-4 h-4 text-muted animate-spin" :stroke-width="2" />
                        <AppButton variant="ghost" size="none" class="p-1" v-on:click="closePalette">
                            <X class="w-4 h-4" :stroke-width="2" />
                        </AppButton>
                    </div>

                    <div class="flex-1 overflow-y-auto">
                        <!-- Empty state -->
                        <div v-if="!searchQuery.trim() && !sections.length" class="px-4 py-8 text-sm text-muted text-center">
                            {{ t("backend.search.hint") }}
                        </div>
                        <div v-else-if="searchQuery.trim() && !searchLoading && !totalResults" class="px-4 py-8 text-sm text-muted text-center">
                            {{ t("backend.search.empty") }}
                        </div>

                        <!-- Result sections -->
                        <div
                            v-for="(section, idx) in sections"
                            :key="section.kind"
                            class="px-2 py-2 space-y-1"
                            :class="{ 'border-t border-line': idx > 0 }"
                        >
                            <p class="px-2 py-1 text-xs uppercase tracking-wide text-muted font-semibold flex items-center gap-1.5">
                                <component
                                    :is="sectionConfig[section.kind]?.icon ?? Layers"
                                    class="w-3 h-3"
                                    :stroke-width="2"
                                />
                                {{ t(sectionConfig[section.kind]?.labelKey ?? "backend.search.sections.nav") }}
                            </p>

                            <button
                                v-for="item in section.items"
                                :key="`${section.kind}-${section.kind === 'nav' || section.kind === 'recent' ? item.route : item.id}`"
                                type="button"
                                class="w-full text-left px-2 py-2 rounded-md transition-colors flex items-center gap-3"
                                :class="entryIndex(section.kind, item) === searchHighlightedIndex ? 'bg-accent-600/15 text-accent-400' : 'hover:bg-surface-2'"
                                v-on:mouseenter="searchHighlightedIndex = entryIndex(section.kind, item)"
                                v-on:click="activateResult({ kind: section.kind, item })"
                            >
                                <!-- nav / recent -->
                                <template v-if="section.kind === 'nav' || section.kind === 'recent'">
                                    <component :is="item.icon" class="w-4 h-4 shrink-0 text-muted" :stroke-width="2" />
                                    <span class="text-sm font-medium text-primary truncate">{{ item.label }}</span>
                                </template>

                                <!-- post -->
                                <template v-else-if="section.kind === 'post'">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium shrink-0" :class="statusBadge(item.status)">
                                        {{ item.statusLabel }}
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.title ?? '-', searchQuery)" />
                                        <div v-if="item.snippet" class="text-xs text-muted line-clamp-2" v-html="highlightMatch(item.snippet, searchQuery)" />
                                        <div class="text-xs text-muted mt-0.5">{{ item.postType }}</div>
                                    </div>
                                </template>

                                <!-- project -->
                                <!-- term -->
                                <template v-else-if="section.kind === 'term'">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.name ?? '-', searchQuery)" />
                                        <div class="text-xs text-muted">{{ item.taxonomy }}</div>
                                    </div>
                                </template>

                                <!-- media -->
                                <template v-else-if="section.kind === 'media'">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.name ?? '-', searchQuery)" />
                                        <div class="text-xs text-muted">{{ item.mimeType }}</div>
                                    </div>
                                </template>

                                <!-- calendar: an event or a reminder. The dot
                                     carries the calendar's colour, which is the
                                     fastest way to tell whose it is. -->
                                <template v-else-if="section.kind === 'event' || section.kind === 'reminder'">
                                    <span
                                        class="w-1.5 h-1.5 rounded-full shrink-0"
                                        :style="{ backgroundColor: `var(--chart-cat-${item.colourSlot})` }"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <div
                                            class="text-sm font-medium truncate"
                                            :class="item.completed ? 'line-through text-muted' : 'text-primary'"
                                            v-html="highlightMatch(item.title ?? '-', searchQuery)"
                                        />
                                        <div class="text-xs text-muted">
                                            {{ item.calendar }} · {{ d(new Date(item.at), item.allDay ? "long" : "short") }}
                                        </div>
                                    </div>
                                </template>

                                <!-- Anything a module registered without a row of
                                     its own. `title` or `name`, and a subtitle if
                                     it sent one: enough for a result to be
                                     readable and clickable without core knowing
                                     what it is. Before this, a new section drew an
                                     empty row. -->
                                <template v-else>
                                    <div class="flex-1 min-w-0">
                                        <div
                                            class="text-sm font-medium text-primary truncate"
                                            v-html="highlightMatch(item.title ?? item.name ?? '-', searchQuery)"
                                        />
                                        <div v-if="item.subtitle" class="text-xs text-muted">{{ item.subtitle }}</div>
                                    </div>
                                </template>
                            </button>
                        </div>
                    </div>

                    <div class="px-4 py-2 border-t border-line bg-surface-2/50 text-xs text-muted flex items-center gap-4">
                        <span><kbd class="px-1 py-0.5 rounded bg-surface border border-line font-mono text-xs">↑↓</kbd> {{ t("backend.search.keys.navigate") }}</span>
                        <span><kbd class="px-1 py-0.5 rounded bg-surface border border-line font-mono text-xs">Enter</kbd> {{ t("backend.search.keys.select") }}</span>
                        <span class="ml-auto"><kbd class="px-1 py-0.5 rounded bg-surface border border-line font-mono text-xs">Esc</kbd> {{ t("backend.search.keys.close") }}</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
