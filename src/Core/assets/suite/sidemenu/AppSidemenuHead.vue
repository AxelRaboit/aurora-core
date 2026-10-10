<script setup>
/**
 * The head of the menu column: the site, the way out to it, where the column
 * is (the project or one module), and the filter.
 *
 * **One head for the desktop column and the phone drawer.** The drawer had its
 * own: a bolder name, "Voir le site" as a full row, the general search where
 * the filter sits, and no word of the module it was showing - so a phone that
 * opened the drawer inside Éditorial had no way left to reach another module
 * (sidemenu audit of 10/10/2026). Two copies of a head drift at every edit;
 * one component cannot.
 *
 * **Shorter than the five rows it replaces.** "Voir le site" is a framed icon
 * beside the site's name, and the module with its way back reads as one line,
 * a path - « ‹ Tous les modules / ÉDITORIAL » - rather than two rows. About
 * sixty pixels handed back to the entries. The version left the head for the
 * column's foot, beside the copyright it belongs with.
 *
 * The filter answers `/` from anywhere on the page (`AppSidemenu`), and the
 * hint says so while the field is empty.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, ChevronsDownUp, ChevronsUpDown, ExternalLink, Filter, X } from "lucide-vue-next";
import AppLogo from "@/shared/components/display/AppLogo.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";

const props = defineProps({
    /** Everything from `useSidemenuNav` the head reads or switches. */
    nav: { type: Object, required: true },
    /** `useSidemenuSectionTheme`, for the module's dot. */
    theme: { type: Object, required: true },
    siteName: { type: String, default: "Aurora" },
    siteLogoUrl: { type: String, default: "" },
    dashboardPath: { type: String, default: "/suite" },
    frontPath: { type: String, default: "/" },
    hasEnabledFronts: { type: Boolean, default: true },
    /** In the drawer: a close button at the end of the site's row. */
    closable: { type: Boolean, default: false },
    /** Whether the `/` hint is worth showing: not on a phone, which has no key for it. */
    showShortcut: { type: Boolean, default: true },
});

const emit = defineEmits(["close"]);

const { t } = useI18n();

const filterInput = ref(null);

/** The filter's text, read from the column's state and written through it. */
const filterText = computed({
    get: () => props.nav.navFilter.value,
    set: (value) => props.nav.setNavFilter(value),
});

/** Puts the caret in the filter, for the `/` shortcut. */
function focusFilter() {
    filterInput.value?.focus();
    filterInput.value?.select();
}

/**
 * Escape in the filter clears it, then gives the page back. It stops there:
 * on the column, Escape also leaves a module view, and clearing a search
 * should not move the reader out of the module they searched in.
 */
function onFilterEscape(event) {
    event.stopPropagation();
    if (filterText.value) {
        filterText.value = "";
        return;
    }
    event.target.blur();
}

defineExpose({ focusFilter });
</script>

<template>
    <div class="sidemenu-head flex shrink-0 flex-col gap-2.5 border-b border-line px-4 pb-3 pt-4">
        <div class="flex min-w-0 items-center gap-2">
            <a :href="dashboardPath" class="flex min-w-0 flex-1 items-center gap-2.5 px-1">
                <img v-if="siteLogoUrl" :src="siteLogoUrl" alt="Logo" class="h-7 w-7 shrink-0 object-contain">
                <AppLogo v-else :size="28" class="shrink-0" />
                <span class="truncate text-[0.9375rem] font-semibold leading-tight tracking-tight text-primary">{{ siteName }}</span>
            </a>
            <!-- It leaves the suite for the public site, so it stays a link,
                 out to a new tab, and says so by its glyph. -->
            <a
                v-if="hasEnabledFronts"
                :href="frontPath"
                target="_blank"
                rel="noopener"
                data-sidemenu-view-site
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-line text-secondary transition-colors hover:bg-surface-2 hover:text-primary"
                :title="t('suite.nav.view_site')"
                :aria-label="t('suite.nav.view_site')"
            >
                <ExternalLink class="h-3.5 w-3.5" :stroke-width="2" />
            </a>
            <AppIconButton
                v-if="closable"
                :title="t('shared.common.close')"
                :aria-label="t('shared.common.close')"
                v-on:click="emit('close')"
            >
                <X class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
        </div>

        <!-- Where the column is, as a path: the way back first, then the
             module, in the dot of its section. -->
        <nav
            v-if="nav.inModuleView.value"
            class="flex min-w-0 items-center gap-1.5 px-1 text-[0.8125rem]"
            :aria-label="t('suite.nav.module_path_label')"
            data-sidemenu-module-path
        >
            <button
                type="button"
                class="flex shrink-0 items-center gap-1 rounded-md text-secondary transition-colors hover:text-primary"
                data-sidemenu-back-to-modules
                v-on:click="nav.backToProject"
            >
                <ChevronLeft class="h-3.5 w-3.5 shrink-0" :stroke-width="2.5" />
                <span>{{ t("suite.nav.back_to_modules") }}</span>
            </button>
            <span class="text-muted" aria-hidden="true">/</span>
            <span class="flex min-w-0 items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-secondary" aria-current="location">
                <span class="size-2 shrink-0 rounded-full" :class="theme.dotClasses(nav.moduleId)" aria-hidden="true" />
                <span class="truncate">{{ nav.moduleLabel.value }}</span>
            </span>
        </nav>

        <!-- The door back into the module, once the reader stepped out. -->
        <button
            v-if="nav.hasModuleView.value && !nav.inModuleView.value"
            type="button"
            class="flex w-fit items-center gap-1 rounded-md px-1 text-[0.8125rem] text-secondary transition-colors hover:text-primary"
            v-on:click="nav.enterModuleView"
        >
            <ChevronRight class="h-3.5 w-3.5 shrink-0" :stroke-width="2.5" />
            <span class="truncate">{{ t("suite.nav.back_to_module", { module: nav.moduleLabel.value }) }}</span>
        </button>

        <div class="flex items-center gap-1">
            <div class="relative flex min-w-0 flex-1 items-center">
                <Filter class="pointer-events-none absolute left-2.5 h-3.5 w-3.5 text-secondary" :stroke-width="2" />
                <input
                    ref="filterInput"
                    v-model="filterText"
                    type="text"
                    :placeholder="t('suite.nav.filter_nav')"
                    :aria-label="t('suite.nav.filter_nav')"
                    class="sidemenu-filter w-full rounded-[10px] border border-line bg-bg py-2 pl-8 pr-8 text-sm text-primary transition-colors placeholder:text-muted"
                    data-sidemenu-filter
                    v-on:keydown.esc="onFilterEscape"
                >
                <button
                    v-if="filterText"
                    type="button"
                    class="absolute right-2.5 text-muted transition-colors hover:text-primary"
                    :aria-label="t('suite.nav.filter_clear')"
                    v-on:click="filterText = ''"
                >
                    <X class="h-3.5 w-3.5" :stroke-width="2.5" />
                </button>
                <kbd
                    v-else-if="showShortcut"
                    class="pointer-events-none absolute right-2.5 rounded border border-line bg-surface px-1.5 font-mono text-[0.6875rem] leading-4 text-muted"
                    :title="t('suite.nav.filter_shortcut')"
                >/</kbd>
            </div>
            <!-- Folds or unfolds the whole menu at once. Gone while filtering:
                 the filter shows every match unfolded and draws no header. -->
            <AppIconButton
                v-if="!filterText"
                data-sidemenu-fold-all
                :title="nav.anyExpanded.value ? t('suite.nav.collapse_all') : t('suite.nav.expand_all')"
                v-on:click="nav.setAllExpanded(!nav.anyExpanded.value)"
            >
                <component :is="nav.anyExpanded.value ? ChevronsDownUp : ChevronsUpDown" class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
        </div>
    </div>
</template>
