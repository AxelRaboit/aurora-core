<script setup>
/**
 * Picking someone: to talk to them privately, or to add them to a channel.
 *
 * **A modal rather than a list unfolding in the rail.** The rail is two hundred
 * pixels wide and is for navigating; growing a second menu in it moves what one
 * was looking at and gives two lists that look alike. A modal asks the question
 * in the middle of the screen, with room for the names, and hands control back
 * in the same place.
 *
 * One component for both uses, because it is the same question asked twice:
 * the title changes, not the gesture.
 */
import { useI18n } from "vue-i18n";
import { Users } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    /**
     * Why the question is asked, sent back as is with the answer.
     *
     * **Handed back rather than read again.** The caller held the intent in a
     * variable and read it again at click time; in between, closing the modal
     * reset it, and "Ajouter quelqu'un" ended up opening a private
     * conversation. An answer that carries its question cannot mistake the
     * question.
     */
    purpose: { type: String, default: null },
    /** Who can be picked. Empty when there is nobody to offer. */
    people: { type: Array, default: () => [] },
    emptyLabel: { type: String, default: "" },
});

const emit = defineEmits(["close", "pick"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :title="title"
        :icon="Users"
        v-on:close="emit('close')"
    >
        <p v-if="!people.length" class="text-sm text-muted">
            {{ emptyLabel || t("shared.space_chat.channels.nobody_left") }}
        </p>

        <ul v-else class="flex flex-col gap-1">
            <li v-for="person in people" :key="person.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-primary transition-colors hover:bg-surface-2/70"
                    v-on:click="emit('pick', { id: person.id, purpose })"
                >
                    <!-- The initial rather than an avatar: there are no
                         photos in a space, and an empty circle would be a
                         hole. -->
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-2 text-xs font-medium text-secondary"
                    >
                        {{ (person.label ?? "?").slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ person.label }}</span>
                </button>
            </li>
        </ul>
    </AppModal>
</template>
