<script setup>
/**
 * Opening a channel: a name, and that is all.
 *
 * **A modal rather than a field in the rail.** The form grew at the bottom of
 * the list, in two hundred pixels of width, under the rooms it was about to
 * join: the field, two buttons and the label of each fit on three cramped
 * lines, and on a phone all of it lived in a drawer that already covers the
 * conversation. The question is short but it deserves to be asked in the middle
 * of the screen, like the two others of this conversation.
 *
 * **The audience is decided here, and that is a reversal.** It used not to be:
 * a channel was born internal, and opening it to the client waited for the
 * room's settings. The reasoning held - decide once there is something in it -
 * but it left the question unanswered at the moment it is asked, that is while
 * naming the room. "Le mois prochain" and "Entre nous" are not named the same
 * way depending on who reads them.
 *
 * The box stays **unticked**, like the entity: a room opened to talk about a
 * client does not become a room that client reads because someone ticked
 * without reading. What changes is that it is decided knowingly instead of
 * inherited.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Hash } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    /**
     * Showing the channel to the client from its creation: the right to share
     * the space. Without it, the box is not offered and the channel is born
     * internal.
     */
    canShowToClient: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "create"]);

const { t } = useI18n();

const name = ref("");
const openToClient = ref(false);

/** Reopening starts from a blank page: the previous name has been created. */
watch(
    () => props.show,
    () => {
        name.value = "";
        openToClient.value = false;
    },
);

function submit() {
    const clean = name.value.trim();
    if ("" === clean) return;

    emit("create", { name: clean, openToClient: props.canShowToClient && openToClient.value });
    emit("close");
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :title="t('shared.space_chat.channels.new')"
        :icon="Hash"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <!-- An example in the field rather than nothing: "Nom du canal"
                 is already written above, repeating it inside would teach
                 nothing, whereas a plausible name says what kind of name is
                 expected. -->
            <AppInput
                id="space-chat-new-channel"
                v-model="name"
                :label="t('shared.space_chat.channels.name_placeholder')"
                :placeholder="t('shared.space_chat.channels.name_example')"
                v-on:keydown.enter.prevent="submit"
            />

            <!-- Unticked by default, and the sentence changes with the state:
                 "who will read it" is what one wants to know before naming a
                 room, not after filling it. -->
            <AppCheckbox
                v-if="canShowToClient"
                v-model="openToClient"
                :label="t('shared.space_chat.channels.new_open_to_client')"
                :hint="t(openToClient
                    ? 'shared.space_chat.channels.new_open_to_client_hint'
                    : 'shared.space_chat.channels.new_internal_hint')"
            />

            <p class="text-xs text-muted">
                {{ t("shared.space_chat.channels.new_hint") }}
            </p>

            <AppButton
                class="w-full sm:w-auto"
                size="sm"
                variant="primary"
                :disabled="!name.trim()"
                v-on:click="submit"
            >
                {{ t("shared.space_chat.channels.create") }}
            </AppButton>
        </div>
    </AppModal>
</template>
