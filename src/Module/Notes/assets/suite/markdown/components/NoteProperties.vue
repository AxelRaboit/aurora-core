<script setup>
/**
 * A note's properties, under its title (09/10/2026), as Obsidian and Notion
 * show them: status, date, person, link, number… one line each, edited in
 * place. A folder's table view sorts and filters by them.
 *
 * The list is the note's; every change hands a new one up, so the form sees
 * the change and saves it like the text.
 */
import { computed, nextTick, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Calendar, CheckSquare, ChevronRight, CircleDot, ExternalLink, Hash, Link2, Plus, Type, User, X } from "lucide-vue-next";
import AppSelect from "@shared/components/form/select/AppSelect.vue";
import AppDatePicker from "@shared/components/form/picker/AppDatePicker.vue";
import AppCheckbox from "@shared/components/form/toggle/AppCheckbox.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    /** list<{id, name, self?}>, for the « person » type. */
    people: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();
const { formatDateShort } = useDateFormat();

/**
 * Folded to a single line (09/10/2026): a note with six properties pushed its
 * text half a screen down. Remembered by this browser for every note, the way
 * one reads them: someone who folds them wants them folded everywhere.
 */
const FOLD_KEY = "aurora.notes.propertiesFolded";
const folded = ref(false);
try {
    folded.value = "1" === window.localStorage.getItem(FOLD_KEY);
} catch {
    folded.value = false;
}

function toggleFold() {
    folded.value = !folded.value;
    try {
        window.localStorage.setItem(FOLD_KEY, folded.value ? "1" : "0");
    } catch {
        // Folded for this visit only.
    }
}

/** What the folded line shows: the values, as they read. */
const summary = computed(() =>
    props.modelValue
        .map((property) => {
            if ("checkbox" === property.type) return true === property.value ? `${property.key} ✓` : null;
            if (null === property.value || undefined === property.value || "" === property.value) return null;
            if ("date" === property.type) return formatDateShort(property.value, "");
            if ("person" === property.type) return props.people.find((person) => person.id === property.value)?.name ?? null;

            return String(property.value);
        })
        .filter(Boolean)
        .slice(0, 5),
);

/** The status shown as a pill, and as a field only while it is being written. */
const editingStatus = ref(null);
async function editStatus(index) {
    if (props.readonly) return;
    editingStatus.value = index;
    await nextTick();
    document.querySelector(`[data-note-property-status-field="${index}"]`)?.focus();
}

const TYPES = ["text", "status", "date", "person", "number", "checkbox", "url"];
const ICONS = { text: Type, status: CircleDot, date: Calendar, person: User, number: Hash, checkbox: CheckSquare, url: Link2 };

const typeOptions = computed(() => TYPES.map((type) => ({ value: type, label: t(`notes.markdown.properties.types.${type}`) })));
const personOptions = computed(() =>
    props.people.map((person) => ({ value: person.id, label: person.self ? `${person.name} (${t("notes.markdown.properties.me")})` : person.name })),
);

function change(index, patch) {
    const next = props.modelValue.map((property, position) => (position === index ? { ...property, ...patch } : { ...property }));
    emit("update:modelValue", next);
}

function changeType(index, type) {
    // A value of another type would mean nothing: the new type starts empty.
    change(index, { type, value: "checkbox" === type ? false : null });
}

function remove(index) {
    emit(
        "update:modelValue",
        props.modelValue.filter((_, position) => position !== index),
    );
}

function add() {
    const taken = new Set(props.modelValue.map((property) => property.key.toLowerCase()));
    let key = t("notes.markdown.properties.new");
    for (let number = 2; taken.has(key.toLowerCase()); number += 1) {
        key = `${t("notes.markdown.properties.new")} ${number}`;
    }
    emit("update:modelValue", [...props.modelValue.map((property) => ({ ...property })), { key, type: "text", value: null }]);
}

function isAddress(value) {
    return /^https?:\/\//i.test(String(value ?? ""));
}
</script>

<template>
    <div v-if="modelValue.length || !readonly" data-note-properties class="flex flex-col gap-1">
        <button
            v-if="modelValue.length"
            type="button"
            data-note-properties-toggle
            class="flex min-w-0 items-center gap-1.5 rounded px-1 py-0.5 text-left text-xs text-muted transition-colors hover:bg-surface-2 hover:text-primary"
            :aria-expanded="!folded"
            v-on:click="toggleFold"
        >
            <ChevronRight class="h-3.5 w-3.5 shrink-0 transition-transform" :class="folded ? '' : 'rotate-90'" :stroke-width="2" />
            <span class="shrink-0 font-medium">{{ t('notes.markdown.properties.title') }}</span>
            <span class="shrink-0">{{ modelValue.length }}</span>
            <span v-if="folded && summary.length" class="min-w-0 truncate text-secondary">· {{ summary.join(' · ') }}</span>
        </button>
        <template v-if="!folded || !modelValue.length">
            <div
                v-for="(property, index) in modelValue"
                :key="index"
                data-note-property
                class="group/property flex flex-wrap items-center gap-2 text-sm"
            >
                <div class="flex w-44 shrink-0 items-center gap-1.5 text-muted">
                    <component :is="ICONS[property.type] ?? Type" class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                    <input
                        :value="property.key"
                        data-note-property-key
                        class="min-w-0 flex-1 rounded border-0 bg-transparent px-1 py-0.5 text-sm text-muted outline-none hover:bg-surface-2 focus:bg-surface-2"
                        :readonly="readonly"
                        :aria-label="t('notes.markdown.properties.name')"
                        v-on:change="change(index, { key: $event.target.value.trim() || property.key })"
                    >
                </div>

                <div class="min-w-0 flex-1">
                    <!-- Plain like the other values: bordered, the date read as
                     the one field of the list to fill in. -->
                    <div v-if="'date' === property.type" class="note-property-date max-w-56">
                        <AppDatePicker
                            :model-value="property.value ?? ''"
                            :disabled="readonly"
                            v-on:update:model-value="change(index, { value: $event || null })"
                        />
                    </div>
                    <AppCheckbox
                        v-else-if="'checkbox' === property.type"
                        :model-value="true === property.value"
                        :disabled="readonly"
                        :aria-label="property.key"
                        v-on:update:model-value="change(index, { value: $event })"
                    />
                    <AppSelect
                        v-else-if="'person' === property.type"
                        class="note-property-person max-w-56"
                        :model-value="property.value"
                        :options="personOptions"
                        :disabled="readonly"
                        :placeholder="t('notes.markdown.properties.pick_person')"
                        v-on:update:model-value="change(index, { value: $event })"
                    />
                    <button
                        v-else-if="'status' === property.type && property.value && editingStatus !== index"
                        type="button"
                        data-note-property-status
                        class="rounded-full bg-accent-500/15 px-2 py-0.5 text-xs font-medium text-accent-600 dark:text-accent-300"
                        :class="readonly ? 'cursor-default' : 'hover:bg-accent-500/25'"
                        v-on:click="editStatus(index)"
                    >
                        {{ property.value }}
                    </button>
                    <div v-else class="flex items-center gap-1">
                        <input
                            :data-note-property-status-field="'status' === property.type ? index : null"
                            :value="property.value ?? ''"
                            data-note-property-value
                            :type="'number' === property.type ? 'number' : 'text'"
                            class="min-w-0 flex-1 rounded border-0 bg-transparent px-1 py-0.5 text-sm text-primary outline-none hover:bg-surface-2 focus:bg-surface-2"
                            :readonly="readonly"
                            :placeholder="t('notes.markdown.properties.empty')"
                            :aria-label="property.key"
                            v-on:change="change(index, { value: '' === $event.target.value ? null : $event.target.value })"
                            v-on:blur="editingStatus = null"
                        >
                        <a
                            v-if="'url' === property.type && isAddress(property.value)"
                            :href="property.value"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="shrink-0 text-muted hover:text-primary"
                            :title="property.value"
                        ><ExternalLink class="h-3.5 w-3.5" :stroke-width="2" /></a>
                    </div>
                </div>

                <!-- Always shown, and quiet (09/10/2026): revealed on hover only,
                     the type and the cross were found by nobody. -->
                <template v-if="!readonly">
                    <div class="note-property-type w-32 shrink-0">
                        <AppSelect
                            :model-value="property.type"
                            :options="typeOptions"
                            :aria-label="t('notes.markdown.properties.type')"
                            v-on:update:model-value="changeType(index, $event)"
                        />
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded p-1 text-muted/60 transition-colors hover:bg-surface-2 hover:text-primary"
                        :title="t('notes.markdown.properties.remove')"
                        :aria-label="t('notes.markdown.properties.remove')"
                        v-on:click="remove(index)"
                    >
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                    </button>
                </template>
            </div>

            <button
                v-if="!readonly"
                type="button"
                data-note-property-add
                class="inline-flex w-max items-center gap-1 rounded px-1 py-0.5 text-xs text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                v-on:click="add"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t('notes.markdown.properties.add') }}
            </button>
        </template>
    </div>
</template>

<style>
.note-property-person .multiselect__tags {
    padding-left: 0.25rem;
}
.note-property-person .multiselect__single {
    padding-left: 0;
    margin-left: 0;
    font-size: 0.875rem;
}
.note-property-type .multiselect__single {
    color: var(--th-muted);
    font-size: 0.75rem;
}

/* The date and the person, plain like the other values (09/10/2026): the
   picker and the selector draw a bordered field of their own, and among
   values written as plain text they read as the only thing left to fill in. */
.note-property-date {
    --dp-border-color: transparent;
    --dp-border-color-hover: transparent;
    --dp-background-color: transparent;
}
.note-property-date .dp__input,
.note-property-date .dp-custom-input {
    font-size: 0.875rem;
    border-color: transparent;
    background: transparent;
    padding-top: 0.125rem;
    padding-bottom: 0.125rem;
}
.note-property-date .dp__input:hover,
.note-property-date .dp-custom-input:hover {
    background: var(--th-surface-2);
}
.note-property-type .multiselect__tags,
.note-property-person .multiselect__tags {
    border-color: transparent;
    background: transparent;
    min-height: 0;
    padding-top: 0.125rem;
}
.note-property-type .multiselect__tags:hover,
.note-property-person .multiselect__tags:hover {
    background: var(--th-surface-2);
}
</style>
