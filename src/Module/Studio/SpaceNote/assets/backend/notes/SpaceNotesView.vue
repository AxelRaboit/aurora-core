<script setup>
/**
 * Le mur de notes d'un espace.
 *
 * **La seule surface d'un espace que le client ne voit pas.** Le fil d'une
 * fiche et la discussion sont partagés, et les écrans le disent là où l'on
 * tape ; une note est ce qu'on écrit pour soi - le brief pris au téléphone,
 * l'idée pas encore présentable, ce qui a mal tourné le mois dernier.
 *
 * **Deux murs, et le second n'est qu'à vous.** Partagées avec l'équipe, ou
 * personnelles : ni l'une ni l'autre n'est jamais montrée au client, ce qui se
 * sépare ici c'est l'équipe et la personne. Les notes personnelles des autres
 * ne sont pas cachées par ces onglets, elles ne sont jamais arrivées.
 *
 * **Deux vues et une seule liste.** Le mur et la liste lisent les mêmes notes
 * dans le même ordre, parce que ce sont deux lectures de la même chose. Le mur
 * répond à « qu'est-ce qu'il y a sur cet espace », la liste à « où est celle
 * que je cherche » - et un conteneur étroit impose les cartes quel que soit le
 * choix, sans l'effacer.
 */
import { computed, toRef } from "vue";
import { useI18n } from "vue-i18n";
import { Download, LayoutGrid, List, Lock, Pencil, Pin, PinOff, Plus, RefreshCw, StickyNote, Trash2, Users } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { excerptOfBlocks } from "./composables/excerptOfBlocks.js";

const props = defineProps({
    notes: { type: Array, default: () => [] },
    viewMode: { type: String, default: "grid" },
    storedViewMode: { type: String, default: "grid" },
    editable: { type: Boolean, default: true },
    /** L'onglet ouvert : "shared" ou "personal". */
    tab: { type: String, default: "shared" },
    /** Les deux onglets et ce qu'il y a derrière chacun. */
    tabs: { type: Array, default: () => [] },
    /** L'installation a-t-elle ouvert une connexion Craft. */
    craftEnabled: { type: Boolean, default: false },
});

const emit = defineEmits(["create", "open", "pin", "delete", "set-view", "set-tab", "import-craft", "refresh-craft"]);

const { t, d } = useI18n();

const rows = computed(() =>
    props.notes.map((note) => ({
        ...note,
        excerpt: excerptOfBlocks(note.body),
    })),
);

/**
 * La couleur du post-it, ou rien - « aucune » est une réponse.
 *
 * Un filet sur la tranche et un voile qui se dissout vers le bas, plutôt qu'un
 * contour entier : la couleur sert à repérer une note d'un coup d'œil sur le
 * mur, pas à entourer son texte. Cerner les quatre côtés donnait autant de
 * poids à la couleur qu'au contenu, et un mur de cadres colorés se lit moins
 * bien qu'un mur de cartes.
 *
 * `backgroundImage` et non `background` : le fond de la carte reste celui de
 * sa classe, le voile se pose dessus et suit donc le thème.
 */
function tint(note) {
    if (!note.colourSlot) return {};

    const colour = `var(--chart-cat-${note.colourSlot})`;

    return {
        borderLeftColor: colour,
        backgroundImage: `linear-gradient(160deg, color-mix(in srgb, ${colour} 12%, transparent), transparent 60%)`,
    };
}
</script>

<template>
    <div class="space-y-2 sm:space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex w-full min-w-0 max-w-full flex-col items-stretch gap-3 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
                <!-- Pleine largeur sous `sm` : c'est le geste de l'écran, et
                     cent vingt-huit pixels à côté d'une bande d'onglets qui en
                     fait deux cent soixante-quatorze le font passer pour un
                     détail de la bande. -->
                <AppButton
                    v-if="editable"
                    class="w-full sm:w-auto"
                    variant="primary"
                    size="sm"
                    v-on:click="emit('create')"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.space_notes.add") }}
                </AppButton>

                <!-- Second, et secondaire : écrire une note est le geste de
                     l'écran, en importer une est l'exception. Absent tant que
                     l'installation n'a pas ouvert de connexion Craft. -->
                <AppButton
                    v-if="editable && craftEnabled"
                    class="w-full sm:w-auto"
                    variant="secondary"
                    size="sm"
                    v-on:click="emit('import-craft')"
                >
                    <Download class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.craft.import.action") }}
                </AppButton>

                <!-- Deux onglets, et le compte sur l'étiquette : c'est lui qui
                     rend l'autre visible. Une note écrite pour soi et rangée
                     derrière un onglet que rien n'annonce est une note perdue. -->
                <!-- Borné à la largeur disponible et défilant : « Partagées 3 »
                     et « Personnelles 2 » font 274 pixels, et dans une fenêtre
                     de 250 c'est la page entière qui partait à droite. -->
                <div
                    class="flex max-w-full items-center gap-0.5 overflow-x-auto rounded-lg border border-line bg-surface-2/40 p-0.5"
                    role="group"
                    :aria-label="t('backend.studio.space_notes.visibility')"
                >
                    <button
                        v-for="entry in tabs"
                        :key="entry.key"
                        type="button"
                        class="flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1 text-sm transition-colors"
                        :class="
                            tab === entry.key
                                ? 'bg-surface font-medium text-primary shadow-sm'
                                : 'text-muted hover:text-primary'
                        "
                        :aria-pressed="tab === entry.key"
                        v-on:click="emit('set-tab', entry.key)"
                    >
                        <component
                            :is="'personal' === entry.key ? Lock : Users"
                            class="h-3.5 w-3.5"
                            :stroke-width="2"
                        />
                        {{ t(`backend.studio.space_notes.visibilities.${entry.key}_plural`) }}
                        <span class="text-xs tabular-nums text-muted">{{ entry.count }}</span>
                    </button>
                </div>
            </div>

            <!-- Le même sélecteur que la vue Fichiers, et au même endroit :
                 deux listes de la même page qui se lisent en carte ou en ligne
                 ne devraient pas se commander différemment. -->
            <div
                class="flex items-center gap-0.5 rounded-lg border border-line bg-surface-2/40 p-0.5"
                role="group"
                :aria-label="t('backend.studio.space_notes.view_label')"
            >
                <AppIconButton
                    :title="t('backend.studio.space_notes.view_grid')"
                    :aria-pressed="'grid' === storedViewMode"
                    v-on:click="emit('set-view', 'grid')"
                >
                    <LayoutGrid class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton
                    :title="t('backend.studio.space_notes.view_list')"
                    :aria-pressed="'list' === storedViewMode"
                    v-on:click="emit('set-view', 'list')"
                >
                    <List class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
            </div>
        </div>

        <AppNoData
            v-if="!rows.length"
            :message="t(`backend.studio.space_notes.empty_${tab}`)"
            :hint="t(`backend.studio.space_notes.empty_${tab}_hint`)"
        />

        <!-- Le mur -->
        <div
            v-else-if="'grid' === viewMode"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="note in rows"
                :key="note.id"
                class="group flex min-w-0 flex-col rounded-lg border border-l-[3px] border-line bg-surface-2/40 p-3 transition-colors hover:bg-surface-2/70"
                :style="tint(note)"
            >
                <header class="flex items-start gap-2">
                    <Pin
                        v-if="note.pinned"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-accent-500"
                        :stroke-width="2"
                    />
                    <h3 class="min-w-0 flex-1 break-words text-sm font-medium text-primary">
                        {{ note.title }}
                    </h3>
                </header>

                <p class="mt-2 line-clamp-6 whitespace-pre-line break-words text-sm text-secondary">
                    {{ note.excerpt }}
                </p>

                <!-- Poussé en bas de la carte plutôt que collé au texte : les cartes
                     d'une ligne ont la hauteur de la plus haute, et une signature
                     qui flotte à mi-hauteur donne une grille qui n'aligne rien. -->
                <footer class="mt-auto flex items-center gap-2 pt-3 text-xs text-muted">
                    <span>{{ note.author }}</span>
                    <span>·</span>
                    <span>{{ d(new Date(note.updatedAt), "short") }}</span>

                    <span v-if="editable" class="ml-auto flex items-center gap-0.5 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">
                        <AppIconButton
                            :title="t(note.pinned ? 'backend.studio.space_notes.unpin' : 'backend.studio.space_notes.pin')"
                            v-on:click="emit('pin', note)"
                        >
                            <component :is="note.pinned ? PinOff : Pin" class="h-3.5 w-3.5" :stroke-width="2" />
                        </AppIconButton>
                        <!-- Seulement sur une note venue de Craft : ailleurs
                             il n'y a rien à rafraîchir depuis nulle part. -->
                        <AppIconButton
                            v-if="note.fromCraft"
                            :title="t('backend.studio.craft.import.refresh')"
                            v-on:click="emit('refresh-craft', note)"
                        >
                            <RefreshCw class="h-3.5 w-3.5" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            :title="t('shared.common.edit')"
                            v-on:click="emit('open', note)"
                        >
                            <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            :title="t('shared.common.delete')"
                            v-on:click="emit('delete', note)"
                        >
                            <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        </AppIconButton>
                    </span>
                </footer>
            </article>
        </div>

        <!-- La liste -->
        <ul v-else class="aurora-card divide-y divide-line/40/40">
            <li
                v-for="note in rows"
                :key="note.id"
                class="group flex items-center gap-3 px-4 py-2.5"
            >
                <StickyNote
                    class="h-4 w-4 shrink-0"
                    :style="note.colourSlot ? { color: `var(--chart-cat-${note.colourSlot})` } : {}"
                    :stroke-width="2"
                />
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-1.5 truncate text-sm font-medium text-primary">
                        <Pin v-if="note.pinned" class="h-3 w-3 shrink-0 text-accent-500" :stroke-width="2" />
                        {{ note.title }}
                    </p>
                    <p class="truncate text-xs text-muted">{{ note.excerpt }}</p>
                </div>
                <span class="hidden shrink-0 text-xs text-muted sm:inline">{{ note.author }}</span>
                <span class="shrink-0 text-xs text-muted">{{ d(new Date(note.updatedAt), "short") }}</span>

                <span v-if="editable" class="flex shrink-0 items-center gap-0.5 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">
                    <AppIconButton
                        :title="t(note.pinned ? 'backend.studio.space_notes.unpin' : 'backend.studio.space_notes.pin')"
                        v-on:click="emit('pin', note)"
                    >
                        <component :is="note.pinned ? PinOff : Pin" class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        v-if="note.fromCraft"
                        :title="t('backend.studio.craft.import.refresh')"
                        v-on:click="emit('refresh-craft', note)"
                    >
                        <RefreshCw class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton :title="t('shared.common.edit')" v-on:click="emit('open', note)">
                        <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton :title="t('shared.common.delete')" v-on:click="emit('delete', note)">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                </span>
            </li>
        </ul>
    </div>
</template>
