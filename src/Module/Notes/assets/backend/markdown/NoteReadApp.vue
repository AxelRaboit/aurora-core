<script setup>
/**
 * Le lecteur : le carnet se lit, dans un espace épuré.
 *
 * Pas de back-office autour - ni menu, ni barre du haut : l'arborescence à
 * gauche, le texte au milieu, à largeur de lecture. C'est la lecture
 * d'Obsidian ou de Notion, et c'est ce qu'Axel a demandé : « un espace
 * épuré, sans le layout habituel ».
 *
 * **L'arborescence se range.** Sur ordinateur, elle se replie d'un geste et
 * s'en souvient ; sur téléphone, elle vit dans un tiroir derrière le bouton
 * « Sommaire », qui se referme dès qu'on a choisi.
 *
 * **On tourne les pages.** Précédente et suivante suivent l'ordre de
 * l'arborescence - sous-dossiers d'abord, puis les notes - pour lire un
 * dossier entier d'affilée ; les flèches du clavier font la même chose.
 *
 * **Le rendu est celui du partage**, le même composant : ce qu'on lit ici est
 * exactement ce qu'un invité lirait, et les deux ne peuvent pas diverger.
 */
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ArrowLeft, ArrowRight, ChevronRight, ListTree, PanelLeftClose, PanelLeftOpen, Pencil, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppBackLink from "@/shared/components/nav/AppBackLink.vue";
import NoteShareApp from "@notes/share/NoteShareApp.vue";
import NoteReaderNav from "./components/NoteReaderNav.vue";

const props = defineProps({
    noteId: { type: Number, required: true },
    noteTitle: { type: String, default: "" },
    content: { type: String, default: "" },
    cover: { type: Object, default: null },
    appearance: { type: String, default: "plain" },
    titleIndex: { type: Object, default: () => ({}) },
    readNotePath: { type: String, required: true },
    imagePrefix: { type: String, default: "" },
    noteImagePath: { type: String, default: "" },
    /** Les dossiers de la note depuis la racine ; vide pour la note d'un autre. */
    breadcrumb: { type: Array, default: () => [] },
    /** Vrai seulement chez soi : la note d'un autre se lit, elle ne s'écrit pas. */
    canEdit: { type: Boolean, default: false },
    backPath: { type: String, default: "" },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
    libraryPath: { type: String, required: true },
    folderShowPath: { type: String, required: true },
    treeFolders: { type: Array, default: () => [] },
    treeNotes: { type: Array, default: () => [] },
    sharedFolders: { type: Array, default: () => [] },
    sharedNotes: { type: Array, default: () => [] },
    searchPath: { type: String, default: "" },
});

const { t } = useI18n();

const SIDEBAR_KEY = "aurora.notes.reader.sidebar";

/** Où ramène le retour : l'édition de sa note, sinon la bibliothèque. */
const exitPath = computed(() => (props.canEdit && props.backPath ? props.backPath : props.libraryPath));
const exitLabel = computed(() =>
    props.canEdit && props.backPath ? t('notes.markdown.read.back') : t('notes.markdown.library.title'),
);

const readUrl = (id) => props.readNotePath.replace("__id__", String(id));
const folderUrl = (id) => props.folderShowPath.replace("__id__", String(id));
const titleOf = (note) => note?.title?.trim() || t("notes.markdown.untitled");

// ── L'arborescence : colonne sur ordinateur, tiroir sur téléphone ──

function readSidebar() {
    try {
        return "0" !== window.localStorage.getItem(SIDEBAR_KEY);
    } catch {
        return true;
    }
}

const sidebarOpen = ref(readSidebar());
const drawerOpen = ref(false);

watch(sidebarOpen, (open) => {
    try {
        window.localStorage.setItem(SIDEBAR_KEY, open ? "1" : "0");
    } catch {
        // Une préférence d'affichage, rien de plus.
    }
});

/**
 * Le même bouton sert les deux tailles : il ouvre le tiroir sur un
 * téléphone, il replie la colonne sur un ordinateur.
 */
function toggleNav() {
    if (window.matchMedia?.("(min-width: 768px)").matches) {
        sidebarOpen.value = !sidebarOpen.value;

        return;
    }

    drawerOpen.value = !drawerOpen.value;
}

/**
 * Les flèches tournent les pages, Échap referme le tiroir. Rien de tout cela
 * quand on tape : la recherche garde ses touches.
 */
function onKeydown(event) {
    // Alt+R, la même touche qui a ouvert le lecteur, ramène à l'écriture.
    if (event.altKey && !event.ctrlKey && !event.metaKey && "KeyR" === event.code) {
        if (props.canEdit && props.backPath) {
            event.preventDefault();
            window.location.assign(props.backPath);
        }

        return;
    }

    if ("Escape" === event.key && drawerOpen.value) {
        drawerOpen.value = false;

        return;
    }

    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;

    const target = event.target;
    if (target?.closest?.("input, textarea, select, [contenteditable]")) return;

    const to = { ArrowLeft: props.previous, ArrowRight: props.next }[event.key];
    if (!to) return;

    event.preventDefault();
    window.location.assign(readUrl(to.id));
}

onMounted(() => window.addEventListener("keydown", onKeydown));
onUnmounted(() => window.removeEventListener("keydown", onKeydown));
</script>

<template>
    <div class="flex min-h-screen">
        <!-- La colonne, sur ordinateur : elle reste en place quand le texte
             défile. -->
        <aside
            v-if="sidebarOpen"
            data-reader-sidebar
            class="sticky top-0 hidden h-screen w-72 shrink-0 flex-col gap-3 border-r border-line bg-surface p-3 md:flex"
        >
            <!-- Un retour, pas le nom du site : on sort de la lecture pour
                 revenir là d'où l'on vient - l'édition de la note, ou la
                 bibliothèque quand la note est à quelqu'un d'autre. -->
            <AppBackLink
                data-reader-back
                class="self-start"
                :href="exitPath"
                :label="exitLabel"
            />

            <NoteReaderNav
                :note-id="noteId"
                :folders="treeFolders"
                :notes="treeNotes"
                :shared-folders="sharedFolders"
                :shared-notes="sharedNotes"
                :read-note-path="readNotePath"
                :search-path="searchPath"
            />
        </aside>

        <!-- Le tiroir, sur téléphone : il couvre l'écran, et se referme d'un
             toucher à côté, d'Échap, ou en choisissant une note. -->
        <div v-if="drawerOpen" data-reader-drawer class="fixed inset-0 z-40 md:hidden">
            <button
                type="button"
                class="absolute inset-0 bg-black/50"
                :aria-label="t('shared.common.close')"
                v-on:click="drawerOpen = false"
            />
            <div class="absolute inset-y-0 left-0 flex w-[85vw] max-w-sm flex-col gap-3 bg-surface p-3 shadow-xl">
                <div class="flex items-center justify-between gap-2">
                    <AppBackLink :href="exitPath" :label="exitLabel" />
                    <span class="text-sm font-semibold text-primary">{{ t('notes.markdown.read.contents') }}</span>
                    <AppIconButton :title="t('shared.common.close')" v-on:click="drawerOpen = false">
                        <X class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <NoteReaderNav
                    :note-id="noteId"
                    :folders="treeFolders"
                    :notes="treeNotes"
                    :shared-folders="sharedFolders"
                    :shared-notes="sharedNotes"
                    :read-note-path="readNotePath"
                    :search-path="searchPath"
                    v-on:navigate="drawerOpen = false"
                />
            </div>
        </div>

        <main class="min-w-0 flex-1">
            <!-- Une seule barre, discrète : ranger l'arbre, où l'on est, et
                 de quoi repartir écrire. -->
            <header class="sticky top-0 z-10 flex items-center gap-2 border-b border-line bg-body/90 px-3 py-2 backdrop-blur sm:px-6">
                <AppIconButton
                    data-reader-nav-toggle
                    :title="t('notes.markdown.read.contents')"
                    v-on:click="toggleNav"
                >
                    <ListTree class="h-4 w-4 md:hidden" :stroke-width="2" />
                    <PanelLeftClose v-if="sidebarOpen" class="hidden h-4 w-4 md:block" :stroke-width="2" />
                    <PanelLeftOpen v-else class="hidden h-4 w-4 md:block" :stroke-width="2" />
                </AppIconButton>

                <nav
                    data-read-breadcrumb
                    class="flex min-w-0 flex-1 items-center gap-x-1 overflow-hidden text-xs text-muted"
                    :aria-label="t('notes.markdown.breadcrumb')"
                >
                    <a
                        :href="libraryPath"
                        class="hidden shrink-0 rounded px-1 py-0.5 no-underline transition-colors hover:bg-surface-2 hover:text-primary sm:inline"
                    >{{ t('notes.markdown.library.title') }}</a>
                    <template v-for="crumb in breadcrumb" :key="crumb.id">
                        <ChevronRight class="hidden h-3 w-3 shrink-0 sm:block" :stroke-width="2" />
                        <a
                            :href="folderUrl(crumb.id)"
                            data-read-crumb
                            class="hidden max-w-[10rem] truncate rounded px-1 py-0.5 no-underline transition-colors hover:bg-surface-2 hover:text-primary sm:inline"
                            :style="crumb.color ? { color: crumb.color } : null"
                        >{{ crumb.name || t('notes.markdown.folders.untitled') }}</a>
                    </template>
                    <ChevronRight class="hidden h-3 w-3 shrink-0 sm:block" :stroke-width="2" />
                    <span class="min-w-0 truncate px-1 text-secondary" aria-current="page">{{ titleOf({ title: noteTitle }) }}</span>
                </nav>

                <AppButton
                    v-if="canEdit && backPath"
                    variant="secondary"
                    size="sm"
                    data-read-edit
                    :href="backPath"
                >
                    <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                    <span class="hidden sm:inline">{{ t('notes.markdown.read.edit') }}</span>
                </AppButton>
            </header>

            <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 px-3 py-4 sm:px-6 sm:py-8">
                <NoteShareApp
                    :image-prefix="imagePrefix"
                    :share-image-path="noteImagePath"
                    :share-note-path="readNotePath"
                    :note-id="noteId"
                    :note-title="noteTitle"
                    :content="content"
                    :cover="cover"
                    :appearance="appearance"
                    :title-index="titleIndex"
                />

                <!-- Tourner la page, comme au bas d'un chapitre. -->
                <nav
                    v-if="previous || next"
                    data-read-pager
                    class="grid grid-cols-1 gap-2 sm:grid-cols-2"
                    :aria-label="t('notes.markdown.read.pager')"
                >
                    <a
                        v-if="previous"
                        :href="readUrl(previous.id)"
                        data-read-previous
                        class="aurora-card flex min-w-0 items-center gap-3 p-3 no-underline transition-colors hover:bg-surface-2"
                    >
                        <ArrowLeft class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                        <span class="min-w-0">
                            <span class="block text-xs text-muted">{{ t('notes.markdown.read.previous') }}</span>
                            <span class="block truncate text-sm font-medium text-primary">{{ titleOf(previous) }}</span>
                        </span>
                    </a>
                    <span v-else class="hidden sm:block" />

                    <a
                        v-if="next"
                        :href="readUrl(next.id)"
                        data-read-next
                        class="aurora-card flex min-w-0 items-center justify-end gap-3 p-3 text-right no-underline transition-colors hover:bg-surface-2"
                    >
                        <span class="min-w-0">
                            <span class="block text-xs text-muted">{{ t('notes.markdown.read.next') }}</span>
                            <span class="block truncate text-sm font-medium text-primary">{{ titleOf(next) }}</span>
                        </span>
                        <ArrowRight class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                    </a>
                </nav>
            </div>
        </main>
    </div>
</template>
