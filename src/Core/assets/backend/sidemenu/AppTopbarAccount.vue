<script setup>
/**
 * Le compte, en haut à droite, sous son avatar.
 *
 * Il occupait le pied de la colonne de navigation : un bloc de trois lignes
 * plus cinq entrées dépliables, pour une chose qu'on touche une fois par
 * jour. **Ce n'est pas de la navigation**, c'est l'identité de la personne
 * et les gestes qui s'y rapportent, et cette place coûtait au menu sa
 * hauteur utile.
 *
 * C'est le même déménagement que la recherche et les notifications, pour la
 * même raison, et la place est cohérente : l'avatar est ce qu'on cherche des
 * yeux pour savoir sous quel compte on travaille.
 *
 * Le menu se referme au clic dehors et à Échap, et le bouton reste
 * atteignable au clavier : c'est une feuille de commandes, pas un survol.
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
 * Se refermer sur un clic ailleurs et sur Échap.
 *
 * Les deux écoutes vivent sur le document parce que le clic qui referme se
 * produit, par définition, hors du composant. Elles sont posées une fois et
 * retirées au démontage : une feuille qui laisse son écoute derrière elle
 * fait réagir un composant qui n'est plus à l'écran.
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
            <!-- Qui l'on est, en tête : la même information qu'en pied de
                 menu, mais elle n'a plus à rester affichée en permanence
                 pour être trouvable. -->
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
                    <span>{{ theme === "dark" ? t("backend.nav.light_mode") : t("backend.nav.dark_mode") }}</span>
                </AppNavButton>

                <AppNavLink v-if="profilePath" :href="profilePath">
                    <User class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="truncate">{{ t("backend.nav.profile") }}</span>
                </AppNavLink>

                <AppNavLink v-if="preferencesPath" :href="preferencesPath">
                    <SlidersHorizontal class="w-5 h-5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="truncate">{{ t("backend.profile.preferences.title") }}</span>
                </AppNavLink>

                <form :action="logoutPath" method="POST">
                    <input type="hidden" name="_token" :value="logoutCsrf">
                    <AppNavButton type="submit" hover-color="rose">
                        <LogOut class="w-5 h-5 shrink-0 text-muted transition-colors group-hover:text-rose-400" :stroke-width="2" />
                        <span>{{ t("backend.nav.logout") }}</span>
                    </AppNavButton>
                </form>
            </div>
        </div>
    </div>
</template>
