<script setup>
/**
 * Everything that can be done to one user, behind a single button.
 *
 * The row used to carry up to eight icon buttons side by side. Eight glyphs
 * say what they do only to whoever already knows, they crowd the row on a
 * narrow screen, and the destructive one sits a few pixels from the harmless
 * ones. One button, and a sheet that names each action and says what it is
 * for, costs a click and removes all three problems.
 *
 * Presentation only: which actions this operator may take on this user is a set
 * of rules, and it lives in `useUserActions`.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { MoreHorizontal } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppActionButton from "@/shared/components/action/AppActionButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import { useUserActions } from "./composables/useUserActions.js";

const { t } = useI18n();

const props = defineProps({
    user: { type: Object, required: true },
    isDev: { type: Boolean, default: false },
    canAct: { type: Boolean, required: true },
    canEdit: { type: Boolean, default: false },
    hasPrivileges: { type: Boolean, default: false },
    canManageDisabledModules: { type: Boolean, default: false },
    impersonatePath: { type: String, default: "" },
    impersonateFrontPath: { type: String, default: "" },
});

const emit = defineEmits(["view", "resend", "edit", "privileges", "modules", "toggle-disabled", "delete"]);

const open = ref(false);
const actions = useUserActions(props);

// The sheet closes on the way out, not on the way back: the page above opens a
// modal of its own for most of these, and two stacked overlays is one too many.
function run(action) {
    open.value = false;

    if (action.emitName) {
        emit(action.emitName, props.user);
    }
}
</script>

<template>
    <div class="flex items-center justify-end">
        <AppIconButton
            :title="t('backend.users.row_actions.open', { name: user.name })"
            v-on:click="open = true"
        >
            <MoreHorizontal class="w-4 h-4" :stroke-width="2" />
        </AppIconButton>

        <!-- No footer, and the modal convention's "always put actions in the
             footer" does not reach this case: it is written for form modals,
             where the footer holds Cancel and Save beside a body being filled
             in. Here the body *is* the actions — a footer would hold a ninth
             button whose only job is to undo opening the sheet, which ESC and
             the overlay already do. -->
        <AppModal
            :show="open"
            max-width="sm"
            :title="t('backend.users.row_actions.title', { name: user.name })"
            v-on:close="open = false"
        >
            <div class="space-y-0.5">
                <AppActionButton
                    v-for="action in actions"
                    :key="action.key"
                    :title="action.title"
                    :description="action.description"
                    :color="action.color"
                    :href="action.href"
                    v-on:click="run(action)"
                >
                    <template #icon>
                        <component :is="action.icon" class="w-4 h-4" :stroke-width="2" />
                    </template>
                </AppActionButton>
            </div>
        </AppModal>
    </div>
</template>
