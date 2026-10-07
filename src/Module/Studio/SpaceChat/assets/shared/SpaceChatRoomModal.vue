<script setup>
/**
 * What can be done with a room, and who is in it.
 *
 * **A modal behind three dots, rather than four buttons under the title.** The
 * bar said "Renommer, Ouvrir au client, Ajouter quelqu'un, Supprimer le canal"
 * all the time: four rare actions taking a line above what one came to read,
 * and on a phone they wrapped. They are now where one goes looking for them,
 * with room to say what they do.
 *
 * **The participants are inside, not elsewhere.** "2 personnes" was a number
 * with no answer to the only question it raises. The list is here, in the same
 * place as what changes it.
 *
 * A private conversation offers only one action: removing it from one's list.
 * It is not renamed - it carries the other person's name - and it is not
 * deleted, because erasing what someone wrote to you is not tidying up.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { EyeOff, Hash, MessageCircle, Trash2, UserMinus, UserPlus, Users } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    channel: { type: Object, default: null },
    /** Null when this reader only looks: the client, or a read-only member. */
    canArrange: { type: Boolean, default: false },
    /** Show the room to the client or hide it: the right to share the space. */
    canSetAudience: { type: Boolean, default: false },
    canInvite: { type: Boolean, default: false },
    canUninvite: { type: Boolean, default: false },
    canHide: { type: Boolean, default: false },
});

const emit = defineEmits([
    "close",
    "rename",
    "audience",
    "invite",
    "remove-member",
    "delete",
    "hide",
]);

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

/** The name is set on a channel opened here, never on the main one nor on a conversation. */
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
                    class="w-full sm:w-auto"
                    :disabled="!name.trim() || name.trim() === channel.name"
                    v-on:click="rename"
                >
                    {{ t("shared.space_chat.channels.rename") }}
                </AppButton>
            </div>

            <!-- Who is there, spelled out. The main channel has no list:
                 everybody is in it, and an empty list would say the
                 opposite. -->
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

                        <!-- The counterpart of "Ajouter quelqu'un", on the
                             person's line: that is where one looks for them,
                             and a second list elsewhere would say the same
                             thing twice. Nothing they wrote is erased. -->
                        <button
                            v-if="canUninvite && !isMain && !isDirect"
                            type="button"
                            class="shrink-0 rounded p-1 text-muted transition-colors hover:bg-surface hover:text-rose-400"
                            :title="t('shared.space_chat.channels.uninvite')"
                            :aria-label="t('shared.space_chat.channels.uninvite')"
                            v-on:click="emit('remove-member', { channel, memberId: member.id })"
                        >
                            <UserMinus class="h-3.5 w-3.5" :stroke-width="2" />
                        </button>
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
                    v-if="canSetAudience"
                    size="sm"
                    variant="secondary"
                    class="w-full sm:w-auto"
                    v-on:click="emit('audience', { channel, openToClient: !channel.openToClient })"
                >
                    <EyeOff class="h-3.5 w-3.5" :stroke-width="2" />
                    {{
                        t(
                            channel.openToClient
                                ? "shared.space_chat.channels.close_to_client"
                                : "shared.space_chat.channels.open_to_client",
                        )
                    }}
                </AppButton>
            </div>

            <!-- Stacked and full width on a phone: side by side, three
                 buttons shared three hundred pixels, so each took two lines
                 and none offered a clear target. They return to their natural
                 width as soon as there is room. -->
            <div class="flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:flex-wrap">
                <AppButton
                    v-if="canInvite && !isDirect && !isMain"
                    size="sm"
                    variant="secondary"
                    class="w-full sm:w-auto"
                    v-on:click="emit('invite')"
                >
                    <UserPlus class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.space_chat.channels.invite") }}
                </AppButton>

                <AppButton
                    v-if="canHide && isDirect"
                    size="sm"
                    variant="secondary"
                    class="w-full sm:w-auto"
                    v-on:click="emit('hide', channel)"
                >
                    {{ t("shared.space_chat.channels.hide") }}
                </AppButton>

                <AppButton
                    v-if="canArrange && !isDirect && !isMain"
                    size="sm"
                    variant="danger"
                    class="w-full sm:w-auto"
                    v-on:click="emit('delete', channel)"
                >
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.space_chat.channels.delete") }}
                </AppButton>
            </div>

            <p v-if="canHide && isDirect" class="text-xs text-muted">
                {{ t("shared.space_chat.channels.hide_hint") }}
            </p>
        </div>
    </AppModal>
</template>
