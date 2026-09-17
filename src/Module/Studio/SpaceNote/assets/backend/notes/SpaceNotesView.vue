<script setup>
/**
 * Le mur de notes d'un espace.
 *
 * **La seule surface d'un espace que le client ne voit pas.** Le fil d'une
 * fiche et la discussion sont partagés, et les écrans le disent là où l'on
 * tape ; une note est ce qu'on écrit pour soi - le brief pris au téléphone,
 * l'idée pas encore présentable, ce qui a mal tourné le mois dernier.
 *
 * **Deux vues et une seule liste.** Le mur et la liste lisent les mêmes notes
 * dans le même ordre, parce que ce sont deux lectures de la même chose. Le mur
 * répond à « qu'est-ce qu'il y a sur cet espace », la liste à « où est celle
 * que je cherche » - et un conteneur étroit impose les cartes quel que soit le
 * choix, sans l'effacer.
 */
import { computed, toRef } from "vue";
import { useI18n } from "vue-i18n";
import { LayoutGrid, List, Pencil, Pin, PinOff, Plus, StickyNote, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { excerptOfBlocks } from "./composables/excerptOfBlocks.js";

const props = defineProps({
    notes: { type: Array, default: () => [] },
    viewMode: { type: String, default: "grid" },
    storedViewMode: { type: String, default: "grid" },
    editable: { type: Boolean, default: true },
});

const emit = defineEmits(["create", "open", "pin", "delete", "set-view"]);

const { t, d } = useI18n();

const rows = computed(() =>
    props.notes.map((note) => ({
        ...note,
        excerpt: excerptOfBlocks(note.body),
    })),
);

/** La couleur du post-it, ou rien - « aucune » est une réponse. */
function tint(note) {
    return note.colourSlot
        ? { borderColor: `var(--chart-cat-${note.colourSlot})` }
        : {};
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <AppButton v-if="editable" variant="primary" size="sm" v-on:click="emit('create')">
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.space_notes.add") }}
            </AppButton>
            <span v-else />

            <!-- Le même sélecteur que la vue Fichiers, et au même endroit :
                 deux listes de la même page qui se lisent en carte ou en ligne
                 ne devraient pas se commander différemment. -->
            <div
                class="flex items-center gap-0.5 rounded-lg border border-line/60 bg-surface-2/40 p-0.5"
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
            :message="t('backend.studio.space_notes.empty')"
            :hint="t('backend.studio.space_notes.empty_hint')"
        />

        <!-- Le mur -->
        <div
            v-else-if="'grid' === viewMode"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="note in rows"
                :key="note.id"
                class="group flex flex-col rounded-lg border-l-4 border border-line/60 bg-surface-2/40 p-3 transition-colors hover:bg-surface-2/70"
                :style="tint(note)"
            >
                <header class="flex items-start gap-2">
                    <Pin
                        v-if="note.pinned"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-accent-500"
                        :stroke-width="2"
                    />
                    <h3 class="flex-1 text-sm font-medium text-primary">{{ note.title }}</h3>
                </header>

                <p class="mt-2 line-clamp-6 whitespace-pre-line text-sm text-secondary">
                    {{ note.excerpt }}
                </p>

                <footer class="mt-3 flex items-center gap-2 text-xs text-muted">
                    <span>{{ note.author }}</span>
                    <span>·</span>
                    <span>{{ d(new Date(note.updatedAt), "short") }}</span>

                    <span v-if="editable" class="ml-auto flex items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                        <AppIconButton
                            :title="t(note.pinned ? 'backend.studio.space_notes.unpin' : 'backend.studio.space_notes.pin')"
                            v-on:click="emit('pin', note)"
                        >
                            <component :is="note.pinned ? PinOff : Pin" class="h-3.5 w-3.5" :stroke-width="2" />
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
        <ul v-else class="divide-y divide-line/40 rounded-lg border border-line/60 bg-surface">
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

                <span v-if="editable" class="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                    <AppIconButton
                        :title="t(note.pinned ? 'backend.studio.space_notes.unpin' : 'backend.studio.space_notes.pin')"
                        v-on:click="emit('pin', note)"
                    >
                        <component :is="note.pinned ? PinOff : Pin" class="h-3.5 w-3.5" :stroke-width="2" />
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
