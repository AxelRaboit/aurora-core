<script setup>
/**
 * Search and notifications, at the top right of every suite page.
 *
 * They used to sit in the side menu, above the nav filter - which put two
 * controls that have nothing to do with navigation inside the thing that
 * navigates, and hid them both whenever the menu was folded to icons.
 *
 * The bell moved as it was: it owns its own panel and only needed its paths.
 * **The search button could not.** Its palette is a large piece of markup
 * living in `AppSidemenu`, and dragging that across would be moving a feature
 * to move a button. So the button announces itself and the menu opens the
 * palette - `SEARCH_OPEN_EVENT`, the same mechanism the fold control uses,
 * because these are two Vue apps that cannot see each other's refs.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Moon, Search, Sun } from "lucide-vue-next";
import { useTheme } from "@/shared/composables/useTheme.js";
import AppNotificationsBell from "@core/suite/notifications/AppNotificationsBell.vue";
import AppTopbarAccount from "./AppTopbarAccount.vue";
import { SEARCH_OPEN_EVENT } from "./composables/useSuiteSearch.js";

defineProps({
    notificationsListPath: { type: String, default: "" },
    notificationsMarkReadPath: { type: String, default: "" },
    notificationsMarkAllReadPath: { type: String, default: "" },
    notificationsDeletePath: { type: String, default: "" },
    notificationsDeleteAllPath: { type: String, default: "" },
    /**
     * The account, which used to live at the foot of the menu.
     *
     * Three lines and five expandable entries for something touched once a
     * day, in the column used to navigate: it is not navigation, and that
     * spot cost the menu its useful height.
     */
    userName: { type: String, default: "" },
    userEmail: { type: String, default: "" },
    userPhotoUrl: { type: String, default: "" },
    mailpitUrl: { type: String, default: "" },
    profilePath: { type: String, default: "" },
    preferencesPath: { type: String, default: "" },
    logoutPath: { type: String, default: "" },
    logoutCsrf: { type: String, default: "" },
});

const { t } = useI18n();

/**
 * Light and dark, one click away instead of two.
 *
 * The switch also lives in the account menu, where it is one entry among
 * five; here it sits with the other controls of the frame, as a bare icon
 * like its neighbours. The icon shows the mode the click leads to, the label
 * says it in words - the same pair the account menu uses.
 */
const { theme, toggle: toggleTheme } = useTheme();
const themeLabel = computed(() => (theme.value === "dark" ? t("suite.nav.light_mode") : t("suite.nav.dark_mode")));

function openSearch() {
    window.dispatchEvent(new CustomEvent(SEARCH_OPEN_EVENT));
}
</script>

<template>
    <div class="flex items-center gap-1">
        <button
            type="button"
            class="shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            :title="t('suite.search.button')"
            :aria-label="t('suite.search.button')"
            v-on:click="openSearch"
        >
            <Search class="w-5 h-5" :stroke-width="2" />
        </button>

        <button
            type="button"
            data-topbar-theme
            class="shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            :title="themeLabel"
            :aria-label="themeLabel"
            v-on:click="toggleTheme"
        >
            <Moon v-if="theme !== 'dark'" class="w-5 h-5" :stroke-width="2" />
            <Sun v-else class="w-5 h-5" :stroke-width="2" />
        </button>

        <AppNotificationsBell
            v-if="notificationsListPath"
            :list-path="notificationsListPath"
            :mark-read-path="notificationsMarkReadPath"
            :mark-all-read-path="notificationsMarkAllReadPath"
            :delete-path="notificationsDeletePath"
            :delete-all-path="notificationsDeleteAllPath"
        />

        <AppTopbarAccount
            v-if="logoutPath"
            :user-name="userName"
            :user-email="userEmail"
            :user-photo-url="userPhotoUrl"
            :mailpit-url="mailpitUrl"
            :profile-path="profilePath"
            :preferences-path="preferencesPath"
            :logout-path="logoutPath"
            :logout-csrf="logoutCsrf"
        />
    </div>
</template>
