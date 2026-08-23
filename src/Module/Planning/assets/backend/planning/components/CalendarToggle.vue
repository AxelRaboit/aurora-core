<script setup>
/**
 * One calendar, as something you can fold away, count and edit.
 *
 * Extracted because the same row is drawn in two places: the sheet on a phone and
 * the picker's panel on a wide screen. Same shape in both, so there is no variant
 * to configure - what it holds is the behaviour, which is where the churn is: the
 * swatch that becomes an outline when hidden, the count that gives way to the
 * pencil on hover, and `aria-pressed`.
 */
import { useI18n } from "vue-i18n";
import { Pencil } from "lucide-vue-next";

const props = defineProps({
    calendar: { type: Object, required: true },
    hidden: { type: Boolean, default: false },
    /** Items in the range on screen. Falsy means draw no number. */
    count: { type: Number, default: 0 },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(["toggle", "edit"]);

const { t } = useI18n();

/**
 * The swatch: filled with the calendar's colour, or an outline when folded away.
 *
 * An outline and not a paler fill, because "hidden" has to survive the row
 * already being at 40% opacity - two shades of faint are not a state anybody can
 * read.
 */
function swatch() {
    return props.hidden
        ? { border: "1.5px solid var(--th-muted)" }
        : { backgroundColor: `var(--chart-cat-${props.calendar.colourSlot})` };
}
</script>

<template>
    <div
        class="group flex items-center gap-2 text-sm transition-opacity"
        :class="hidden ? 'opacity-40' : ''"
    >
        <!-- A row holding two actions, not a button: the name toggles and the
             pencil edits, and a button inside a button is invalid HTML whose inner
             one never fires. -->
        <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-2 text-left cursor-pointer"
            :aria-pressed="!hidden"
            v-on:click="emit('toggle', calendar.id)"
        >
            <span class="h-3 w-3 shrink-0 rounded" :style="swatch()" />
            <span class="truncate text-secondary">{{ calendar.name }}</span>
        </button>

        <!-- Events in the range on screen, not a lifetime total. Titled, because a
             bare number next to a calendar reads as "everything in it" and this one
             moves as you page.

             Absent rather than "0" when there is nothing: a zero in figures reads
             as a fact about the calendar, and this one is a fact about the month.
             No number says "nothing here right now", which is what it means. -->
        <span
            v-if="count"
            class="shrink-0 text-2xs tabular-nums text-muted"
            :class="canManage ? 'group-hover:hidden' : ''"
            :title="t('backend.plannings.events_in_range')"
        >
            {{ count }}
        </span>
        <!-- Takes the count's place on hover rather than sitting beside it, so the
             row does not change width and the names stay lined up. -->
        <button
            v-if="canManage"
            type="button"
            class="hidden shrink-0 cursor-pointer text-muted hover:text-primary group-hover:block"
            :title="t('backend.plannings.edit_calendar')"
            v-on:click="emit('edit', calendar)"
        >
            <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
        </button>
    </div>
</template>
