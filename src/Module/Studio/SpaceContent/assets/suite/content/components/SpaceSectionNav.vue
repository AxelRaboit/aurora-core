<script setup>
/**
 * Les sections d'un espace, regroupées.
 *
 * **Un rail sur ordinateur, une feuille ailleurs.** Dix onglets sur une ligne
 * débordaient dès 1 024 pixels, et sur téléphone il ne restait que des icônes
 * à deviner. Regroupés par ce qu'on y fait - travailler, ranger des documents,
 * connaître le client - ils tiennent dans une colonne qu'on lit d'un coup
 * d'œil. Sous `lg`, un bouton dit où l'on est et ouvre la même liste : le nom
 * de la section ouverte répond à « où suis-je », la liste à « où aller ».
 *
 * **Un compteur quand il y a quelque chose à faire, et seulement là.** Le
 * nombre de fichiers ou de notes n'appelle aucun geste ; des publications qui
 * attendent l'avis du client, si. Un compteur sur chaque entrée apprendrait à
 * n'en lire aucun.
 *
 * Les entrées viennent de l'appelant, déjà filtrées (le Drive sans compte de
 * service, les réglages pour qui ne configure pas) ; ce composant ne décide
 * que de leur place.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";

const props = defineProps({
    /** `{ key, labelKey, icon }`, dans l'ordre de la barre. */
    views: { type: Array, required: true },
    /** Par clé de section, un nombre qui appelle un geste. */
    badges: { type: Object, default: () => ({}) },
    /** Les clés dont le compteur signale un retard. */
    urgent: { type: Array, default: () => [] },
});

const view = defineModel({ type: String, required: true });

const { t } = useI18n();

/** Ce qu'on fait dans chaque section ; Réglages, à part, ferme la liste. */
const GROUPS = [
    { key: "work", views: ["content", "calendar", "chat"] },
    { key: "documents", views: ["files", "drive", "deliverables", "notes"] },
    { key: "client", views: ["information", "resources"] },
];

const byKey = computed(() => Object.fromEntries(props.views.map((entry) => [entry.key, entry])));

/** Les groupes qui ont au moins une entrée, et une section inconnue des groupes à la fin. */
const groups = computed(() => {
    const placed = new Set(GROUPS.flatMap((group) => group.views));
    const result = GROUPS.map((group) => ({
        key: group.key,
        entries: group.views.map((key) => byKey.value[key]).filter(Boolean),
    })).filter((group) => group.entries.length);

    const rest = props.views.filter((entry) => !placed.has(entry.key) && "settings" !== entry.key);
    if (rest.length) result.push({ key: "other", entries: rest });

    return result;
});

const settings = computed(() => byKey.value.settings ?? null);
const current = computed(() => byKey.value[view.value] ?? props.views[0]);

const sheetOpen = ref(false);

function go(key) {
    view.value = key;
    sheetOpen.value = false;
}

function badgeOf(key) {
    const count = props.badges[key] ?? 0;

    return count > 0 ? count : null;
}
</script>

<template>
    <!-- Ordinateur : le rail, collé sous l'entête pendant qu'on fait défiler. -->
    <nav
        class="sticky top-20 hidden w-52 shrink-0 flex-col gap-5 self-start lg:flex"
        :aria-label="t('suite.studio.space_content.view_label')"
    >
        <div v-for="group in groups" :key="group.key" class="flex flex-col gap-1">
            <p class="m-0 px-2.5 text-2xs font-semibold uppercase tracking-wider text-muted">
                {{ t(`suite.studio.space_content.nav_groups.${group.key}`) }}
            </p>
            <button
                v-for="entry in group.entries"
                :key="entry.key"
                type="button"
                class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-sm transition-colors"
                :class="view === entry.key ? 'bg-surface-2 font-medium text-primary' : 'text-secondary hover:bg-surface-2/60 hover:text-primary'"
                :aria-current="view === entry.key ? 'page' : undefined"
                v-on:click="go(entry.key)"
            >
                <component
                    :is="entry.icon"
                    class="h-4 w-4 shrink-0"
                    :class="view === entry.key ? 'text-accent-400' : 'text-muted'"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ t(entry.labelKey) }}</span>
                <span
                    v-if="badgeOf(entry.key)"
                    class="rounded-full px-1.5 text-2xs font-semibold tabular-nums"
                    :class="urgent.includes(entry.key) ? 'bg-warning-soft text-warning' : 'bg-accent-500/15 text-accent-400'"
                >
                    {{ badgeOf(entry.key) }}
                </span>
            </button>
        </div>

        <div v-if="settings" class="border-t border-line pt-3">
            <button
                type="button"
                class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-sm transition-colors"
                :class="view === settings.key ? 'bg-surface-2 font-medium text-primary' : 'text-secondary hover:bg-surface-2/60 hover:text-primary'"
                :aria-current="view === settings.key ? 'page' : undefined"
                v-on:click="go(settings.key)"
            >
                <component
                    :is="settings.icon"
                    class="h-4 w-4 shrink-0"
                    :class="view === settings.key ? 'text-accent-400' : 'text-muted'"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ t(settings.labelKey) }}</span>
            </button>
        </div>
    </nav>

    <!-- Téléphone et tablette : la section ouverte, et la liste derrière. -->
    <button
        type="button"
        class="flex min-w-0 items-center gap-2 rounded-lg border border-line bg-surface-2/40 px-3 py-2 text-sm font-medium text-primary transition-colors hover:bg-surface-2 lg:hidden"
        :aria-label="t('suite.studio.space_content.nav_open')"
        aria-haspopup="dialog"
        v-on:click="sheetOpen = true"
    >
        <component :is="current.icon" class="h-4 w-4 shrink-0 text-accent-400" :stroke-width="2" />
        <span class="min-w-0 truncate">{{ t(current.labelKey) }}</span>
        <span
            v-if="Object.values(badges).some((count) => count > 0)"
            class="h-2 w-2 shrink-0 rounded-full bg-accent-400"
            aria-hidden="true"
        />
        <ChevronDown class="ml-auto h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
    </button>

    <AppModal :show="sheetOpen" max-width="sm" :title="t('suite.studio.space_content.nav_title')" v-on:close="sheetOpen = false">
        <div class="flex flex-col gap-4">
            <div v-for="group in groups" :key="group.key" class="flex flex-col gap-1">
                <p class="m-0 px-2.5 text-2xs font-semibold uppercase tracking-wider text-muted">
                    {{ t(`suite.studio.space_content.nav_groups.${group.key}`) }}
                </p>
                <button
                    v-for="entry in group.entries"
                    :key="entry.key"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2.5 text-left text-sm transition-colors"
                    :class="view === entry.key ? 'bg-surface-2 font-medium text-primary' : 'text-secondary hover:bg-surface-2/60'"
                    :aria-current="view === entry.key ? 'page' : undefined"
                    v-on:click="go(entry.key)"
                >
                    <component :is="entry.icon" class="h-4 w-4 shrink-0" :class="view === entry.key ? 'text-accent-400' : 'text-muted'" :stroke-width="2" />
                    <span class="min-w-0 flex-1 truncate">{{ t(entry.labelKey) }}</span>
                    <span
                        v-if="badgeOf(entry.key)"
                        class="rounded-full px-1.5 text-2xs font-semibold tabular-nums"
                        :class="urgent.includes(entry.key) ? 'bg-warning-soft text-warning' : 'bg-accent-500/15 text-accent-400'"
                    >
                        {{ badgeOf(entry.key) }}
                    </span>
                </button>
            </div>
            <!-- Le filet sur un conteneur, pas sur le bouton : posé sur un
                 bouton arrondi, il suivait l'arrondi et dessinait un arc. -->
            <div v-if="settings" class="border-t border-line pt-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2.5 text-left text-sm transition-colors"
                    :class="view === settings.key ? 'bg-surface-2 font-medium text-primary' : 'text-secondary hover:bg-surface-2/60'"
                    :aria-current="view === settings.key ? 'page' : undefined"
                    v-on:click="go(settings.key)"
                >
                    <component
                        :is="settings.icon"
                        class="h-4 w-4 shrink-0"
                        :class="view === settings.key ? 'text-accent-400' : 'text-muted'"
                        :stroke-width="2"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ t(settings.labelKey) }}</span>
                </button>
            </div>
        </div>
    </AppModal>
</template>
