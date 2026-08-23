<script setup>
/**
 * The account block at the foot of the menu: who you are, and what you can do
 * about it.
 *
 * **One component for both menus.** This file's markup used to exist twice -
 * once in the desktop `<aside>`, once in the mobile drawer in plain classes - and the two drifted exactly as two
 * copies do: the block was made foldable on the desktop side only, and the
 * notifications bell went missing on the mobile side. Each was a separate bug
 * report.
 *
 * What kept them apart was not taste, it was CSS: `.sidemenu-collapsed` sits on
 * `<html>`, so the aside's collapsed rules reached the drawer too and a menu
 * folded on a desktop session would fold the drawer's rows on a phone. Those
 * rules are scoped to `#sidemenu` now, which is what makes this possible.
 */
import { useI18n } from "vue-i18n";
import { ChevronDown, LogOut, Mail, Moon, SlidersHorizontal, Sun, User } from "lucide-vue-next";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import AppNavButton from "@/shared/components/nav/AppNavButton.vue";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";

const props = defineProps({
    userName: { type: String, default: "" },
    userEmail: { type: String, default: "" },
    userPhotoUrl: { type: String, default: "" },
    /** Dev only - empty in production, and the row disappears with it. */
    mailpitUrl: { type: String, default: "" },
    profilePath: { type: String, required: true },
    preferencesPath: { type: String, required: true },
    logoutPath: { type: String, required: true },
    logoutCsrf: { type: String, default: "" },
    profileActive: { type: Boolean, default: false },
    preferencesActive: { type: Boolean, default: false },
    theme: { type: String, default: "light" },
    /** Whether the rows are showing. */
    expanded: { type: Boolean, default: true },
});

const emit = defineEmits(["toggle", "toggle-theme"]);

const { t } = useI18n();
</script>

<template>
    <div class="flex flex-col gap-0.5">
        <!-- The account's own name heads the block that acts on it, and doubles
             as the fold control: the name is both the label and the button. -->
        <button
            type="button"
            class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-surface-2"
            :aria-expanded="expanded"
            v-on:click="emit('toggle')"
        >
            <AppAvatar
                variant="solid"
                :name="userName"
                :photo-url="userPhotoUrl"
                size="md"
                class="shrink-0"
            />
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="truncate text-sm font-medium text-primary">{{ userName }}</span>
                <span class="truncate text-xs text-muted">{{ userEmail }}</span>
            </span>
            <ChevronDown
                class="w-3.5 h-3.5 shrink-0 text-muted transition-transform"
                :class="{ '-rotate-90': !expanded }"
                :stroke-width="2.5"
            />
        </button>

        <template v-if="expanded">
            <AppNavLink
                v-if="mailpitUrl"
                :href="mailpitUrl"
                target="_blank"
                hover-color="amber"
                tooltip-title="Mailpit"
            >
                <Mail class="w-5 h-5 shrink-0 text-muted group-hover:text-amber-400 transition-colors" :stroke-width="2" />
                <span>Mailpit</span>
            </AppNavLink>

            <AppNavButton
                :tooltip-title="theme === 'dark' ? t('backend.nav.light_mode') : t('backend.nav.dark_mode')"
                v-on:click="emit('toggle-theme')"
            >
                <Moon v-if="theme !== 'dark'" class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                <Sun v-else class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                <span>{{ theme === "dark" ? t("backend.nav.light_mode") : t("backend.nav.dark_mode") }}</span>
            </AppNavButton>

            <AppNavLink :href="profilePath" :active="profileActive" :tooltip-title="t('backend.nav.profile')">
                <User class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                <span class="truncate">{{ t("backend.nav.profile") }}</span>
            </AppNavLink>

            <AppNavLink :href="preferencesPath" :active="preferencesActive" :tooltip-title="t('backend.profile.preferences.title')">
                <SlidersHorizontal class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                <span class="truncate">{{ t("backend.profile.preferences.title") }}</span>
            </AppNavLink>

            <form :action="logoutPath" method="POST">
                <input type="hidden" name="_token" :value="logoutCsrf">
                <AppNavButton type="submit" hover-color="rose" :tooltip-title="t('backend.nav.logout')">
                    <LogOut class="w-5 h-5 shrink-0 text-muted group-hover:text-rose-400 transition-colors" :stroke-width="2" />
                    <span>{{ t("backend.nav.logout") }}</span>
                </AppNavButton>
            </form>
        </template>
    </div>
</template>
