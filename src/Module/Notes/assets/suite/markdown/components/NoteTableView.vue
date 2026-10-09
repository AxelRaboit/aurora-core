<script setup>
/**
 * A folder as a table (09/10/2026), the way Notion shows a database: one row
 * per note, one column per property found in the notes on screen, sorted by
 * a click on a heading and filtered by a word in any cell.
 *
 * The columns come from the notes themselves: there is no schema to keep,
 * and a property written in one note only gives a column with one value.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ArrowDown, ArrowUp, Check, ExternalLink, FileText, Folder } from "lucide-vue-next";
import AppSearchInput from "@shared/components/form/input/AppSearchInput.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { foldText } from "@notes/suite/markdown/composables/noteEmoji.js";

const props = defineProps({
    folders: { type: Array, default: () => [] },
    notes: { type: Array, default: () => [] },
    /** list<{id, name}>, to name a « person » property. */
    people: { type: Array, default: () => [] },
    noteUrlFor: { type: Function, required: true },
    noteLabel: { type: Function, required: true },
    folderLabel: { type: Function, required: true },
});

const emit = defineEmits(["open-note", "open-folder"]);

const { t } = useI18n();
const { formatDateShort } = useDateFormat();

const MAX_COLUMNS = 8;
const filter = ref("");
/** `{column, direction}`; `column` is `title`, `updated` or a property's name. */
const order = ref({ column: null, direction: 1 });

/** Every property name, in the order the notes first use them. */
const columns = computed(() => {
    const seen = new Map();
    for (const note of props.notes) {
        for (const property of note.properties ?? []) {
            const key = String(property.key ?? "");
            if ("" !== key && !seen.has(key.toLowerCase())) seen.set(key.toLowerCase(), { key, type: property.type });
        }
    }

    return [...seen.values()].slice(0, MAX_COLUMNS);
});

const headings = computed(() => [
    { key: "title", label: t("notes.markdown.library.table.title") },
    ...columns.value.map((column) => ({ key: column.key, label: column.key })),
    { key: "updated", label: t("notes.markdown.library.table.updated") },
]);

const peopleById = computed(() => new Map(props.people.map((person) => [String(person.id), person.name])));

function propertyOf(note, key) {
    return (note.properties ?? []).find((property) => String(property.key).toLowerCase() === key.toLowerCase()) ?? null;
}

/** What a cell says, as text: for the filter, the sort and the cell itself. */
function cellText(property) {
    if (null === property || null === property.value || undefined === property.value || "" === property.value) return "";
    if ("checkbox" === property.type) return true === property.value ? "✓" : "";
    if ("person" === property.type) return peopleById.value.get(String(property.value)) ?? "";
    if ("date" === property.type) return formatDateShort(property.value, "");

    return String(property.value);
}

/** What a cell sorts by: dates and numbers by value, the rest by text. */
function sortValue(note, column) {
    if ("title" === column) return foldText(props.noteLabel(note));
    if ("updated" === column) return note.updatedAt ?? "";
    const property = propertyOf(note, column);
    if (null === property || null === property.value || "" === property.value) return null;
    if ("number" === property.type) return Number(property.value);
    if ("date" === property.type) return String(property.value);
    if ("checkbox" === property.type) return true === property.value ? 1 : 0;

    return foldText(cellText(property));
}

const sortedNotes = computed(() => {
    const words = foldText(filter.value).split(/\s+/).filter(Boolean);
    const shown = props.notes.filter((note) => {
        if (0 === words.length) return true;
        const haystack = foldText([props.noteLabel(note), ...columns.value.map((column) => cellText(propertyOf(note, column.key)))].join(" "));

        return words.every((word) => haystack.includes(word));
    });

    const { column, direction } = order.value;
    if (null === column) return shown;

    // Empty cells last whatever the direction: they are not « smaller ».
    return [...shown].sort((left, right) => {
        const leftValue = sortValue(left, column);
        const rightValue = sortValue(right, column);
        if (null === leftValue && null === rightValue) return 0;
        if (null === leftValue) return 1;
        if (null === rightValue) return -1;
        if (leftValue < rightValue) return -direction;
        if (leftValue > rightValue) return direction;

        return 0;
    });
});

/** Each row with its cells, looked up once. */
const rows = computed(() =>
    sortedNotes.value.map((note) => ({ note, cells: columns.value.map((column) => propertyOf(note, column.key)) })),
);

/** A first click sorts up, a second down, a third gives the library's order back. */
function sortBy(column) {
    const current = order.value;
    if (current.column !== column) {
        order.value = { column, direction: 1 };
    } else if (1 === current.direction) {
        order.value = { column, direction: -1 };
    } else {
        order.value = { column: null, direction: 1 };
    }
}

function ariaSort(column) {
    if (order.value.column !== column) return "none";

    return 1 === order.value.direction ? "ascending" : "descending";
}

function isAddress(value) {
    return /^https?:\/\//i.test(String(value ?? ""));
}
</script>

<template>
    <div data-note-table class="flex flex-col gap-3">
        <AppSearchInput
            v-model="filter"
            class="max-w-sm"
            data-note-table-filter
            :placeholder="t('notes.markdown.library.table.filter')"
            :debounce="0"
        />

        <div class="overflow-x-auto rounded-lg border border-line">
            <table class="w-full text-sm">
                <thead class="bg-surface-2 text-left text-xs text-muted">
                    <tr>
                        <th v-for="column in headings" :key="column.key" class="whitespace-nowrap px-3 py-2 font-medium" :aria-sort="ariaSort(column.key)">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-primary"
                                :data-note-table-sort="column.key"
                                v-on:click="sortBy(column.key)"
                            >
                                {{ column.label }}
                                <ArrowUp v-if="order.column === column.key && 1 === order.direction" class="h-3 w-3" :stroke-width="2" />
                                <ArrowDown v-else-if="order.column === column.key" class="h-3 w-3" :stroke-width="2" />
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="folder in folders" :key="`table-folder-${folder.id}`" class="border-t border-line">
                        <td class="px-3 py-2" :colspan="columns.length + 2">
                            <button type="button" class="flex items-center gap-2 text-left" v-on:click="emit('open-folder', folder.id)">
                                <Folder class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                                <span class="truncate font-medium text-primary">{{ folderLabel(folder) }}</span>
                            </button>
                        </td>
                    </tr>
                    <tr v-for="{ note, cells } in rows" :key="`table-note-${note.id}`" data-note-table-row class="border-t border-line hover:bg-surface-2/60">
                        <td class="max-w-72 px-3 py-2">
                            <a :href="noteUrlFor(note.id)" class="flex items-center gap-2" v-on:click.prevent="emit('open-note', note.id)">
                                <span v-if="note.icon" class="inline-flex w-4 shrink-0 justify-center leading-none">{{ note.icon }}</span>
                                <FileText v-else class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                                <span class="truncate font-medium text-primary">{{ noteLabel(note) }}</span>
                            </a>
                        </td>
                        <td v-for="(property, position) in cells" :key="columns[position].key" class="whitespace-nowrap px-3 py-2 text-secondary">
                            <span
                                v-if="property && 'status' === property.type && cellText(property)"
                                class="rounded-full bg-accent-500/15 px-2 py-0.5 text-xs text-accent-600 dark:text-accent-300"
                            >{{ cellText(property) }}</span>
                            <Check v-else-if="property && 'checkbox' === property.type && true === property.value" class="h-4 w-4 text-accent-500" :stroke-width="2" />
                            <a
                                v-else-if="property && 'url' === property.type && isAddress(property.value)"
                                :href="property.value"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex max-w-48 items-center gap-1 truncate hover:text-primary"
                            >
                                <span class="truncate">{{ property.value }}</span>
                                <ExternalLink class="h-3 w-3 shrink-0" :stroke-width="2" />
                            </a>
                            <span v-else class="block max-w-56 truncate">{{ cellText(property) }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-muted">{{ formatDateShort(note.updatedAt, "") }}</td>
                    </tr>
                    <tr v-if="0 === rows.length && 0 === folders.length">
                        <td :colspan="columns.length + 2" class="px-3 py-6 text-center text-muted">{{ t('notes.markdown.library.table.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
