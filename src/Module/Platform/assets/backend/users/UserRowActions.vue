<script setup>
/**
 * The actions a user row offers, handed to the shared sheet.
 *
 * Presentation only, and barely that: which actions this operator may take on
 * this user is a set of rules and lives in `useUserActions`; the trigger, the
 * modal and the rows are `AppRowActions`, shared with every other list.
 *
 * What is left here is the translation between the two - an action carrying an
 * `emitName` becomes an `onSelect` that emits it, because the page above owns
 * what actually happens.
 */
import { computed } from "vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useUserActions } from "./composables/useUserActions.js";

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

const rules = useUserActions(props);

const actions = computed(() =>
    rules.value.map((action) => ({
        ...action,
        onSelect: action.emitName
            ? () => emit(action.emitName, props.user)
            : undefined,
    })),
);
</script>

<template>
    <AppRowActions :actions="actions" :label="user.name" />
</template>
