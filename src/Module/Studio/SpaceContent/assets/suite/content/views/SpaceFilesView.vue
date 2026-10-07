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
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
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
import { ExternalLink, Eye, EyeOff, FileText, FolderOpen, LayoutGrid, List, Trash2, Upload, UserRound, X } from "lucide-vue-next";

const props = defineProps({
    attachments: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    /** The files of the space itself, on no card. */
    spaceFiles: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    /**
     * Showing a space file to the client or hiding it: the right to share
     * the space, on top of the right to edit it.
     */
    canShowToClient: { type: Boolean, default: false },
    /** Whether the reader may browse the media library the picker lists. */
    canPick: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["open-item", "upload", "pick", "remove", "toggle-visibility"]);

const { t, d: formatDate } = useI18n();

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
 * The file shown in the panel, or null.
 *
 * The shared component already knows how to render an image, a PDF and the
 * rest; there is nothing specific to spaces in "what this file looks like".
 */
const previewed = ref(null);

/**
 * Two attachments, a single list drawn.
 *
 * "Sur les fiches" answers "this file arrived last week, but for which post";
 * "De l'espace" carries what illustrates nothing - the brand guidelines, the
 * logos, the brief, a signed PDF. Remembered from one visit to the next, like
 * the notes tabs: whoever files their guidelines apart does it on every
 * space.
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

/** The hidden file field: a button can be styled, an `input[type=file]` cannot. */
const fileInput = ref(null);

function chooseFile(event) {
    const file = event.target.files?.[0];

    if (file) emit("upload", file);

    // Reset: without it, uploading the same file twice emits nothing, since
    // the field's value has not changed.
    event.target.value = "";
}
</script>

<template>
    <div ref="container">
        <!-- The screen's how-to, next to what it explains; folded or
     unfolded, the choice holds for every guide. -->
        <AppGuide :title="t('suite.studio.space_files.guide.title')" storage-key="space-files" class="mb-[var(--aurora-page-margin)]">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.space_files.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <!-- Two attachments, two tabs, and the count on the label: it is
                 what makes the other one visible. -->
            <div
                class="flex items-center gap-0.5 rounded-lg border border-line bg-surface-2/40 p-0.5"
                role="group"
                :aria-label="t('suite.studio.space_files.label')"
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
                    {{ t(`suite.studio.space_files.tabs.${entry.key}`) }}
                    <span class="text-xs tabular-nums text-muted">{{ entry.count }}</span>
                </button>
            </div>

            <!-- **Both actions take the full line on a phone.** Squeezed next
                 to the view switcher, "Déposer un fichier" shrank to a hundred
                 and seventeen pixels and "Choisir dans la médiathèque" wrapped
                 onto two lines: the screen's actions looked like trimming on
                 the switcher. -->
            <div class="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:items-center">
                <!-- Upload and pick only apply to the space's files: on a
                     card, the card carries them, and that is where they are
                     added. -->
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
                        {{ t("suite.studio.space_files.upload") }}
                    </AppButton>
                    <AppButton
                        v-if="canPick"
                        class="w-full sm:w-auto"
                        variant="ghost"
                        size="sm"
                        :loading="loading"
                        v-on:click="emit('pick')"
                    >
                        <FolderOpen class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("suite.studio.space_files.pick") }}
                    </AppButton>
                </template>

                <div v-if="visible.length > 0" class="flex self-start rounded-lg border border-line p-0.5 sm:self-auto">
                    <AppIconButton
                        :title="t('suite.studio.space_content.files_as_cards')"
                        :class="storedViewMode === 'grid' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                        v-on:click="setViewMode('grid')"
                    >
                        <LayoutGrid class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        :title="t('suite.studio.space_content.files_as_rows')"
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
            :message="t(`suite.studio.space_files.empty_${tab}`)"
            :hint="t(`suite.studio.space_files.empty_${tab}_hint`)"
        />

        <!-- **One file per row on a phone.** Two columns on three hundred
             and seventy-five pixels gave thumbnails of a hundred and
             seventy-three: a picture you guess rather than recognise, and
             recognising is all this view is asked for. The one that remains
             takes the width, and the name below it stops being cut at the
             third word. Whoever wants to see many files at once has the list,
             right next to it. -->
        <div
            v-else-if="viewMode === 'grid'"
            class="grid grid-cols-1 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
        >
            <article
                v-for="file in visible"
                :key="file.id"
                class="aurora-card overflow-hidden transition-colors hover:border-accent-400"
            >
                <!-- A square of three hundred and sixty pixels would eat the
                     screen for a single thumbnail; four by three shows two and
                     a half, and the framing is decided by `object-fit: cover`
                     anyway. -->
                <button
                    type="button"
                    class="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden bg-surface-2 sm:aspect-square"
                    :title="t('suite.studio.space_content.files_open')"
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

                    <!-- `py-1.5 -my-1.5`: sixteen pixels high is the height of
                         a line of text, not of a target. The hit area grows to
                         twenty-eight without the card moving a pixel. -->
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
                    <!-- Said in words: the icon alone does not tell a colleague
                         that this file came from the client's page. -->
                    <p v-if="file.fromClient" class="mt-0.5 text-[0.68rem] text-accent-500">
                        {{ t("suite.studio.space_files.sent_by_client") }}
                    </p>

                    <!-- The state of a space file, and the action that changes
                         it for whoever may share the space. A file the client
                         sent stays visible: there is nothing to hide from
                         them in what they uploaded themselves. -->
                    <div v-if="'space' === tab" class="mt-1.5 flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1 text-[0.68rem]" :class="file.visibleToClient ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted'">
                            <component :is="file.visibleToClient ? Eye : EyeOff" class="h-3 w-3" :stroke-width="2" />
                            {{ t(file.visibleToClient ? "suite.studio.space_files.visible" : "suite.studio.space_files.hidden") }}
                        </span>
                        <AppIconButton
                            v-if="canShowToClient && !file.fromClient"
                            :title="t(file.visibleToClient ? 'suite.studio.space_files.hide' : 'suite.studio.space_files.show')"
                            v-on:click="emit('toggle-visibility', file)"
                        >
                            <component :is="file.visibleToClient ? EyeOff : Eye" class="h-3.5 w-3.5" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </div>
            </article>
        </div>

        <ul v-else class="divide-y divide-line/40 rounded-lg border border-line">
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

                        <template v-if="file.fromClient">
                            <span aria-hidden="true">·</span>
                            <span class="text-accent-500">{{ t("suite.studio.space_files.sent_by_client") }}</span>
                        </template>

                        <span aria-hidden="true">·</span>
                        <span>{{ formatDate(new Date(file.createdAt), "short") }}</span>

                        <template v-if="weightOf(file)">
                            <span aria-hidden="true">·</span>
                            <span>{{ weightOf(file) }}</span>
                        </template>

                        <template v-if="'space' === tab">
                            <span aria-hidden="true">·</span>
                            <span class="inline-flex items-center gap-1" :class="file.visibleToClient ? 'text-emerald-600 dark:text-emerald-400' : ''">
                                <component :is="file.visibleToClient ? Eye : EyeOff" class="h-3 w-3" :stroke-width="2" />
                                {{ t(file.visibleToClient ? "suite.studio.space_files.visible" : "suite.studio.space_files.hidden") }}
                            </span>
                        </template>
                    </p>
                </div>

                <button
                    type="button"
                    class="shrink-0 rounded-md border border-line px-2.5 py-1.5 text-xs text-primary transition-colors hover:bg-surface-2"
                    v-on:click="previewed = file"
                >
                    {{ t("suite.studio.space_content.files_open") }}
                </button>

                <!-- Show to or hide from the client: for whoever may share
                     the space, and never on a file the client sent
                     themselves. Spelled out on a phone, where no hover
                     explains an icon. -->
                <AppButton
                    v-if="'space' === tab && canShowToClient && !file.fromClient"
                    size="sm"
                    variant="ghost"
                    class="shrink-0"
                    :title="t(file.visibleToClient ? 'suite.studio.space_files.hide' : 'suite.studio.space_files.show')"
                    v-on:click="emit('toggle-visibility', file)"
                >
                    <component :is="file.visibleToClient ? EyeOff : Eye" class="h-3.5 w-3.5" :stroke-width="2" />
                    <span class="sm:sr-only">
                        {{ t(file.visibleToClient ? "suite.studio.space_files.hide" : "suite.studio.space_files.show") }}
                    </span>
                </AppButton>

                <!-- Remove is offered only on the space's files: on a card,
                     the file is removed from the card, where you see what you
                     undo. -->
                <AppIconButton
                    v-if="'space' === tab && editable"
                    :title="t('suite.studio.space_files.remove')"
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
                    <span>{{ previewed ? formatDate(new Date(previewed.createdAt), "short") : "" }}</span>

                    <template v-if="previewed && weightOf(previewed)">
                        <span aria-hidden="true">·</span>
                        <span>{{ weightOf(previewed) }}</span>
                    </template>
                </p>
            </div>

            <template #footer>
                <AppModalFooter>
                    <!-- The real address stays on offer: the panel is for
                         looking, the link is for downloading or keeping the
                         file open alongside. -->
                    <AppButton variant="ghost" size="md" :href="previewed?.url" target="_blank">
                        <ExternalLink class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("suite.studio.space_content.files_open_in_tab") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" v-on:click="previewed = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
