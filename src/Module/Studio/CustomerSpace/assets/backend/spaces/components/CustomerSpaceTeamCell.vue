<script setup>
/**
 * The team of a space, in the width of a table cell.
 *
 * Faces rather than a number, because that is what the cell is scanned for: a
 * reader goes down the column looking for the spaces that are theirs, and an
 * initial is recognised where "2 personnes" has to be read. The count was the
 * first attempt and it wrapped in the cell, which is the shape of a label doing
 * a picture's job.
 *
 * Three at most, then a "+n". Past three the circles stop being separable at
 * this size, and the whole point is that they are read at a glance; the names
 * live one click away, where they have room.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppAvatar from "@/shared/components/display/AppAvatar.vue";

const props = defineProps({
    members: { type: Array, default: () => [] },
});

defineEmits(["open"]);

const { t } = useI18n();

const MAX_FACES = 3;

const shown = computed(() => props.members.slice(0, MAX_FACES));

const overflow = computed(() => Math.max(0, props.members.length - MAX_FACES));

/** What a screen reader hears, and what the tooltip says. */
const label = computed(() =>
    t(
        "backend.studio.spaces.team_button",
        { count: props.members.length },
        props.members.length,
    ),
);
</script>

<template>
    <button
        v-if="members.length"
        type="button"
        class="group/team flex items-center rounded-full py-0.5 pr-1.5 transition-colors hover:bg-surface-2"
        :title="label"
        :aria-label="label"
        v-on:click="$emit('open')"
    >
        <span class="flex -space-x-2">
            <AppAvatar
                v-for="member in shown"
                :key="member.userId"
                :name="member.name"
                :email="member.email"
                size="sm"
                class="ring-2 ring-surface transition-transform group-hover/team:ring-surface-2"
            />
            <span
                v-if="overflow"
                class="flex h-7 w-7 items-center justify-center rounded-full bg-surface-2 text-xs font-medium tabular-nums text-secondary ring-2 ring-surface"
            >
                +{{ overflow }}
            </span>
        </span>
    </button>

    <span v-else class="text-sm text-muted">
        {{ t("backend.studio.spaces.no_members") }}
    </span>
</template>
