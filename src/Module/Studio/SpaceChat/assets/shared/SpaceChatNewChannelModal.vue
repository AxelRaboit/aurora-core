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
 * **L'audience se décide ici, et c'est un revirement.** Elle ne s'y décidait
 * pas : un canal naissait interne, et l'ouvrir au client attendait les réglages
 * du salon. Le raisonnement tenait - décider une fois qu'il y a quelque chose
 * dedans - mais il laissait la question sans réponse au moment où on se la
 * pose, c'est-à-dire en nommant la pièce. « Le mois prochain » et « Entre
 * nous » ne se nomment pas pareil selon qui les lit.
 *
 * La case reste **décochée**, comme l'entité : un salon ouvert pour parler d'un
 * client ne devient pas un salon que ce client lit parce que quelqu'un a coché
 * sans lire. Ce qui change est qu'on le décide en connaissance de cause au lieu
 * de l'hériter.
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
});

const emit = defineEmits(["close", "create"]);

const { t } = useI18n();

const name = ref("");
const openToClient = ref(false);

/** Rouvrir, c'est repartir d'une page blanche : le nom d'avant a été créé. */
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

    emit("create", { name: clean, openToClient: openToClient.value });
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
            <!-- Un exemple dans le champ plutôt qu'un vide : « Nom du canal »
                 est déjà écrit au-dessus, le répéter dedans n'aurait rien
                 appris, tandis qu'un nom plausible dit quelle sorte de nom on
                 attend. -->
            <AppInput
                id="space-chat-new-channel"
                v-model="name"
                :label="t('shared.space_chat.channels.name_placeholder')"
                :placeholder="t('shared.space_chat.channels.name_example')"
                v-on:keydown.enter.prevent="submit"
            />

            <!-- Décochée par défaut, et la phrase change avec l'état : « qui
                 le lira » est ce qu'on veut savoir avant de nommer une pièce,
                 pas après l'avoir remplie. -->
            <AppCheckbox
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
