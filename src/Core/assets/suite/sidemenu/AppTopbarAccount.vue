<script setup>
/**
 * The account, top right, under its avatar.
 *
 * It used to fill the foot of the navigation column: a three-line block
 * plus five expandable entries, for something touched once a day. **It is
 * not navigation**, it is the person's identity and the actions that go
 * with it, and that spot cost the menu its useful height.
 *
 * It is the same move as search and notifications, for the same reason, and
 * the spot makes sense: the avatar is what the eye looks for to know which
 * account it is working under.
 *
 * The menu closes on an outside click and on Escape, and the button stays
 * reachable from the keyboard: it is a command sheet, not a hover.
 */
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { LogOut, Mail, Moon, SlidersHorizontal, Sun, User } from "lucide-vue-next";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import AppNavButton from "@/shared/components/nav/AppNavButton.vue";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import { useTheme } from "@/shared/composables/useTheme.js";

const props = defineProps({
    userName: { type: String, default: "" },
    userEmail: { type: String, default: "" },
    userPhotoUrl: { type: String, default: "" },
    /** Dev only - empty in production, and the row disappears with it. */
    mailpitUrl: { type: String, default: "" },
    profilePath: { type: String, default: "" },
    preferencesPath: { type: String, default: "" },
    logoutPath: { type: String, default: "" },
    logoutCsrf: { type: String, default: "" },
});

const { t } = useI18n();
const { theme, toggle: toggleTheme } = useTheme();

const open = ref(false);
const root = ref(null);

const initials = computed(() => props.userName || props.userEmail);

function close() {
    open.value = false;
}

/**
 * Close on a click elsewhere and on Escape.
 *
 * Both listeners live on the document because the closing click happens, by
 * definition, outside the component. They are added once and removed on
 * unmount: a sheet that leaves its listener behind makes a component react
 * that is no longer on screen.
 */
function onDocumentClick(event) {
    if (!open.value) return;

    if (!root.value?.contains(event.target)) close();
}

function onKeydown(event) {
    if ("Escape" === event.key) close();
}

onMounted(() => {
    document.addEventListener("click", onDocumentClick);
    document.addEventListener("keydown", onKeydown);
});

onUnmounted(() => {
    document.removeEventListener("click", onDocumentClick);
    document.removeEventListener("keydown", onKeydown);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex shrink-0 items-center rounded-lg p-0.5 transition-colors hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            :title="userName || userEmail"
            :aria-label="userName || userEmail"
            :aria-expanded="open"
            aria-haspopup="menu"
            v-on:click="open = !open"
        >
            <AppAvatar
                variant="solid"
                :name="initials"
                :photo-url="userPhotoUrl"
                size="sm"
            />
        </button>

        <div
            v-if="open"
            class="aurora-card absolute right-0 top-full z-50 mt-1 w-64 overflow-hidden py-1 shadow-xl"
            role="menu"
        >
            <!-- Who you are, at the top: the same information as at the foot
                 of the menu, but it no longer has to stay on screen at all
                 times to be found. -->
            <div class="flex min-w-0 items-center gap-3 px-3 py-2">
                <AppAvatar
                    variant="solid"
                    :name="initials"
                    :photo-url="userPhotoUrl"
                    size="md"
                    class="shrink-0"
                />
                <span class="flex min-w-0 flex-col">
                    <span class="truncate text-sm font-medium text-primary">{{ userName }}</span>
                    <span class="truncate text-xs text-muted">{{ userEmail }}</span>
                </span>
            </div>

            <div class="my-1 border-t border-line" />

            <div class="flex flex-col gap-0.5 px-1">
                <AppNavLink
                    v-if="mailpitUrl"
                    :href="mailpitUrl"
                    target="_blank"
                    hover-color="amber"
                >
                    <Mail class="w-5 h-5 shrink-0 text-muted transition-colors group-hover:text-amber-400" :stroke-width="2" />
                    <span>Mailpit</span>
                </AppNavLink>

                <AppNavButton v-on:click="toggleTheme">
                    <Moon v-if="theme !== 'dark'" class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <Sun v-else class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span>{{ theme === "dark" ? t("suite.nav.light_mode") : t("suite.nav.dark_mode") }}</span>
                </AppNavButton>

                <AppNavLink v-if="profilePath" :href="profilePath">
                    <User class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="truncate">{{ t("suite.nav.profile") }}</span>
                </AppNavLink>

                <AppNavLink v-if="preferencesPath" :href="preferencesPath">
                    <SlidersHorizontal class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="truncate">{{ t("suite.profile.preferences.title") }}</span>
                </AppNavLink>

                <form :action="logoutPath" method="POST">
                    <input type="hidden" name="_token" :value="logoutCsrf">
                    <AppNavButton type="submit" hover-color="rose">
                        <LogOut class="w-5 h-5 shrink-0 text-muted transition-colors group-hover:text-rose-400" :stroke-width="2" />
                        <span>{{ t("suite.nav.logout") }}</span>
                    </AppNavButton>
                </form>
            </div>
        </div>
    </div>
</template>
