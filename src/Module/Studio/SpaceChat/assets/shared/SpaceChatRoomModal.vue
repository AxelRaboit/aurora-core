<script setup>
/**
 * Ce qu'on peut faire d'un salon, et qui s'y trouve.
 *
 * **Une modale derrière trois points, plutôt que quatre boutons sous le
 * titre.** La barre disait « Renommer, Ouvrir au client, Ajouter quelqu'un,
 * Supprimer le canal » en permanence : quatre gestes rares occupant une ligne
 * au-dessus de ce qu'on est venu lire, et sur téléphone ils passaient à la
 * ligne. Ils sont maintenant là où l'on va les chercher, avec la place de dire
 * ce qu'ils font.
 *
 * **Les participants sont dedans, pas ailleurs.** « 2 personnes » était un
 * chiffre sans réponse à la seule question qu'il pose. La liste est ici, au
 * même endroit que ce qui la modifie.
 *
 * Une conversation privée n'offre qu'un geste : la retirer de sa liste. Elle ne
 * se renomme pas - elle porte le nom de l'autre - et elle ne se supprime pas,
 * parce qu'effacer ce que quelqu'un vous a écrit n'est pas un rangement.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { EyeOff, Hash, MessageCircle, Trash2, UserPlus, Users } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    channel: { type: Object, default: null },
    /** Null when this reader only looks: the client, or a read-only member. */
    canArrange: { type: Boolean, default: false },
    canInvite: { type: Boolean, default: false },
    canHide: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "rename", "audience", "invite", "delete", "hide"]);

const { t } = useI18n();

const name = ref("");

watch(
    () => [props.show, props.channel?.name],
    () => {
        name.value = props.channel?.name ?? "";
    },
    { immediate: true },
);

const isMain = computed(() => !!props.channel?.isMain);
const isDirect = computed(() => !!props.channel?.isDirect);

/** Le nom se règle sur un canal ouvert ici, jamais sur le principal ni sur une conversation. */
const renameable = computed(() => props.canArrange && !isMain.value && !isDirect.value);

function rename() {
    const clean = name.value.trim();
    if ("" === clean || clean === props.channel?.name) return;

    emit("rename", { channel: props.channel, name: clean });
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="md"
        :title="channel?.name ?? ''"
        :icon="isDirect ? MessageCircle : Hash"
        v-on:close="emit('close')"
    >
        <div v-if="channel" class="space-y-5">
            <div v-if="renameable" class="space-y-2">
                <AppInput
                    id="space-chat-room-name"
                    v-model="name"
                    :label="t('shared.space_chat.channels.name_placeholder')"
                    v-on:keydown.enter.prevent="rename"
                />
                <AppButton
                    size="sm"
                    variant="secondary"
                    :disabled="!name.trim() || name.trim() === channel.name"
                    v-on:click="rename"
                >
                    {{ t("shared.space_chat.channels.rename") }}
                </AppButton>
            </div>

            <!-- Qui est là, en toutes lettres. Le canal principal n'a pas de
                 liste : tout le monde y est, et une liste vide dirait le
                 contraire. -->
            <div class="space-y-2">
                <h3 class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                    <Users class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.space_chat.channels.participants") }}
                </h3>

                <p v-if="isMain" class="text-sm text-muted">
                    {{ t("shared.space_chat.channels.everybody") }}
                </p>

                <ul v-else class="flex flex-col gap-1">
                    <li
                        v-for="member in channel.members"
                        :key="member.id"
                        class="flex items-center gap-2 rounded-lg bg-surface-2/40 px-3 py-1.5 text-sm text-primary"
                    >
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface text-xs font-medium text-secondary"
                        >
                            {{ (member.label ?? "?").slice(0, 1).toUpperCase() }}
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ member.label }}</span>
                        <span v-if="member.fromClient" class="shrink-0 text-xs text-accent-500">
                            {{ t("shared.space_chat.from_client") }}
                        </span>
                    </li>
                </ul>
            </div>

            <div v-if="canArrange && !isDirect && !isMain" class="space-y-2">
                <h3 class="text-xs font-medium uppercase tracking-wide text-muted">
                    {{ t("shared.space_chat.channels.audience") }}
                </h3>
                <p class="text-sm text-secondary">
                    {{
                        t(
                            channel.openToClient
                                ? "shared.space_chat.channels.audience_open"
                                : "shared.space_chat.channels.audience_internal",
                        )
                    }}
                </p>
                <AppButton
                    size="sm"
                    variant="secondary"
                    :icon="EyeOff"
                    v-on:click="emit('audience', { channel, openToClient: !channel.openToClient })"
                >
                    {{
                        t(
                            channel.openToClient
                                ? "shared.space_chat.channels.close_to_client"
                                : "shared.space_chat.channels.open_to_client",
                        )
                    }}
                </AppButton>
            </div>

            <div class="flex flex-wrap gap-2 border-t border-line/60 pt-4">
                <AppButton
                    v-if="canInvite && !isDirect && !isMain"
                    size="sm"
                    variant="secondary"
                    :icon="UserPlus"
                    v-on:click="emit('invite')"
                >
                    {{ t("shared.space_chat.channels.invite") }}
                </AppButton>

                <AppButton
                    v-if="canHide && isDirect"
                    size="sm"
                    variant="secondary"
                    v-on:click="emit('hide', channel)"
                >
                    {{ t("shared.space_chat.channels.hide") }}
                </AppButton>

                <AppButton
                    v-if="canArrange && !isDirect && !isMain"
                    size="sm"
                    variant="danger"
                    :icon="Trash2"
                    v-on:click="emit('delete', channel)"
                >
                    {{ t("shared.space_chat.channels.delete") }}
                </AppButton>
            </div>

            <p v-if="canHide && isDirect" class="text-xs text-muted">
                {{ t("shared.space_chat.channels.hide_hint") }}
            </p>
        </div>
    </AppModal>
</template>
