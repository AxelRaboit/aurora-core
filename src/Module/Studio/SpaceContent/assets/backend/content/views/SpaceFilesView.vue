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
 */
import { toRef } from "vue";
import { useI18n } from "vue-i18n";
import { useSpaceFiles } from "../composables/useSpaceFiles.js";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppImage from "@/shared/components/display/AppImage.vue";
import { FileText, LayoutGrid, List, UserRound } from "lucide-vue-next";

const props = defineProps({
    attachments: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
});

const emit = defineEmits(["open-item"]);

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
</script>

<template>
    <div ref="container">
        <div v-if="files.length > 0" class="mb-3 flex justify-end">
            <div class="flex rounded-lg border border-line/60 p-0.5">
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

        <AppNoData
            v-if="files.length === 0"
            :title="t('backend.studio.space_content.files_empty')"
            :description="t('backend.studio.space_content.files_empty_hint')"
        />

        <div
            v-else-if="viewMode === 'grid'"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
        >
            <article
                v-for="file in files"
                :key="file.id"
                class="overflow-hidden rounded-lg border border-line/60 bg-surface transition-colors hover:border-accent-400"
            >
                <a
                    :href="file.url"
                    class="relative flex aspect-square items-center justify-center overflow-hidden bg-surface-2"
                >
                    <AppImage
                        v-if="file.preview"
                        :src="file.preview"
                        :alt="file.title"
                        object-fit="cover"
                    />
                    <FileText v-else class="h-10 w-10 text-muted" :stroke-width="1.5" />
                </a>

                <div class="px-2.5 py-2">
                    <p class="truncate text-xs text-primary" :title="file.title">
                        {{ file.title }}
                    </p>

                    <button
                        type="button"
                        class="mt-0.5 block max-w-full truncate text-xs text-muted underline decoration-dotted underline-offset-2 transition-colors hover:text-primary"
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
                v-for="file in files"
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
                        <button
                            type="button"
                            class="truncate underline decoration-dotted underline-offset-2 transition-colors hover:text-primary"
                            v-on:click="open(file.itemId)"
                        >
                            {{ titleOf(file.itemId) }}
                        </button>

                        <span aria-hidden="true">·</span>
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

                <a
                    :href="file.url"
                    class="shrink-0 rounded-md border border-line/60 px-2.5 py-1 text-xs text-primary transition-colors hover:bg-surface-2"
                >
                    {{ t("backend.studio.space_content.files_open") }}
                </a>
            </li>
        </ul>
    </div>
</template>
