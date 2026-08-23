<script setup>
/**
 * Everything that can be done to one row, behind a single button.
 *
 * A list row used to carry its actions as a strip of icon buttons. Glyphs say
 * what they do only to whoever already knows them, they crowd the row on a
 * narrow screen, and the destructive one ends up a few pixels from the harmless
 * ones. One button and a named list costs a click and answers all three.
 *
 * **The list is the caller's, the sheet is this component's.** Which actions a
 * row offers depends on permissions and on the record's own state, and that
 * belongs beside the list that knows them - usually in a composable, never in a
 * template. What every list shares is the trigger, the modal, the ordering of
 * the rows and the closing behaviour, and sharing those is the whole point:
 * twelve lists each growing their own modal is twelve places for it to drift.
 *
 * An action is `{ key, title, description?, color?, icon?, href?, onSelect?,
 * disabled? }`. `href` makes it a link - some actions are navigations and
 * should stay openable in a new tab; `onSelect` is called for the rest.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { MoreHorizontal } from "lucide-vue-next";
import AppIconButton from "./AppIconButton.vue";
import AppActionButton from "./AppActionButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";

defineProps({
    /** What this row offers, in the order they are meant to be read. */
    actions: { type: Array, required: true },
    /** Names the row in the trigger's label and the sheet's title. */
    label: { type: String, default: "" },
});

const { t } = useI18n();

const open = ref(false);

// Closed on the way out rather than on the way back: most of these open a modal
// of the caller's own, and two stacked overlays is one too many.
function run(action) {
    if (action.disabled) {
        return;
    }

    open.value = false;
    action.onSelect?.();
}
</script>

<template>
    <div class="flex items-center justify-end">
        <AppIconButton
            :title="t('shared.actions.open', { name: label })"
            v-on:click="open = true"
        >
            <MoreHorizontal class="w-4 h-4" :stroke-width="2" />
        </AppIconButton>

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
                    v-on:click="run(action)"
                >
                    <template v-if="action.icon" #icon>
                        <component :is="action.icon" class="w-4 h-4" :stroke-width="2" />
                    </template>
                </AppActionButton>
            </div>
        </AppModal>
    </div>
</template>
