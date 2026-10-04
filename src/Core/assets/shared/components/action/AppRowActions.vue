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
 *
 * **Everywhere, on every screen, cards included** (Axel's call, 04/10/2026):
 * a list's actions always sit behind this button, on a phone card as in a
 * table, except when there is only one. A single action is not a menu: it is
 * shown as itself, a small button with its icon and its word, one tap away.
 * No action at all, and nothing is drawn.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { MoreHorizontal } from "lucide-vue-next";
import AppButton from "./AppButton.vue";
import AppIconButton from "./AppIconButton.vue";
import AppActionSheet from "./AppActionSheet.vue";

const props = defineProps({
    /** What this row offers, in the order they are meant to be read. */
    actions: { type: Array, required: true },
    /** Names the row in the trigger's label and the sheet's title. */
    label: { type: String, default: "" },
});

const { t } = useI18n();

const sheet = ref(null);

/** The one action, shown as itself; null when there are several, or none. */
const single = computed(() => (1 === props.actions.length ? props.actions[0] : null));

function runSingle() {
    const action = single.value;
    if (!action || action.disabled || action.loading) return;
    action.onSelect?.();
}

// Opens the same sheet as the button, for a row that also answers a
// right-click: one list of actions, two ways to reach it.
defineExpose({ open: () => sheet.value?.show() });
</script>

<template>
    <div v-if="actions.length" class="flex items-center justify-end">
        <AppActionSheet ref="sheet" :actions="actions" :label="label">
            <template #trigger="{ open }">
                <!-- One action: the button itself, its icon and its word. -->
                <AppButton
                    v-if="single"
                    :variant="'rose' === single.color ? 'danger-outline' : 'ghost'"
                    size="sm"
                    :href="single.href ?? null"
                    :disabled="single.disabled"
                    :loading="single.loading"
                    :title="single.description || single.title"
                    v-on:click="single.href ? undefined : runSingle()"
                >
                    <component :is="single.icon" v-if="single.icon && !single.loading" class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ single.title }}
                </AppButton>
                <AppIconButton
                    v-else
                    :title="t('shared.actions.open', { name: label })"
                    v-on:click="open"
                >
                    <MoreHorizontal class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
            </template>
        </AppActionSheet>
    </div>
</template>
