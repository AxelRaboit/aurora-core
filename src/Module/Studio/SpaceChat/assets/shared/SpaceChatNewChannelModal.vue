<script setup>
/**
 * Ouvrir un canal : un nom, et c'est tout.
 *
 * **Une modale plutôt qu'un champ dans le rail.** Le formulaire poussait en bas
 * de la liste, dans deux cents pixels de large, sous les salons qu'il allait
 * rejoindre : le champ, deux boutons et le libellé de chacun tenaient sur trois
 * lignes serrées, et sur téléphone tout cela vivait dans un tiroir qui couvre
 * déjà la conversation. La question est courte mais elle mérite d'être posée au
 * milieu de l'écran, comme les deux autres de cette discussion.
 *
 * Le nom seul, et pas l'audience : un canal naît interne, et l'ouvrir au client
 * est une décision qui se prend une fois qu'il a quelque chose dedans - elle a
 * sa place dans les réglages du salon, où elle dit aussi ce qu'elle change.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Hash } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "create"]);

const { t } = useI18n();

const name = ref("");

/** Rouvrir, c'est repartir d'une page blanche : le nom d'avant a été créé. */
watch(
    () => props.show,
    () => {
        name.value = "";
    },
);

function submit() {
    const clean = name.value.trim();
    if ("" === clean) return;

    emit("create", clean);
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
            <AppInput
                id="space-chat-new-channel"
                v-model="name"
                :label="t('shared.space_chat.channels.name_placeholder')"
                v-on:keydown.enter.prevent="submit"
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
