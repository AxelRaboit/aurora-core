<script setup>
/**
 * The team of one space, read in full.
 *
 * The list used to be three names and a "+2" in a table cell, which answers
 * "is anybody on this" and nothing else: the fourth person was invisible, the
 * roles were nowhere, and the column grew with the longest team rather than
 * with what a reader needs at a glance. The cell is now a count that opens
 * this, so the table keeps one figure and the detail has room to be complete.
 *
 * Read-only on purpose. Changing who is on a space is an edit of the space, and
 * it belongs to the form that already owns every other field of it - a second
 * place to change the same rows is the shape two screens disagree in.
 */
import { useI18n } from "vue-i18n";
import { Users, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";

defineProps({
    space: { type: Object, default: null },
    roles: { type: Array, default: () => [] },
});

const emit = defineEmits(["close"]);

const { t } = useI18n();

function roleLabel(roles, value) {
    const role = roles.find((candidate) => candidate.value === value);

    return role ? t(role.labelKey) : value;
}
</script>

<template>
    <AppModal
        :show="!!space"
        max-width="md"
        :title="t('backend.studio.spaces.team_modal_title', { name: space?.name ?? '' })"
        :icon="Users"
        v-on:close="emit('close')"
    >
        <AppNoData
            v-if="!space?.members?.length"
            :message="t('backend.studio.spaces.team_modal_empty')"
        />

        <ul v-else class="divide-y divide-line/40/40">
            <li
                v-for="member in space.members"
                :key="member.userId"
                class="flex items-center justify-between gap-3 py-2.5"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-primary">
                        {{ member.name }}
                    </p>
                    <p class="truncate text-xs text-muted">{{ member.email }}</p>
                </div>
                <span
                    class="shrink-0 rounded-full bg-surface-2 px-2 py-0.5 text-xs text-secondary"
                >
                    {{ roleLabel(roles, member.role) }}
                </span>
            </li>
        </ul>

        <p class="mt-3 text-xs text-muted">
            {{ t("backend.studio.spaces.team_modal_hint") }}
        </p>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.close") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
