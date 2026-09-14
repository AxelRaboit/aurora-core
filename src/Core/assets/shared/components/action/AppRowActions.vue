<script setup>
/**
 * Everything that can be done to one row, behind a single button.
 *
 * A list row used to carry its actions as a strip of icon buttons. Glyphs say
 * what they do only to whoever already knows them, they crowd the row on a
 * narrow screen, and the destructive one ends up a few pixels from the harmless
 * ones. One button and a named list costs a click and answers all three.
 *
 * The sheet itself lives in {@see AppActionSheet}; this is the row's trigger and
 * nothing more. Three dots are enough here because the column above them says
 * "Actions" - at the top of a page there is no such header, which is why
 * {@see AppPageActions} spells the word out.
 *
 * See {@see AppActionSheet} for the shape of an action.
 */
import { useI18n } from "vue-i18n";
import { MoreHorizontal } from "lucide-vue-next";
import AppIconButton from "./AppIconButton.vue";
import AppActionSheet from "./AppActionSheet.vue";

defineProps({
    /** What this row offers, in the order they are meant to be read. */
    actions: { type: Array, required: true },
    /** Names the row in the trigger's label and the sheet's title. */
    label: { type: String, default: "" },
});

const { t } = useI18n();
</script>

<template>
    <div class="flex items-center justify-end">
        <AppActionSheet :actions="actions" :label="label">
            <template #trigger="{ open }">
                <AppIconButton
                    :title="t('shared.actions.open', { name: label })"
                    v-on:click="open"
                >
                    <MoreHorizontal class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
            </template>
        </AppActionSheet>
    </div>
</template>
