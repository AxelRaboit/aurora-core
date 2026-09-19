<script setup>
/**
 * Everything exchanged on a space, newest first, as rows or as cards.
 *
 * **The one question the other three views cannot answer.** The board, the
 * list and the month all read the same rows and differ only in how somebody
 * likes to look at them; each shows a card's files as a thumbnail on that
 * card. None of them answers "what has this client sent us", which is asked
 * when a file arrived last week and nobody remembers which post it was for.
 *
 * Chronological rather than grouped by step, for that reason exactly. Grouping
 * by card would be a second list view, and would bury a photo that arrived
 * this morning under a step full of ideas nobody has written. The card is
 * named on every row instead, and clicking it opens it.
 *
 * **Two shapes, because the two questions differ.** Rows answer "when did this
 * arrive and from whom", which is reading; cards answer "which photo was it",
 * which is looking. The library made the same call for the same reason, so the
 * toggle is {@see useListViewMode} rather than a second implementation of it:
 * the choice lands in the query string, so a link to this view carries it, and
 * a narrow container renders cards whatever the link says without erasing what
 * the reader chose.
 *
 * It draws from the payload the other views already hold, so opening it costs
 * no request and it cannot disagree with the thumbnails on the board.
 *
 * **A file opens in a panel, not in a tab.** Reading this view is a sweep -
 * which photo was it, when did it arrive - and a tab per file turns that sweep
 * into a pile of tabs to close. The panel shows the file over the list it came
 * from, closes on Escape, and still offers the real address for whoever wants
 * the tab or the download.
 */
import { computed, ref, toRef } from "vue";
import { useI18n } from "vue-i18n";
import { useSpaceFiles } from "../composables/useSpaceFiles.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppImage from "@/shared/components/display/AppImage.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppFilePreview from "@/shared/components/display/AppFilePreview.vue";
import { ExternalLink, FileText, FolderOpen, LayoutGrid, List, Trash2, Upload, UserRound, X } from "lucide-vue-next";

const props = defineProps({
    attachments: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    /** Les fichiers de l'espace lui-même, sur aucune fiche. */
    spaceFiles: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["open-item", "upload", "pick", "remove"]);

const { t, d } = useI18n();

const {
    viewMode,
    storedViewMode,
    setViewMode,
    container,
    files,
    itemOf,
    titleOf,
    weightOf,
} = useSpaceFiles(toRef(props, "attachments"), toRef(props, "items"));

/**
 * Opens the card the file is on.
 *
 * The item object rather than its id, because that is what the three other
 * views hand over and what the form reads. Passing the id would set the form's
 * subject to a number and blank every field, silently.
 */
function open(itemId) {
    const item = itemOf(itemId);

    if (item) emit("open-item", item);
}

/**
 * Le fichier montré dans le panneau, ou null.
 *
 * Le composant partagé sait déjà rendre une image, un PDF et le reste ; il n'y
 * a rien de propre aux espaces dans « à quoi ressemble ce fichier ».
 */
const previewed = ref(null);

/**
 * Deux rattachements, une seule liste dessinée.
 *
 * « Sur les fiches » répond à « ce fichier est arrivé la semaine dernière, mais
 * pour quel post » ; « De l'espace » porte ce qui n'illustre rien - la charte,
 * les logos, le brief, un PDF signé. Retenu d'une visite à l'autre, comme les
 * onglets des notes : celui qui range ses chartes à part le fait sur tous ses
 * espaces.
 */
const { choice: tab } = usePersistedChoice("studio.space_files.tab", "linked", [
    "linked",
    "space",
]);

const visible = computed(() => ("space" === tab.value ? props.spaceFiles : files.value));

const tabs = computed(() => [
    { key: "linked", count: files.value.length },
    { key: "space", count: props.spaceFiles.length },
]);

/** Le champ de fichier caché : un bouton se dessine, un `input[type=file]` non. */
const fileInput = ref(null);

function chooseFile(event) {
    const file = event.target.files?.[0];

    if (file) emit("upload", file);

    // Remis à zéro : sans ça, redéposer deux fois le même fichier n'émet rien,
    // le champ n'ayant pas changé de valeur.
    event.target.value = "";
}
</script>

<template>
    <div ref="container">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <!-- Deux rattachements, deux onglets, et le compte sur l'étiquette :
                 c'est lui qui rend l'autre visible. -->
            <div
                class="flex items-center gap-0.5 rounded-lg border border-line/60 bg-surface-2/40 p-0.5"
                role="group"
                :aria-label="t('backend.studio.space_files.label')"
            >
                <button
                    v-for="entry in tabs"
                    :key="entry.key"
                    type="button"
                    class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm transition-colors"
                    :class="
                        tab === entry.key
                            ? 'bg-surface font-medium text-primary shadow-sm'
                            : 'text-muted hover:text-primary'
                    "
                    :aria-pressed="tab === entry.key"
                    v-on:click="tab = entry.key"
                >
                    {{ t(`backend.studio.space_files.tabs.${entry.key}`) }}
                    <span class="text-xs tabular-nums text-muted">{{ entry.count }}</span>
                </button>
            </div>

            <!-- **Les deux gestes prennent la ligne sur téléphone.** Serrés à
                 côté du sélecteur de vue, « Déposer un fichier » tombait à cent
                 dix-sept pixels et « Choisir dans la médiathèque » se repliait
                 sur deux lignes : les gestes de l'écran avaient l'air de la
                 garniture du sélecteur. -->
            <div class="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:items-center">
                <!-- Déposer et choisir ne valent que pour les fichiers de
                     l'espace : sur une fiche, c'est la fiche qui les porte, et
                     c'est là qu'on les y met. -->
                <template v-if="'space' === tab && editable">
                    <input ref="fileInput" type="file" class="hidden" v-on:change="chooseFile">
                    <AppButton
                        class="w-full sm:w-auto"
                        variant="primary"
                        size="sm"
                        :loading="loading"
                        v-on:click="fileInput?.click()"
                    >
                        <Upload class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_files.upload") }}
                    </AppButton>
                    <AppButton
                        class="w-full sm:w-auto"
                        variant="ghost"
                        size="sm"
                        :loading="loading"
                        v-on:click="emit('pick')"
                    >
                        <FolderOpen class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_files.pick") }}
                    </AppButton>
                </template>

                <div v-if="visible.length > 0" class="flex self-start rounded-lg border border-line/60 p-0.5 sm:self-auto">
                    <AppIconButton
                        size="sm"
                        variant="ghost"
                        :title="t('backend.studio.space_content.files_as_cards')"
                        :class="storedViewMode === 'grid' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setViewMode('grid')"
                    >
                        <LayoutGrid class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        size="sm"
                        variant="ghost"
                        :title="t('backend.studio.space_content.files_as_rows')"
                        :class="storedViewMode === 'list' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setViewMode('list')"
                    >
                        <List class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </div>
        </div>

        <AppNoData
            v-if="visible.length === 0"
            :title="t(`backend.studio.space_files.empty_${tab}`)"
            :description="t(`backend.studio.space_files.empty_${tab}_hint`)"
        />

        <!-- **Un fichier par ligne sur téléphone.** Deux colonnes sur trois
             cent soixante-quinze pixels donnaient des vignettes de cent
             soixante-treize : une image qu'on devine plutôt qu'on ne la
             reconnaît, ce qui est tout ce qu'on demande à cette vue. Celle qui
             reste prend la largeur, et le nom sous elle cesse d'être coupé au
             troisième mot. Qui veut voir beaucoup de fichiers d'un coup a la
             liste, juste à côté. -->
        <div
            v-else-if="viewMode === 'grid'"
            class="grid grid-cols-1 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
        >
            <article
                v-for="file in visible"
                :key="file.id"
                class="overflow-hidden rounded-lg border border-line/60 bg-surface transition-colors hover:border-accent-400"
            >
                <!-- Un carré de trois cent soixante pixels mangerait l'écran
                     pour une seule vignette ; quatre tiers en laissent voir
                     deux et demie, et le cadrage est de toute façon décidé par
                     `object-fit: cover`. -->
                <button
                    type="button"
                    class="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden bg-surface-2 sm:aspect-square"
                    :title="t('backend.studio.space_content.files_open')"
                    v-on:click="previewed = file"
                >
                    <AppImage
                        v-if="file.preview"
                        :src="file.preview"
                        :alt="file.title"
                        object-fit="cover"
                    />
                    <FileText v-else class="h-10 w-10 text-muted" :stroke-width="1.5" />
                </button>

                <div class="px-2.5 py-2">
                    <p class="truncate text-xs text-primary" :title="file.title">
                        {{ file.title }}
                    </p>

                    <!-- `py-1.5 -my-1.5` : seize pixels de haut, c'est la
                         hauteur d'une ligne de texte et non celle d'une cible.
                         La zone sensible monte à vingt-huit sans que la carte
                         bouge d'un pixel. -->
                    <button
                        v-if="file.itemId"
                        type="button"
                        class="mt-0.5 block max-w-full truncate py-1.5 -my-1.5 text-xs text-muted underline decoration-dotted underline-offset-2 transition-colors hover:text-primary"
                        v-on:click="open(file.itemId)"
                    >
                        {{ titleOf(file.itemId) }}
                    </button>

                    <p class="mt-1 flex items-center gap-1 text-[0.68rem] text-muted">
                        <UserRound v-if="file.fromClient" class="h-3 w-3" :stroke-width="2" />
                        <span class="truncate">{{ file.author }}</span>
                    </p>
                </div>
            </article>
        </div>

        <ul v-else class="divide-y divide-line/60 rounded-lg border border-line/60">
            <li
                v-for="file in visible"
                :key="file.id"
                class="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-surface-2"
            >
                <img
                    v-if="file.preview"
                    :src="file.preview"
                    :alt="file.title"
                    class="h-10 w-10 shrink-0 rounded object-cover"
                    loading="lazy"
                >
                <span
                    v-else
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-surface-2 text-muted"
                >
                    <FileText class="h-4 w-4" :stroke-width="2" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm text-primary">{{ file.title }}</p>

                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                        <template v-if="file.itemId">
                            <button
                                type="button"
                                class="truncate underline decoration-dotted underline-offset-2 transition-colors hover:text-primary"
                                v-on:click="open(file.itemId)"
                            >
                                {{ titleOf(file.itemId) }}
                            </button>

                            <span aria-hidden="true">·</span>
                        </template>
                        <span class="inline-flex items-center gap-1">
                            <UserRound v-if="file.fromClient" class="h-3 w-3" :stroke-width="2" />
                            {{ file.author }}
                        </span>

                        <span aria-hidden="true">·</span>
                        <span>{{ d(new Date(file.createdAt), "short") }}</span>

                        <template v-if="weightOf(file)">
                            <span aria-hidden="true">·</span>
                            <span>{{ weightOf(file) }}</span>
                        </template>
                    </p>
                </div>

                <button
                    type="button"
                    class="shrink-0 rounded-md border border-line/60 px-2.5 py-1.5 text-xs text-primary transition-colors hover:bg-surface-2"
                    v-on:click="previewed = file"
                >
                    {{ t("backend.studio.space_content.files_open") }}
                </button>

                <!-- Retirer n'est offert que sur les fichiers de l'espace : sur
                     une fiche, le fichier se retire depuis la fiche, là où on
                     voit ce qu'on défait. -->
                <AppIconButton
                    v-if="'space' === tab && editable"
                    size="sm"
                    variant="ghost"
                    :title="t('backend.studio.space_files.remove')"
                    v-on:click="emit('remove', file)"
                >
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                </AppIconButton>
            </li>
        </ul>

        <AppModal
            :show="!!previewed"
            max-width="3xl"
            :title="previewed?.title ?? ''"
            :icon="FileText"
            v-on:close="previewed = null"
        >
            <div class="space-y-3">
                <AppFilePreview
                    :url="previewed?.url ?? ''"
                    :mime="previewed?.mimeType ?? ''"
                    :name="previewed?.title ?? ''"
                    max-height="60vh"
                />

                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                    <button
                        type="button"
                        class="truncate underline decoration-dotted underline-offset-2 transition-colors hover:text-primary"
                        v-on:click="open(previewed.itemId)"
                    >
                        {{ titleOf(previewed?.itemId) }}
                    </button>

                    <span aria-hidden="true">·</span>
                    <span class="inline-flex items-center gap-1">
                        <UserRound v-if="previewed?.fromClient" class="h-3 w-3" :stroke-width="2" />
                        {{ previewed?.author }}
                    </span>

                    <span aria-hidden="true">·</span>
                    <span>{{ previewed ? d(new Date(previewed.createdAt), "short") : "" }}</span>

                    <template v-if="previewed && weightOf(previewed)">
                        <span aria-hidden="true">·</span>
                        <span>{{ weightOf(previewed) }}</span>
                    </template>
                </p>
            </div>

            <template #footer>
                <AppModalFooter>
                    <!-- L'adresse réelle reste offerte : le panneau sert à
                         regarder, le lien sert à télécharger ou à garder le
                         fichier ouvert à côté. -->
                    <AppButton variant="ghost" size="md" :href="previewed?.url" target="_blank">
                        <ExternalLink class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_content.files_open_in_tab") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" v-on:click="previewed = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Ce qu'un module voisin ajoute au bas de cet onglet - aujourd'hui le
             dossier Drive d'un espace. Une fente plutôt qu'une dépendance :
             cette vue n'a pas à connaître Google pour lui faire une place. -->
        <slot name="after" />
    </div>
</template>
