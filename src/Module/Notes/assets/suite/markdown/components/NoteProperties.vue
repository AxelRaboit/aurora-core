<script setup>
/**
 * A note's properties, under its title (09/10/2026), as Obsidian and Notion
 * show them: status, date, person, link, number… one line each, edited in
 * place. A folder's table view sorts and filters by them.
 *
 * The list is the note's; every change hands a new one up, so the form sees
 * the change and saves it like the text.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Calendar, CheckSquare, CircleDot, ExternalLink, Hash, Link2, Plus, Type, User, X } from "lucide-vue-next";
import AppSelect from "@shared/components/form/select/AppSelect.vue";
import AppDatePicker from "@shared/components/form/picker/AppDatePicker.vue";
import AppCheckbox from "@shared/components/form/toggle/AppCheckbox.vue";

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    /** list<{id, name, self?}>, for the « person » type. */
    people: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

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
                <AppDatePicker
                    v-if="'date' === property.type"
                    :model-value="property.value ?? ''"
                    :disabled="readonly"
                    v-on:update:model-value="change(index, { value: $event || null })"
                />
                <AppCheckbox
                    v-else-if="'checkbox' === property.type"
                    :model-value="true === property.value"
                    :disabled="readonly"
                    :aria-label="property.key"
                    v-on:update:model-value="change(index, { value: $event })"
                />
                <AppSelect
                    v-else-if="'person' === property.type"
                    :model-value="property.value"
                    :options="personOptions"
                    :disabled="readonly"
                    :placeholder="t('notes.markdown.properties.pick_person')"
                    v-on:update:model-value="change(index, { value: $event })"
                />
                <div v-else class="flex items-center gap-1">
                    <input
                        :value="property.value ?? ''"
                        data-note-property-value
                        :type="'number' === property.type ? 'number' : 'text'"
                        class="min-w-0 flex-1 rounded border-0 bg-transparent px-1 py-0.5 text-sm text-primary outline-none hover:bg-surface-2 focus:bg-surface-2"
                        :class="'status' === property.type && property.value ? 'max-w-max rounded-full bg-accent-500/15 px-2 text-accent-600 dark:text-accent-300' : ''"
                        :readonly="readonly"
                        :placeholder="t('notes.markdown.properties.empty')"
                        :aria-label="property.key"
                        v-on:change="change(index, { value: '' === $event.target.value ? null : $event.target.value })"
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

            <template v-if="!readonly">
                <div class="w-32 shrink-0 opacity-0 transition-opacity group-hover/property:opacity-100 focus-within:opacity-100">
                    <AppSelect
                        :model-value="property.type"
                        :options="typeOptions"
                        :aria-label="t('notes.markdown.properties.type')"
                        v-on:update:model-value="changeType(index, $event)"
                    />
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded p-1 text-muted opacity-0 transition-opacity hover:text-primary group-hover/property:opacity-100 focus:opacity-100"
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
    </div>
</template>
