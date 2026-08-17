<script setup>
import "./sidemenu.css";

defineOptions({ inheritAttrs: false });
import { watch } from "vue";
import { useI18n } from "vue-i18n";
import { useLayoutMount } from "@/shared/composables/useLayoutMount.js";
import { useTheme } from "@/shared/composables/useTheme.js";
import { useResizable } from "@/shared/composables/useResizable.js";
import { useBackendSearch } from "@core/backend/sidemenu/composables/useBackendSearch.js";
import { useSidemenuCollapse } from "@core/backend/sidemenu/composables/useSidemenuCollapse.js";
import { useSidemenuNav } from "@core/backend/sidemenu/composables/useSidemenuNav.js";
import { useSidemenuSectionTheme } from "@core/backend/sidemenu/composables/useSidemenuSectionTheme.js";
import { useSidemenuLiveColors } from "@core/backend/sidemenu/composables/useSidemenuLiveColors.js";
import AppLogo from "@/shared/components/display/AppLogo.vue";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import AppNavButton from "@/shared/components/nav/AppNavButton.vue";
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";
import AppNotificationsBell from "@core/backend/notifications/AppNotificationsBell.vue";
import {
    Globe, ShieldCheck, LogOut, Mail, Moon, Sun, User, SlidersHorizontal,
    Menu as MenuIcon, X,
    Search, Loader2, ChevronDown, Filter,
    Clock, Layers, FileText, Tags as TagsIcon, Image, FolderKanban, CheckSquare,
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
    /** Per-section colour overrides — `{sectionId: colorName}`. */
    navSectionColors: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { theme, toggle: toggleTheme } = useTheme();
const { liveSectionColors } = useSidemenuLiveColors(props.navSectionColors);
const { collapsed, mobileOpen, openMobile, closeMobile } = useSidemenuCollapse(props.sidemenuCollapsedPath);

const { dragging: sidemenuDragging, startResize: startSidemenuResize, reset: resetSidemenuWidth } = useResizable({
    key: "aurora-sidemenu-width",
    defaultValue: 240,
    min: 200,
    max: 480,
    onChange: (px) => { document.documentElement.style.setProperty("--sidemenu-width", `${px}px`); },
});

watch(sidemenuDragging, (dragging) => {
    document.documentElement.classList.toggle("sidemenu-resizing", dragging);
});

useLayoutMount();

const {
    dashboardPath, groupedSections, navItems, navFilter, displayedSections,
    isGroupExpanded, toggleGroup, isSectionExpanded, toggleSection,
    isAccountExpanded, toggleAccount,
    isActive, isActiveExact, itemIsActive, itemClasses, iconClasses,
} = useSidemenuNav(props.navSections, props.activeRoute, props.navSectionAliases, props.navItemAliases, liveSectionColors);

const { headerClasses: sectionHeaderClasses, labelClasses: sectionLabelClasses } = useSidemenuSectionTheme(liveSectionColors);

const SECTION_CONFIG = {
    recent:  { icon: Clock,         labelKey: "backend.search.sections.recent"   },
    nav:     { icon: Layers,        labelKey: "backend.search.sections.nav"      },
    project: { icon: FolderKanban,  labelKey: "backend.search.sections.projects" },
    task:    { icon: CheckSquare,   labelKey: "backend.search.sections.tasks"    },
    post:    { icon: FileText,      labelKey: "backend.search.sections.posts"    },
    term:    { icon: TagsIcon,      labelKey: "backend.search.sections.terms"    },
    media:   { icon: Image,         labelKey: "backend.search.sections.media"    },
};

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
    <aside id="sidemenu" class="hidden lg:flex flex-col fixed inset-y-0 left-0 bg-surface border-r border-line z-30 overflow-hidden">
        <div class="sh-wrap flex items-center h-16 border-b border-line shrink-0 transition-all duration-200">
            <a :href="dashboardPath" class="sh-logo-expanded flex items-center gap-2.5 min-w-0 flex-1">
                <img v-if="siteLogoUrl" :src="siteLogoUrl" alt="Logo" class="h-8 w-8 shrink-0 object-cover rounded-xl">
                <AppLogo v-else :size="32" class="shrink-0" />
                <div class="flex flex-col min-w-0">
                    <span class="text-primary font-bold text-lg tracking-tight truncate leading-tight">{{ siteName }}</span>
                    <span v-if="appVersion" class="text-xs text-muted/50 leading-none">{{ appVersion }}</span>
                </div>
            </a>
            <a :href="dashboardPath" class="sh-logo-collapsed">
                <img v-if="siteLogoUrl" :src="siteLogoUrl" alt="Logo" class="h-8 w-8 object-cover rounded-xl">
                <AppLogo v-else :size="32" />
            </a>
        </div>

        <div class="sh-logo-expanded items-center gap-3 border-b border-line px-4 py-3 shrink-0">
            <AppAvatar variant="solid" :name="userName" :photo-url="userPhotoUrl" size="md" />
            <div class="flex flex-col min-w-0">
                <p class="text-sm font-medium text-primary truncate">{{ userName }}</p>
                <p class="text-xs text-muted truncate">{{ userEmail }}</p>
            </div>
        </div>

        <div v-if="hasEnabledFronts" class="px-3 py-2 border-b border-line shrink-0">
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

        <div class="sh-search-section px-3 py-2 border-b border-line shrink-0 space-y-1.5">
            <!-- Icons row — expanded -->
            <div class="sh-logo-expanded items-center gap-1 w-full">
                <button
                    type="button"
                    class="p-1.5 rounded-lg text-muted hover:text-primary hover:bg-surface-2 transition-colors"
                    :title="t('backend.search.button')"
                    v-on:click="openPalette"
                >
                    <Search class="w-5 h-5" :stroke-width="2" />
                </button>
                <AppNotificationsBell
                    v-if="notificationsListPath"
                    :list-path="notificationsListPath"
                    :mark-read-path="notificationsMarkReadPath"
                    :mark-all-read-path="notificationsMarkAllReadPath"
                    :delete-path="notificationsDeletePath"
                    :delete-all-path="notificationsDeleteAllPath"
                />
            </div>
            <!-- Palette trigger — collapsed -->
            <AppNavButton
                class="sh-logo-collapsed"
                :tooltip-title="t('backend.search.button')"
                v-on:click="openPalette"
            >
                <Search class="w-5 h-5 shrink-0" :stroke-width="2" />
            </AppNavButton>
            <!-- Notifications bell — collapsed -->
            <div v-if="notificationsListPath" class="sh-logo-collapsed justify-center">
                <AppNotificationsBell
                    :list-path="notificationsListPath"
                    :mark-read-path="notificationsMarkReadPath"
                    :mark-all-read-path="notificationsMarkAllReadPath"
                    :delete-path="notificationsDeletePath"
                    :delete-all-path="notificationsDeleteAllPath"
                />
            </div>
            <!-- Nav filter — expanded only -->
            <div class="sh-logo-expanded relative flex items-center">
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
        </div>

        <nav class="sidemenu-nav flex-1 min-h-0 overflow-y-auto scrollbar-thin py-4 space-y-3">
            <p v-if="navFilter && !displayedSections.length" class="sh-logo-expanded px-3 text-xs text-muted">
                {{ t("backend.nav.filter_nav_empty") }}
            </p>
            <div v-for="section in displayedSections" :key="section.id" class="space-y-0.5">
                <button
                    v-if="!navFilter"
                    type="button"
                    class="si-section-header w-full flex items-center justify-between text-xs font-semibold uppercase tracking-wider transition-colors"
                    :class="[sectionHeaderClasses(section.id), sectionLabelClasses(section.id)]"
                    v-on:click="toggleSection(section)"
                >
                    <span class="si-label truncate">{{ section.label }}</span>
                    <ChevronDown class="si-chevron w-3.5 h-3.5 shrink-0 transition-transform" :class="{ '-rotate-90': !isSectionExpanded(section) }" :stroke-width="2.5" />
                </button>

                <template v-for="item in section.items" :key="item.route">
                    <template v-if="navFilter || isSectionExpanded(section)">
                        <!-- Group parent: split link + chevron toggle (only when not filtering) -->
                        <template v-if="!navFilter && item.children?.length">
                            <AppTooltip :title="item.label" :description="item.description" placement="right">
                                <div
                                    class="flex items-center rounded-lg text-sm font-medium transition-colors group relative"
                                    :class="itemClasses(item, section.id)"
                                >
                                    <a
                                        :href="item.path"
                                        :data-sidemenu-active="itemIsActive(item) ? 'true' : null"
                                        class="flex items-center flex-1 min-w-0 gap-3 py-[0.625rem] pl-3"
                                    >
                                        <component :is="item.icon" class="w-5 h-5 shrink-0" :class="iconClasses(item, section.id)" :stroke-width="2" />
                                        <span class="si-label flex-1 truncate">{{ item.label }}</span>
                                    </a>
                                    <AppIconButton
                                        :title="item.label"
                                        class="si-group-chevron si-label mr-1 opacity-50 hover:opacity-100 hover:!bg-transparent"
                                        v-on:click.stop="toggleGroup(item.route)"
                                    >
                                        <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ '-rotate-90': !isGroupExpanded(item.route) }" :stroke-width="2.5" />
                                    </AppIconButton>
                                </div>
                            </AppTooltip>
                            <div v-show="isGroupExpanded(item.route)" class="si-children space-y-0.5">
                                <AppNavLink
                                    v-for="child in item.children"
                                    :key="child.route"
                                    :href="child.path"
                                    :active="isActive(child.route)"
                                    :sidemenu-active="isActive(child.route)"
                                    :link-classes-override="itemClasses(child, section.id)"
                                    :tooltip-title="child.label"
                                    :tooltip-description="child.description"
                                >
                                    <component :is="child.icon" class="w-4 h-4 shrink-0" :class="iconClasses(child, section.id)" :stroke-width="2" />
                                    <span class="si-label truncate">{{ child.label }}</span>
                                </AppNavLink>
                            </div>
                        </template>
                        <!-- Regular item or filtered group parent -->
                        <AppNavLink
                            v-else
                            :href="item.path"
                            :active="itemIsActive(item)"
                            :sidemenu-active="itemIsActive(item)"
                            :link-classes-override="itemClasses(item, section.id)"
                            :tooltip-title="item.label"
                            :tooltip-description="item.description"
                        >
                            <component :is="item.icon" class="w-5 h-5 shrink-0" :class="iconClasses(item, section.id)" :stroke-width="2" />
                            <span class="si-label truncate">{{ item.label }}</span>
                        </AppNavLink>
                    </template>
                </template>
            </div>
        </nav>

        <div class="sidemenu-bottom shrink-0 border-t border-line py-3 space-y-0.5">
            <!-- Folds like a nav section, and for the same reason: five rows
                 that are read once a session should not hold the bottom of the
                 menu open. Same header markup, same chevron, same store — the
                 gesture is one an author already knows from the sections above.

                 `collapsed ||` is what keeps the rows on screen in icon mode.
                 `.si-section-header` is hidden there by the stylesheet, so
                 without it a folded block would have no control left to unfold
                 it — and logging out would be unreachable. -->
            <button
                type="button"
                class="si-section-header w-full flex items-center justify-between text-xs font-semibold uppercase tracking-wider text-muted hover:text-primary transition-colors"
                v-on:click="toggleAccount"
            >
                <span class="si-label truncate">{{ t("backend.nav.sections.account") }}</span>
                <ChevronDown class="si-chevron w-3.5 h-3.5 shrink-0 transition-transform" :class="{ '-rotate-90': !isAccountExpanded() }" :stroke-width="2.5" />
            </button>

            <template v-if="collapsed || isAccountExpanded()">
                <AppNavLink
                    v-if="mailpitUrl"
                    :href="mailpitUrl"
                    target="_blank"
                    hover-color="amber"
                    tooltip-title="Mailpit"
                >
                    <Mail class="w-5 h-5 shrink-0 text-muted group-hover:text-amber-400 transition-colors" :stroke-width="2" />
                    <span class="si-label">Mailpit</span>
                </AppNavLink>

                <AppNavButton
                    :tooltip-title="theme === 'dark' ? t('backend.nav.light_mode') : t('backend.nav.dark_mode')"
                    v-on:click="toggleTheme"
                >
                    <Moon v-if="theme !== 'dark'" class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <Sun v-else class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="si-label">{{ theme === "dark" ? t("backend.nav.light_mode") : t("backend.nav.dark_mode") }}</span>
                </AppNavButton>

                <AppNavLink
                    :href="profilePath"
                    :active="isActiveExact('backend_general_profile')"
                    :tooltip-title="t('backend.nav.profile')"
                >
                    <User class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="si-label truncate">{{ t("backend.nav.profile") }}</span>
                </AppNavLink>

                <AppNavLink
                    :href="sidemenuPreferencesPath"
                    :active="isActive('backend_general_profile_sidemenu')"
                    :tooltip-title="t('backend.profile.preferences.title')"
                >
                    <SlidersHorizontal class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="si-label truncate">{{ t("backend.profile.preferences.title") }}</span>
                </AppNavLink>

                <form :action="logoutPath" method="POST">
                    <input type="hidden" name="_token" :value="logoutCsrf">
                    <AppNavButton
                        type="submit"
                        hover-color="rose"
                        :tooltip-title="t('backend.nav.logout')"
                    >
                        <LogOut class="w-5 h-5 shrink-0 text-muted group-hover:text-rose-400 transition-colors" :stroke-width="2" />
                        <span class="si-label">{{ t("backend.nav.logout") }}</span>
                    </AppNavButton>
                </form>
            </template>
        </div>

        <div class="sh-logo-expanded justify-center py-2 border-t border-line/30">
            <a href="https://github.com/AxelRaboit" target="_blank" rel="noopener" class="text-xs text-muted/40 hover:text-muted/70 transition-colors tracking-wide select-none">
                {{ t('shared.common.built_with') }}
            </a>
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
            <AppButton variant="ghost" size="none" class="p-2" v-on:click="openMobile">
                <MenuIcon class="w-5 h-5" :stroke-width="2" />
            </AppButton>
        </div>
    </div>

    <div
        class="lg:hidden fixed inset-0 z-50 transition-opacity duration-200"
        :class="mobileOpen ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'"
    >
        <div class="absolute inset-0 bg-black/60" v-on:click="closeMobile" />
        <div
            class="relative w-60 max-w-[85vw] bg-surface h-full flex flex-col shadow-2xl transition-transform duration-200"
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

            <nav class="flex-1 overflow-y-auto scrollbar-thin px-3 py-2 space-y-3">
                <div v-for="section in groupedSections" :key="section.id" class="space-y-0.5">
                    <button
                        type="button"
                        class="w-full flex items-center justify-between px-3 py-1.5 text-xs font-semibold uppercase tracking-wider transition-colors"
                        :class="[sectionHeaderClasses(section.id), sectionLabelClasses(section.id)]"
                        v-on:click="toggleSection(section)"
                    >
                        <span class="truncate">{{ section.label }}</span>
                        <ChevronDown class="w-3.5 h-3.5 shrink-0 transition-transform" :class="{ '-rotate-90': !isSectionExpanded(section) }" :stroke-width="2.5" />
                    </button>

                    <template v-for="item in section.items" :key="item.route">
                        <template v-if="isSectionExpanded(section)">
                            <!-- Group parent: split link + chevron -->
                            <template v-if="item.children?.length">
                                <div
                                    class="flex items-center rounded-lg text-sm font-medium transition-colors"
                                    :class="itemClasses(item, section.id)"
                                >
                                    <a :href="item.path" class="flex items-center flex-1 gap-3 px-3 py-2.5">
                                        <component :is="item.icon" class="w-5 h-5 shrink-0" :class="iconClasses(item, section.id)" :stroke-width="2" />
                                        {{ item.label }}
                                    </a>
                                    <AppIconButton :title="item.label" class="mr-1 opacity-50 hover:opacity-100 hover:!bg-transparent" v-on:click.stop="toggleGroup(item.route)">
                                        <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ '-rotate-90': !isGroupExpanded(item.route) }" :stroke-width="2.5" />
                                    </AppIconButton>
                                </div>
                                <div v-show="isGroupExpanded(item.route)" class="space-y-0.5">
                                    <AppNavLink
                                        v-for="child in item.children"
                                        :key="child.route"
                                        :href="child.path"
                                        :active="isActive(child.route)"
                                        :link-classes-override="itemClasses(child, section.id)"
                                    >
                                        <component :is="child.icon" class="w-4 h-4 shrink-0" :class="iconClasses(child, section.id)" :stroke-width="2" />
                                        <span class="truncate">{{ child.label }}</span>
                                        <template #tooltip>{{ child.label }}</template>
                                    </AppNavLink>
                                </div>
                            </template>
                            <!-- Regular item -->
                            <AppNavLink
                                v-else
                                :href="item.path"
                                :active="itemIsActive(item)"
                                :link-classes-override="itemClasses(item, section.id)"
                            >
                                <component :is="item.icon" class="w-5 h-5 shrink-0" :class="iconClasses(item, section.id)" :stroke-width="2" />
                                <span class="si-label truncate">{{ item.label }}</span>
                                <template #tooltip>{{ item.label }}</template>
                            </AppNavLink>
                        </template>
                    </template>
                </div>
            </nav>

            <div class="shrink-0 border-t border-line px-3 py-3 space-y-1">
                <button
                    class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium text-secondary hover:text-primary hover:bg-surface-2 transition-colors"
                    v-on:click="toggleTheme"
                >
                    <Moon v-if="theme !== 'dark'" class="w-5 h-5 text-muted shrink-0" :stroke-width="2" />
                    <Sun v-else class="w-5 h-5 text-muted shrink-0" :stroke-width="2" />
                    {{ theme === "dark" ? t("backend.nav.light_mode") : t("backend.nav.dark_mode") }}
                </button>
                <a
                    :href="profilePath"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
                    :class="isActiveExact('backend_general_profile') ? 'bg-accent-600/15 text-accent-400' : 'text-secondary hover:text-primary hover:bg-surface-2'"
                >
                    <User class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    {{ t("backend.nav.profile") }}
                </a>
                <a
                    :href="sidemenuPreferencesPath"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
                    :class="isActive('backend_general_profile_sidemenu') ? 'bg-accent-600/15 text-accent-400' : 'text-secondary hover:text-primary hover:bg-surface-2'"
                >
                    <SlidersHorizontal class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    {{ t("backend.profile.preferences.title") }}
                </a>
                <form :action="logoutPath" method="POST">
                    <input type="hidden" name="_token" :value="logoutCsrf">
                    <button type="submit" class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium text-secondary hover:text-rose-400 hover:bg-rose-500/10 transition-colors">
                        <LogOut class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                        {{ t("backend.nav.logout") }}
                    </button>
                </form>
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
                                <component :is="SECTION_CONFIG[section.kind].icon" class="w-3 h-3" :stroke-width="2" />
                                {{ t(SECTION_CONFIG[section.kind].labelKey) }}
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
                                        {{ t("backend.posts.status_options." + item.status) }}
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.title ?? '(—)', searchQuery)" />
                                        <div v-if="item.snippet" class="text-xs text-muted line-clamp-2" v-html="highlightMatch(item.snippet, searchQuery)" />
                                        <div class="text-xs text-muted mt-0.5">{{ item.postType }}</div>
                                    </div>
                                </template>

                                <!-- project -->
                                <template v-else-if="section.kind === 'project'">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.title ?? '(—)', searchQuery)" />
                                        <div class="text-xs text-muted">{{ item.reference }} · {{ item.status }}</div>
                                    </div>
                                </template>

                                <!-- task -->
                                <template v-else-if="section.kind === 'task'">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.title ?? '(—)', searchQuery)" />
                                        <div class="text-xs text-muted">{{ item.reference }} · {{ item.projectTitle }}</div>
                                    </div>
                                </template>

                                <!-- term -->
                                <template v-else-if="section.kind === 'term'">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.name ?? '(—)', searchQuery)" />
                                        <div class="text-xs text-muted">{{ item.taxonomy }}</div>
                                    </div>
                                </template>

                                <!-- media -->
                                <template v-else-if="section.kind === 'media'">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-primary truncate" v-html="highlightMatch(item.name ?? '(—)', searchQuery)" />
                                        <div class="text-xs text-muted">{{ item.mimeType }}</div>
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
