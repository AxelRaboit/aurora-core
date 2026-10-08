<script setup>
/**
 * The reader: the notebook is read, in an uncluttered space.
 *
 * No back-office around it - no menu, no top bar: the tree on the left, the
 * text in the middle, at reading width. It is Obsidian's or Notion's reading
 * view, and it is what Axel asked for: "un espace épuré, sans le layout
 * habituel".
 *
 * **The tree tucks away.** On a computer, it folds with one gesture and
 * remembers it; on a phone, it lives in a drawer behind the "Sommaire"
 * button, which closes as soon as a choice is made.
 *
 * **Pages turn.** Previous and next follow the tree's order - subfolders
 * first, then the notes - to read a whole folder in a row; the keyboard
 * arrows do the same.
 *
 * **The rendering is the share's**, the same component: what is read here is
 * exactly what a guest would read, and the two cannot diverge.
 */
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ArrowLeft, ArrowRight, ChevronRight, Clock, Download, FileText, ListTree, PanelLeftClose, PanelLeftOpen, Pencil, Printer, Star, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppActionSheet from "@/shared/components/action/AppActionSheet.vue";
import AppBackLink from "@/shared/components/nav/AppBackLink.vue";
import NoteShareApp from "@notes/share/NoteShareApp.vue";
import NoteReaderNav from "./components/NoteReaderNav.vue";
import NoteReaderOutline from "./components/NoteReaderOutline.vue";
import { readingMinutes, wordCount } from "./composables/noteOutline.js";
import { printWhenReady } from "@notes/share/useNotePrint.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

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
    /** The note's folders from the root; empty for someone else's note. */
    breadcrumb: { type: Array, default: () => [] },
    /** True only at home: someone else's note is read, it is not written. */
    canEdit: { type: Boolean, default: false },
    /** In the reader's favourites, and the address that adds or removes it. */
    favorited: { type: Boolean, default: false },
    favoritePath: { type: String, default: "" },
    backPath: { type: String, default: "" },
    /** The note as a Markdown file; empty on the public reading. */
    exportPath: { type: String, default: "" },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
    libraryPath: { type: String, required: true },
    /** Empty on public reading, where a folder has no page. */
    folderShowPath: { type: String, default: "" },
    treeFolders: { type: Array, default: () => [] },
    treeNotes: { type: Array, default: () => [] },
    /** The readable spaces, one's own first: the tree is grouped by space. */
    treeSpaces: { type: Array, default: () => [] },
    /** The notes handed to this reader one by one, as `id => role`. */
    sharedNotes: { type: Object, default: () => ({}) },
    /**
     * This note's own role when it was handed over on its own, else null.
     *
     * Shown as a word on the page: a note from a notebook the reader knows
     * nothing about, sitting in their reader, otherwise raises the question
     * of where it came from.
     */
    sharedWithViewer: { type: String, default: null },
    searchPath: { type: String, default: "" },
    /**
     * The name of a published space, on its public page: it replaces the
     * link back to the back-office, which leads nowhere for someone without
     * an account.
     */
    publicTitle: { type: String, default: "" },
    /**
     * Opened from the editor's "Imprimer ou exporter en PDF": the dialog
     * opens without a second click, once the images have loaded.
     */
    autoPrint: { type: Boolean, default: false },
});

const { t } = useI18n();

const SIDEBAR_KEY = "aurora.notes.reader.sidebar";

/**
 * Where the back link leads: the library, always (08/10/2026). It used to lead
 * to the editor for one's own note, where the pencil in the bar already goes:
 * two ways to the same place, and none back to the notes.
 */
const exitPath = computed(() => props.libraryPath);
const exitLabel = computed(() => t('notes.markdown.library.title'));

/** Alt+R, said where it is used: on the pencil, which it also presses. */
const editTitle = computed(() => `${t('notes.markdown.read.edit')} (${t('notes.markdown.read.shortcut')})`);

/** Its length, as the editor's panel gives it, said before one starts. */
const words = computed(() => wordCount(props.content));
const minutes = computed(() => readingMinutes(words.value));

/**
 * The note taken away: the Markdown file, or the PDF on a light background
 * that printing gives. Printing left the bar for the export (08/10/2026),
 * and this keeps it one choice away.
 */
const exportActions = computed(() => [
    { key: "markdown", title: t('notes.markdown.read.export_markdown'), icon: FileText, href: props.exportPath },
    { key: "pdf", title: t('notes.markdown.read.export_pdf'), icon: Printer, onSelect: print },
]);

/** The rendered note, where the outline looks for its headings. */
const body = ref(null);

/**
 * The star: one reads a note and thinks of coming back to it. Adding it to
 * favourites does not require going to find it elsewhere.
 */
const { request } = useRequest();
const isFavorite = ref(props.favorited);
const favoriteLabel = computed(() =>
    isFavorite.value ? t("notes.markdown.library.unpin") : t("notes.markdown.library.pin"),
);

async function toggleFavorite() {
    const payload = await request(props.favoritePath, {}, { method: HttpMethod.Post });

    if (undefined !== payload?.favorite) isFavorite.value = Boolean(payload.favorite);
}

const readUrl = (id) => props.readNotePath.replace("__id__", String(id));
const folderUrl = (id) => props.folderShowPath.replace("__id__", String(id));
const titleOf = (note) => note?.title?.trim() || t("notes.markdown.untitled");

// ── The tree: a column on a computer, a drawer on a phone ──────────

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
        // A display preference, nothing more.
    }
});

/**
 * The same button serves both sizes: it opens the drawer on a phone, it
 * folds the column on a computer.
 */
function toggleNav() {
    if (window.matchMedia?.("(min-width: 768px)").matches) {
        sidebarOpen.value = !sidebarOpen.value;

        return;
    }

    drawerOpen.value = !drawerOpen.value;
}

/**
 * The arrows turn the pages, Escape closes the drawer. None of this while
 * typing: the search keeps its keys.
 */
function onKeydown(event) {
    // Alt+R, the same key that opened the reader, goes back to writing.
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

onMounted(() => {
    window.addEventListener("keydown", onKeydown);

    if (props.autoPrint) void printWhenReady(document);
});

function print() {
    void printWhenReady(document);
}
onUnmounted(() => window.removeEventListener("keydown", onKeydown));
</script>

<template>
    <div class="flex min-h-screen">
        <!-- The column, on a computer: it stays in place when the text
             scrolls. -->
        <aside
            v-if="sidebarOpen"
            data-reader-sidebar
            class="sticky top-0 hidden h-screen w-72 shrink-0 flex-col gap-3 border-r border-line bg-surface p-3 md:flex print:hidden"
        >
            <!-- A back link, not the site name: one leaves reading for the
                 library. Writing is the pencil's, in the bar. -->
            <a
                v-if="publicTitle"
                data-reader-public-title
                class="truncate px-1 text-sm font-semibold text-primary no-underline"
                :href="libraryPath"
            >{{ publicTitle }}</a>
            <AppBackLink
                v-else
                data-reader-back
                fill
                :href="exitPath"
                :label="exitLabel"
            />

            <NoteReaderNav
                :note-id="noteId"
                :folders="treeFolders"
                :notes="treeNotes"
                :spaces="treeSpaces"
                :shared-notes="sharedNotes"
                :read-note-path="readNotePath"
                :search-path="searchPath"
            />
        </aside>

        <!-- The drawer, on a phone: it covers the screen, and closes with a
             tap beside it, with Escape, or by picking a note. -->
        <div v-if="drawerOpen" data-reader-drawer class="fixed inset-0 z-40 md:hidden">
            <button
                type="button"
                class="absolute inset-0 bg-black/50"
                :aria-label="t('shared.common.close')"
                v-on:click="drawerOpen = false"
            />
            <div class="absolute inset-y-0 left-0 flex w-[85vw] max-w-sm flex-col gap-3 bg-surface p-3 shadow-xl">
                <div class="flex items-center justify-between gap-2">
                    <a
                        v-if="publicTitle"
                        class="min-w-0 truncate text-sm font-semibold text-primary no-underline"
                        :href="libraryPath"
                    >{{ publicTitle }}</a>
                    <template v-else>
                        <AppBackLink :href="exitPath" :label="exitLabel" />
                        <span class="text-sm font-semibold text-primary">{{ t('notes.markdown.read.contents') }}</span>
                    </template>
                    <AppIconButton :title="t('shared.common.close')" v-on:click="drawerOpen = false">
                        <X class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <NoteReaderNav
                    :note-id="noteId"
                    :folders="treeFolders"
                    :notes="treeNotes"
                    :spaces="treeSpaces"
                    :shared-notes="sharedNotes"
                    :read-note-path="readNotePath"
                    :search-path="searchPath"
                    v-on:navigate="drawerOpen = false"
                />
            </div>
        </div>

        <main class="min-w-0 flex-1">
            <!-- A single, discreet bar: tuck the tree away, where one is, and
                 a way to go back to writing. -->
            <header class="sticky top-0 z-10 flex items-center gap-2 border-b border-line bg-body/90 px-3 py-2 backdrop-blur sm:px-6 print:hidden">
                <!-- Below `md` the column holding the way back is gone: it
                     lived only in the drawer, and a reader with no editor to
                     go to had to open it to leave. -->
                <AppBackLink
                    v-if="!publicTitle"
                    data-reader-back-phone
                    class="md:hidden"
                    :href="exitPath"
                    :label="exitLabel"
                />
                <!-- A real button, framed like the commands on the right of
                     the same bar (08/10/2026): it was the only bare icon. -->
                <AppButton
                    variant="secondary"
                    data-reader-nav-toggle
                    :label="t('notes.markdown.read.contents')"
                    icon-only
                    :aria-pressed="sidebarOpen"
                    v-on:click="toggleNav"
                >
                    <ListTree class="h-4 w-4 md:hidden" :stroke-width="2" />
                    <PanelLeftClose v-if="sidebarOpen" class="hidden h-4 w-4 md:block" :stroke-width="2" />
                    <PanelLeftOpen v-else class="hidden h-4 w-4 md:block" :stroke-width="2" />
                </AppButton>

                <nav
                    data-read-breadcrumb
                    class="flex min-w-0 flex-1 items-center gap-x-1 overflow-hidden text-xs text-muted"
                    :aria-label="t('notes.markdown.breadcrumb')"
                >
                    <a
                        :href="libraryPath"
                        class="hidden shrink-0 rounded px-1 py-0.5 no-underline transition-colors hover:bg-surface-2 hover:text-primary sm:inline"
                    >{{ publicTitle || t('notes.markdown.library.title') }}</a>
                    <template v-for="crumb in breadcrumb" :key="crumb.id">
                        <ChevronRight class="hidden h-3 w-3 shrink-0 sm:block" :stroke-width="2" />
                        <!-- A folder has no public page: on public reading,
                             it reads without leading anywhere. -->
                        <span
                            v-if="!folderShowPath"
                            data-read-crumb
                            class="hidden max-w-[10rem] truncate px-1 py-0.5 sm:inline"
                            :style="crumb.color ? { color: crumb.color } : null"
                        >{{ crumb.name || t('notes.markdown.folders.untitled') }}</span>
                        <a
                            v-else
                            :href="folderUrl(crumb.id)"
                            data-read-crumb
                            class="hidden max-w-[10rem] truncate rounded px-1 py-0.5 no-underline transition-colors hover:bg-surface-2 hover:text-primary sm:inline"
                            :style="crumb.color ? { color: crumb.color } : null"
                        >{{ crumb.name || t('notes.markdown.folders.untitled') }}</a>
                    </template>
                    <ChevronRight class="hidden h-3 w-3 shrink-0 sm:block" :stroke-width="2" />
                    <span class="min-w-0 truncate px-1 text-secondary" aria-current="page">{{ titleOf({ title: noteTitle }) }}</span>
                </nav>

                <span
                    v-if="minutes"
                    data-read-length
                    class="hidden shrink-0 items-center gap-1.5 text-xs text-muted lg:inline-flex"
                    :title="t('notes.markdown.outline.words', { count: words }, words)"
                >
                    <Clock class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.outline.minutes', { minutes }) }}
                </span>

                <!-- The bar's commands are real buttons, of the same size
                     (02/10/2026): the favourite was a bare star next to a
                     framed "Modifier", and the latter had no name left on a
                     phone. -->
                <AppButton
                    v-if="favoritePath"
                    variant="secondary"
                    data-read-favorite
                    :label="favoriteLabel"
                    icon-only
                    :aria-pressed="isFavorite"
                    v-on:click="toggleFavorite"
                >
                    <Star
                        class="h-4 w-4"
                        :class="isFavorite ? 'fill-current text-accent-400' : ''"
                        :stroke-width="2"
                    />
                </AppButton>

                <!-- Taking the note away rather than printing it (08/10/2026):
                     the Markdown file is what a reader keeps, and printing
                     stays in the editor's menu. The public reading has no
                     account to export with, so it keeps the printer. -->
                <AppActionSheet v-if="exportPath" :actions="exportActions" :label="titleOf({ title: noteTitle })">
                    <template #trigger="{ open }">
                        <AppButton
                            variant="secondary"
                            data-read-export
                            :label="t('notes.markdown.export.one')"
                            icon-only
                            v-on:click="open"
                        >
                            <Download class="h-4 w-4" :stroke-width="2" />
                        </AppButton>
                    </template>
                </AppActionSheet>
                <AppButton
                    v-else
                    variant="secondary"
                    data-read-print
                    :label="t('notes.markdown.print.action')"
                    icon-only
                    v-on:click="print"
                >
                    <Printer class="h-4 w-4" :stroke-width="2" />
                </AppButton>

                <!-- An icon like the two beside it (08/10/2026). -->
                <AppButton
                    v-if="canEdit && backPath"
                    variant="secondary"
                    data-read-edit
                    :href="backPath"
                    :label="t('notes.markdown.read.edit')"
                    :title="editTitle"
                    icon-only
                >
                    <Pencil class="h-4 w-4" :stroke-width="2" />
                </AppButton>
            </header>

            <!-- The text, and on a wide screen its outline to the right
                 (08/10/2026), held in the reading column's width. -->
            <div class="mx-auto flex w-full max-w-3xl gap-8 xl:max-w-[67rem] print:max-w-none">
                <div ref="body" class="flex min-w-0 max-w-3xl flex-1 flex-col gap-4 px-3 py-4 sm:px-6 sm:py-8 print:max-w-none print:p-0">
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

                    <!-- Turn the page, as at the end of a chapter. -->
                    <nav
                        v-if="previous || next"
                        data-read-pager
                        class="grid grid-cols-1 gap-2 sm:grid-cols-2 print:hidden"
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

                <aside class="sticky top-16 hidden w-56 shrink-0 self-start py-8 pr-3 xl:block print:hidden">
                    <NoteReaderOutline :root="body" />
                </aside>
            </div>
        </main>
    </div>
</template>
