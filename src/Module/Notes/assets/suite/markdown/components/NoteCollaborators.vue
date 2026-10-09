<script setup>
/**
 * Who else has this note open, as a stack of faces.
 *
 * The shape people know from shared documents, and the one the Studio team
 * cell already draws: overlapping circles, then a "+n". Each circle wears the
 * colour of that person's name tag on their caret, so a face in the header
 * and a caret in a paragraph are recognised as the same person without
 * reading a name. Names, and whether each one is writing or reading, are in
 * the tooltip of each face; the whole sentence is what a screen reader hears.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";
import { collaboratorColor } from "@notes/suite/markdown/composables/collaboratorColor.js";

const props = defineProps({
    /** `[{userId, name, editing}]`, as the live room reports it - this reader excluded. */
    people: { type: Array, default: () => [] },
    /** How the room is kept up to date - live or polled - added to every tooltip. */
    status: { type: String, default: "" },
});

const { t } = useI18n();

/** Past four the circles stop being separable at this size. */
const MAX_FACES = 4;

const shown = computed(() => props.people.slice(0, MAX_FACES));
const hidden = computed(() => props.people.slice(MAX_FACES));

/**
 * What a person is called here.
 *
 * A guest of a live link has no name on purpose - the server keeps neither
 * one nor the link's label in the room - so they are "Guest", in this
 * reader's language. Nobody else comes without a name.
 */
function nameOf(person) {
    return person.name || t("notes.markdown.live.guest");
}

const names = computed(() => props.people.map(nameOf));

const sentence = computed(() =>
    1 === names.value.length
        ? t("notes.markdown.live.here", { name: names.value[0] })
        : t("notes.markdown.live.here_many", { names: names.value.join(", ") }),
);

function withStatus(text) {
    return props.status ? `${text}\n${props.status}` : text;
}

function tooltipOf(person) {
    const activity = t(person.editing ? "notes.markdown.live.editing" : "notes.markdown.live.reading");

    return withStatus(`${nameOf(person)} · ${activity}`);
}

const hiddenTooltip = computed(() =>
    withStatus(hidden.value.map(nameOf).join(", ")),
);
</script>

<template>
    <div
        role="group"
        class="flex -space-x-2"
        :aria-label="props.status ? `${sentence}. ${props.status}` : sentence"
    >
        <!-- An opaque ring under each circle, as in the Studio team cell: the
             one on top hides the part of its neighbour it covers. -->
        <span
            v-for="person in shown"
            :key="person.userId"
            data-note-collaborator
            class="rounded-full bg-surface ring-2 ring-surface"
            :title="tooltipOf(person)"
        >
            <AppAvatar
                :name="nameOf(person)"
                :color="collaboratorColor(person.userId)"
                size="sm"
                class="block"
            />
        </span>
        <span
            v-if="hidden.length"
            class="flex h-7 w-7 items-center justify-center rounded-full bg-surface-2 text-xs font-medium tabular-nums text-secondary ring-2 ring-surface"
            :title="hiddenTooltip"
        >+{{ hidden.length }}</span>
    </div>
</template>
