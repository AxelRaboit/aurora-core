<script setup>
/**
 * Search and notifications, at the top right of every backend page.
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
import { useI18n } from "vue-i18n";
import { Search } from "lucide-vue-next";
import AppNotificationsBell from "@core/backend/notifications/AppNotificationsBell.vue";
import AppTopbarAccount from "./AppTopbarAccount.vue";
import { SEARCH_OPEN_EVENT } from "./composables/useBackendSearch.js";

defineProps({
    notificationsListPath: { type: String, default: "" },
    notificationsMarkReadPath: { type: String, default: "" },
    notificationsMarkAllReadPath: { type: String, default: "" },
    notificationsDeletePath: { type: String, default: "" },
    notificationsDeleteAllPath: { type: String, default: "" },
    /**
     * Le compte, qui vivait au pied du menu.
     *
     * Trois lignes et cinq entrées dépliables pour une chose qu'on touche
     * une fois par jour, dans la colonne qui sert à naviguer : ce n'est pas
     * de la navigation, et cette place coûtait au menu sa hauteur utile.
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

function openSearch() {
    window.dispatchEvent(new CustomEvent(SEARCH_OPEN_EVENT));
}
</script>

<template>
    <div class="flex items-center gap-1">
        <button
            type="button"
            class="shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            :title="t('backend.search.button')"
            :aria-label="t('backend.search.button')"
            v-on:click="openSearch"
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
