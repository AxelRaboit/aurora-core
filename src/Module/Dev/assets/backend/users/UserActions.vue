<script setup>
/**
 * The actions a developer row offers, handed to the shared sheet.
 *
 * Presentation only: what may be done to whom lives in `useDevUserActions`,
 * where the rule that matters is visible - nothing that could lock the last
 * developer out is offered on your own account.
 */
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useDevUserActions } from "./composables/useDevUserActions.js";

const props = defineProps({
    user: { type: Object, required: true },
    impersonatePath: { type: String, required: true },
});

const emit = defineEmits(["edit", "toggle-role", "delete"]);

const actionsFor = useDevUserActions({
    impersonatePath: props.impersonatePath,
    onEdit: (user) => emit("edit", user),
    onToggleRole: (user) => emit("toggle-role", user),
    onDelete: (user) => emit("delete", user),
});
</script>

<template>
    <AppRowActions :actions="actionsFor(user)" :label="user.name" />
</template>
