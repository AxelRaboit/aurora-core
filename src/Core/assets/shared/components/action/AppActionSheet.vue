<script setup>
/**
 * The list of things that can be done, and the modal it is read in.
 *
 * Two places need it: a table row, where the trigger is three dots in the last
 * column, and the top of a page, where it is a button that says "Actions". What
 * they share is everything else - the modal, the order the rows are read in,
 * closing before the action runs - and sharing it is the whole point. Twelve
 * lists each growing their own sheet is twelve places for it to drift.
 *
 * **The trigger is the caller's, the sheet is this component's.** The `trigger`
 * slot is handed an `open` function and decides what is pressed; see
 * {@see AppRowActions} and {@see AppPageActions}, which are the two façades and
 * are meant to stay the only two.
 *
 * **The list is the caller's too.** Which actions are offered depends on
 * permissions and on the record's own state, and that belongs beside the list
 * that knows them - usually in a composable, never in a template.
 *
 * An action is `{ key, title, description?, color?, icon?, href?, onSelect?,
 * disabled?, loading? }`. `href` makes it a link - some actions are navigations
 * and should stay openable in a new tab; `onSelect` is called for the rest.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import AppActionButton from "./AppActionButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";

defineProps({
    /** What is offered, in the order it is meant to be read. */
    actions: { type: Array, required: true },
    /** Names what is being acted on, in the sheet's title. */
    label: { type: String, default: "" },
});

const { t } = useI18n();

const open = ref(false);

function show() {
    open.value = true;
}

// Closed on the way out rather than on the way back: most of these open a modal
// of the caller's own, and two stacked overlays is one too many.
function run(action) {
    if (action.disabled || action.loading) {
        return;
    }

    open.value = false;
    action.onSelect?.();
}
</script>

<template>
    <slot name="trigger" :open="show" />

    <!-- No footer, and the modal convention's "actions always go in the
         footer" does not reach this case: it is written for form modals,
         where the footer carries Cancel and Save beside a body being filled
         in. Here the body *is* the actions, and a footer would hold one
         more button undoing the opening - which ESC and the overlay do. -->
    <AppModal
        :show="open"
        max-width="sm"
        :title="label ? t('shared.actions.title', { name: label }) : t('shared.actions.plain_title')"
        v-on:close="open = false"
    >
        <div class="space-y-0.5">
            <AppActionButton
                v-for="action in actions"
                :key="action.key"
                :title="action.title"
                :description="action.description ?? ''"
                :color="action.color ?? 'default'"
                :href="action.href"
                :disabled="action.disabled ?? false"
                :loading="action.loading ?? false"
                v-on:click="run(action)"
            >
                <template v-if="action.icon" #icon>
                    <component :is="action.icon" class="w-4 h-4" :stroke-width="2" />
                </template>
            </AppActionButton>
        </div>
    </AppModal>
</template>
