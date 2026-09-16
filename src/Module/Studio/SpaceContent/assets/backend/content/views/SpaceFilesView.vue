<script setup>
/**
 * Everything exchanged on a space, newest first.
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
 * named on every row instead, and clicking the row opens it.
 *
 * It draws from the payload the other views already hold, so opening it costs
 * no request and it cannot disagree with the thumbnails on the board.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { FileText, UserRound } from "lucide-vue-next";

const props = defineProps({
    attachments: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
});

const emit = defineEmits(["open-item"]);

const { t, d, n } = useI18n();

const itemsById = computed(
    () => new Map(props.items.map((item) => [item.id, item])),
);

/**
 * Every file of the space, flattened, newest first.
 *
 * The card id is carried on each row rather than looked up later: the payload
 * is keyed by card, so it is free here and it is what the click needs.
 */
const files = computed(() =>
    Object.entries(props.attachments)
        .flatMap(([itemId, list]) =>
            (list ?? []).map((file) => ({ ...file, itemId: Number(itemId) })),
        )
        .sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)),
);

/**
 * Opens the card the file is on.
 *
 * The item object rather than its id, because that is what the three other
 * views hand over and what the form reads. Passing the id would set the form's
 * subject to a number and blank every field, silently.
 */
function open(itemId) {
    const item = itemsById.value.get(itemId);

    if (item) emit("open-item", item);
}

/** Bytes as the row shows them. Absent size is a file filed before the column existed. */
function weight(file) {
    if (!file.size) return null;

    return `${n(Math.max(1, Math.round(file.size / 1024)))} ko`;
}
</script>

<template>
    <AppNoData
        v-if="files.length === 0"
        :title="t('backend.studio.space_content.files_empty')"
        :description="t('backend.studio.space_content.files_empty_hint')"
    />

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
                        {{ itemsById.get(file.itemId)?.title ?? t("backend.studio.space_content.files_unknown_card") }}
                    </button>

                    <span aria-hidden="true">·</span>
                    <span class="inline-flex items-center gap-1">
                        <UserRound v-if="file.fromClient" class="h-3 w-3" :stroke-width="2" />
                        {{ file.author }}
                    </span>

                    <span aria-hidden="true">·</span>
                    <span>{{ d(new Date(file.createdAt), "short") }}</span>

                    <template v-if="weight(file)">
                        <span aria-hidden="true">·</span>
                        <span>{{ weight(file) }}</span>
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
</template>
