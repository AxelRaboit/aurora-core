<script setup>
/**
 * What the studio can do to the room it is looking at.
 *
 * Under the room's name rather than in the rail, because these act on one room
 * and the rail is about choosing between them. A menu hidden behind the title
 * would be one click closer to Slack and one click further from being found:
 * these are rare gestures, and rare gestures that are invisible get asked for
 * by email instead.
 *
 * Absent entirely on the main room, on private conversations, and on the
 * client's page - nothing here is theirs to do.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Trash2, UserPlus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    channel: { type: Object, default: null },
    /** The space's team, for the picker. Empty on the client's side. */
    team: { type: Array, default: () => [] },
    invitePath: { type: String, default: null },
});

const emit = defineEmits(["rename", "audience", "delete", "invite"]);

const { t } = useI18n();

const inviting = ref(false);

/** Who is not in the room yet. Somebody already there is not an invitation. */
const invitable = computed(() => {
    const inside = new Set((props.channel?.members ?? []).map((member) => member.label));

    return props.team.filter((person) => !inside.has(person.label));
});

function askDelete() {
    if (!window.confirm(t("shared.space_chat.channels.delete_confirm"))) return;

    emit("delete", props.channel);
}

function rename() {
    const name = window.prompt(
        t("shared.space_chat.channels.name_placeholder"),
        props.channel?.name ?? "",
    );

    if (null === name || "" === name.trim()) return;

    emit("rename", { channel: props.channel, name: name.trim() });
}
</script>

<template>
    <div v-if="channel" class="flex flex-col gap-1.5 border-b border-line/60 px-4 py-2">
        <div class="flex flex-wrap items-center gap-1.5">
            <AppButton size="xs" variant="ghost" v-on:click="rename">
                {{ t("shared.space_chat.channels.rename") }}
            </AppButton>

            <AppButton
                size="xs"
                variant="ghost"
                v-on:click="
                    emit('audience', { channel, openToClient: !channel.openToClient })
                "
            >
                {{
                    t(
                        channel.openToClient
                            ? "shared.space_chat.channels.close_to_client"
                            : "shared.space_chat.channels.open_to_client",
                    )
                }}
            </AppButton>

            <AppButton
                v-if="invitePath && invitable.length"
                size="xs"
                variant="ghost"
                :icon="UserPlus"
                v-on:click="inviting = !inviting"
            >
                {{ t("shared.space_chat.channels.invite") }}
            </AppButton>

            <AppButton size="xs" variant="ghost" :icon="Trash2" v-on:click="askDelete">
                {{ t("shared.space_chat.channels.delete") }}
            </AppButton>

            <span class="ml-auto text-xs text-muted">
                {{ t("shared.space_chat.channels.members", (channel.members ?? []).length) }}
            </span>
        </div>

        <div v-if="inviting" class="flex flex-wrap gap-1.5">
            <AppButton
                v-for="person in invitable"
                :key="person.id"
                size="xs"
                variant="ghost"
                v-on:click="
                    emit('invite', { channel, userId: person.id });
                    inviting = false;
                "
            >
                {{ person.label }}
            </AppButton>
        </div>
    </div>
</template>
