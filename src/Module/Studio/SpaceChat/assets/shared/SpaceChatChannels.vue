<script setup>
/**
 * The rooms of a space's conversation, and the studio's controls over them.
 *
 * **Drawn on both sides, controllable on one.** The client sees the rooms that
 * were opened to them and switches between them; every button that changes the
 * list is bound to a path the client's page is never handed, so the same
 * component is safe on a page a customer reads. Hiding the controls behind a
 * prop rather than behind a second component keeps the two sides from drifting
 * - a room drawn differently on each side is a room somebody misreads.
 *
 * Separate from the panel because the panel is about messages. A rail of rooms,
 * a name being typed, a person being added: none of that is the conversation,
 * and all of it was going to double the size of a component that is already
 * about scrolling.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Check, EyeOff, Hash, Plus, Trash2, UserPlus, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    channels: { type: Array, default: () => [] },
    current: { type: [Number, null], default: null },
    /** The space's own team, for the picker. Empty on the client's side. */
    team: { type: Array, default: () => [] },
    /** Null on the client's page: only the studio arranges the rooms. */
    createPath: { type: String, default: null },
    renamePath: { type: String, default: null },
    audiencePath: { type: String, default: null },
    deletePath: { type: String, default: null },
    invitePath: { type: String, default: null },
});

const emit = defineEmits(["select", "create", "rename", "audience", "delete", "invite"]);

const { t } = useI18n();

const naming = ref(false);
const draft = ref("");
const inviting = ref(false);

const canArrange = computed(() => !!props.createPath);

const open = computed(
    () => props.channels.find((channel) => channel.id === props.current) ?? null,
);

/** The main room is nobody's to delete or close, so it offers neither. */
const arrangeable = computed(
    () => canArrange.value && open.value && !open.value.isMain && !open.value.isDirect,
);

/** Who is not in the room yet. Somebody already there is not an invitation. */
const invitable = computed(() => {
    const inside = new Set((open.value?.members ?? []).map((member) => member.label));

    return props.team.filter((person) => !inside.has(person.label));
});

function confirmName() {
    const name = draft.value.trim();
    if ("" === name) return;

    emit("create", name);
    draft.value = "";
    naming.value = false;
}

function askDelete() {
    if (!window.confirm(t("shared.space_chat.channels.delete_confirm"))) return;

    emit("delete", open.value);
}

function rename() {
    const name = window.prompt(
        t("shared.space_chat.channels.name_placeholder"),
        open.value?.name ?? "",
    );

    if (null === name || "" === name.trim()) return;

    emit("rename", { channel: open.value, name: name.trim() });
}
</script>

<template>
    <div class="flex flex-col gap-2 border-b border-line/60 px-4 py-2.5">
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                v-for="channel in channels"
                :key="channel.id"
                type="button"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-xs transition-colors"
                :class="
                    channel.id === current
                        ? 'bg-accent/15 text-accent'
                        : 'text-secondary hover:bg-surface-2/60 hover:text-primary'
                "
                v-on:click="emit('select', channel.id)"
            >
                <Hash class="h-3 w-3" :stroke-width="2" />
                {{ channel.name }}

                <!-- Dit sur la pastille et pas seulement dans les réglages :
                     ce qui se tape ici ne sort pas de l'agence, et c'est la
                     chose à savoir avant d'écrire, pas après. -->
                <EyeOff
                    v-if="canArrange && !channel.openToClient"
                    class="h-3 w-3 opacity-70"
                    :stroke-width="2"
                    :aria-label="t('shared.space_chat.channels.internal')"
                />
            </button>

            <button
                v-if="canArrange && !naming"
                type="button"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-secondary transition-colors hover:bg-surface-2/60 hover:text-primary"
                v-on:click="naming = true"
            >
                <Plus class="h-3 w-3" :stroke-width="2" />
                {{ t("shared.space_chat.channels.new") }}
            </button>
        </div>

        <div v-if="naming" class="flex items-center gap-1.5">
            <input
                id="space-chat-channel-name"
                v-model="draft"
                type="text"
                class="w-48 rounded-md border border-line/60 bg-surface px-2 py-1 text-xs text-primary"
                :placeholder="t('shared.space_chat.channels.name_placeholder')"
                v-on:keydown.enter.prevent="confirmName"
                v-on:keydown.esc.prevent="naming = false"
            >
            <AppButton size="xs" variant="primary" :icon="Check" v-on:click="confirmName">
                {{ t("shared.space_chat.channels.create") }}
            </AppButton>
            <AppButton size="xs" variant="ghost" :icon="X" v-on:click="naming = false">
                {{ t("shared.space_chat.channels.cancel") }}
            </AppButton>
        </div>

        <div v-if="arrangeable" class="flex flex-wrap items-center gap-1.5">
            <AppButton size="xs" variant="ghost" v-on:click="rename">
                {{ t("shared.space_chat.channels.rename") }}
            </AppButton>

            <AppButton
                size="xs"
                variant="ghost"
                v-on:click="emit('audience', { channel: open, openToClient: !open.openToClient })"
            >
                {{
                    t(
                        open.openToClient
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

            <span class="text-xs text-muted">
                {{ t("shared.space_chat.channels.members", (open.members ?? []).length) }}
            </span>
        </div>

        <div v-if="inviting && arrangeable" class="flex flex-wrap gap-1.5">
            <AppButton
                v-for="person in invitable"
                :key="person.id"
                size="xs"
                variant="ghost"
                v-on:click="
                    emit('invite', { channel: open, userId: person.id });
                    inviting = false;
                "
            >
                {{ person.label }}
            </AppButton>
        </div>
    </div>
</template>
