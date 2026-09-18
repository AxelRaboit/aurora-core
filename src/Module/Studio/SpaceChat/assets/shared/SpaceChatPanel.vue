<script setup>
/**
 * The conversation of a space, both sides of it.
 *
 * **It is not the card threads, and it is not meant to replace them.** What is
 * about one post belongs on that post, where somebody reopening it a month
 * later finds the objection next to what was objected to. This is for
 * everything that is about the work and not about one card - next month's
 * brief, a campaign moving, who is sending the logo - which used to land on
 * whichever card happened to be open.
 *
 * One shared stream, like the threads: what the studio writes here the client
 * reads, and the notice above the box says so. There is no internal side,
 * deliberately - a flag one forgets once is worse than not having one.
 *
 * It lives in `assets/shared/` and speaks `shared.space_chat.*` because both
 * surfaces mount it: a key under `backend.` rendered on a page a customer reads
 * is a namespace that has stopped meaning anything.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { PanelLeft, Radio, Send, Trash2, WifiOff } from "lucide-vue-next";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import SpaceChatChannels from "./SpaceChatChannels.vue";
import SpaceChatRoomBar from "./SpaceChatRoomBar.vue";
import { useSpaceChat } from "./composables/useSpaceChat.js";
import { useSpaceChatChannels } from "./composables/useSpaceChatChannels.js";

const props = defineProps({
    messages: { type: Array, default: () => [] },
    postPath: { type: String, default: null },
    reloadPath: { type: String, required: true },
    /** Null on the client's page: only the studio removes its own messages. */
    deletePath: { type: String, default: null },
    /** Null when no hub is running, and then nothing tries to connect. */
    streamUrl: { type: String, default: null },
    /** The rooms this reader may hear, and which one is open. */
    channels: { type: Array, default: () => [] },
    channelId: { type: [Number, null], default: null },
    /** The space's team, for invitations. Empty on the client's page. */
    team: { type: Array, default: () => [] },
    /** Null on the client's page: the rooms are the studio's to arrange. */
    channelCreatePath: { type: String, default: null },
    channelRenamePath: { type: String, default: null },
    channelAudiencePath: { type: String, default: null },
    channelDeletePath: { type: String, default: null },
    channelInvitePath: { type: String, default: null },
    /** Shown above the box: who reads what is typed here. */
    notice: { type: String, default: "" },
    /**
     * Prend toute la hauteur de son conteneur, au lieu de sa hauteur fixe.
     *
     * C'est le conteneur qui décide, pas le panneau : dans un espace, la
     * discussion occupe l'écran parce qu'elle est l'écran, et sur la page du
     * client elle est un bloc parmi d'autres sous le calendrier. Le même
     * composant, deux places, et la place qui tranche.
     */
    fill: { type: Boolean, default: false },
    /**
     * Which side of the conversation is reading.
     *
     * **The same stream, drawn from each reader's point of view.** A message is
     * on the right when the person looking at it wrote it, so the studio sees
     * its own on the right and the client sees theirs on the right - and the
     * two are looking at one conversation, not two. Without this the component
     * would have to guess, and it would guess wrong on one of the two surfaces.
     */
    ownSide: {
        type: String,
        default: "studio",
        validator: (value) => ["studio", "client"].includes(value),
    },
});

const { t, d } = useI18n();

const { messages, loading, live, expectsLive, currentChannel, select, post, remove } =
    useSpaceChat(
        props.messages,
        {
            postPath: props.postPath,
            reloadPath: props.reloadPath,
            deletePath: props.deletePath,
            streamUrl: props.streamUrl,
        },
        props.channelId,
    );

const { channels, create, rename, setAudience, drop, invite } = useSpaceChatChannels(
    props.channels,
    {
        createPath: props.channelCreatePath,
        renamePath: props.channelRenamePath,
        audiencePath: props.channelAudiencePath,
        deletePath: props.channelDeletePath,
        invitePath: props.channelInvitePath,
    },
);

/**
 * Whether there is a list to show at all.
 *
 * One room and no right to open another is a conversation, not a set of them:
 * drawing a rail for it would be a control with one choice in it.
 */
const hasRail = computed(() => channels.value.length > 1 || !!props.channelCreatePath);

/** Out only on a phone, where the rail is a drawer over the conversation. */
const railOpen = ref(false);

/** The room on screen, which is what the header names. */
const openChannel = computed(
    () => channels.value.find((channel) => channel.id === currentChannel.value) ?? null,
);

/**
 * Whether this reader may arrange the room they are in.
 *
 * The main room and a private conversation are nobody's to rename or close, and
 * the client's page is handed none of the paths at all.
 */
const arrangeable = computed(
    () =>
        !!props.channelCreatePath &&
        !!openChannel.value &&
        !openChannel.value.isMain &&
        !openChannel.value.isDirect,
);

/**
 * Leaving a room that no longer exists.
 *
 * Deleting the open room is the one case where the list changes under the
 * reader: they land back in the first room rather than on a conversation that
 * has nothing behind it.
 */
async function onDrop(channel) {
    await drop(channel);

    if (channel?.id === currentChannel.value) {
        await select(channels.value[0]?.id ?? null);
    }
}

const draft = ref("");
const scroller = ref(null);
const content = ref(null);

const canPost = computed(() => !!props.postPath);

/** Whether this message was written by whoever is reading. */
function mine(message) {
    return message.fromClient === ("client" === props.ownSide);
}

/**
 * The stream, with a line whenever the day changes.
 *
 * A conversation that runs over weeks is unreadable without them: every message
 * carries a time, and nothing says which day that time was on.
 */
const entries = computed(() => {
    const stream = [];
    let day = null;

    for (const message of messages.value) {
        const at = new Date(message.createdAt);
        const stamp = at.toDateString();

        if (stamp !== day) {
            stream.push({ kind: "day", key: `d${stamp}`, at });
            day = stamp;
        }

        stream.push({ kind: "message", key: `m${message.id}`, message });
    }

    return stream;
});

function toBottom() {
    if (scroller.value) {
        scroller.value.scrollTop = scroller.value.scrollHeight;
    }
}

/**
 * Whether the reader is following the end of the conversation.
 *
 * True until they scroll up to read something older, and true again the moment
 * they come back down. A chat that jumps to the newest message while somebody
 * is reading last week's is a chat they cannot read.
 */
const following = ref(true);

function onScroll() {
    const element = scroller.value;
    if (!element) return;

    following.value =
        element.scrollHeight - element.scrollTop - element.clientHeight < 80;
}

/**
 * **Observed rather than timed, and that is the whole lesson here.**
 *
 * Opening at the bottom looks like a line to run after mount, and it is not: at
 * that point the box may still have no height, and scrolling something that is
 * not yet scrollable does nothing at all. Waiting a frame fixed it on the
 * studio's page, where the panel mounts when somebody switches to it, and left
 * the client's page opening on its oldest message - there it mounts with the
 * rest of a long document. Waiting for the web fonts fixed a third case. Each
 * of those is a guess about when the layout settles, and there is always
 * another one.
 *
 * So the content is watched instead. The box gaining its height, a font
 * rewrapping the text, a message arriving: one event here, one answer - if the
 * reader was at the end, keep them there.
 */
let observer = null;

onMounted(() => {
    if (typeof ResizeObserver === "undefined") {
        void nextTick().then(toBottom);

        return;
    }

    observer = new ResizeObserver(() => {
        if (following.value) toBottom();
    });

    if (content.value) observer.observe(content.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

async function send() {
    const body = draft.value.trim();
    if ("" === body) return;

    // Cleared on success only: a message the server refused is a message the
    // writer still has, rather than something they have to type again.
    if (await post(body)) {
        draft.value = "";

        await nextTick();
        toBottom();
    }
}

/**
 * Enter sends, Shift+Enter breaks the line.
 *
 * What every chat does, and the opposite of what the card threads do - those
 * are a textarea in a form somebody is filling in. The two behave differently
 * because they are two different acts: writing a note about a post, and
 * talking.
 */
function onKeydown(event) {
    if ("Enter" !== event.key || event.shiftKey) return;

    event.preventDefault();
    void send();
}
</script>

<template>
    <section
        class="relative flex flex-col overflow-hidden rounded-lg border border-line/60 bg-surface-2/20 md:flex-row"
        :class="fill ? 'h-full' : 'h-[32rem]'"
    >
        <SpaceChatChannels
            v-if="hasRail"
            :channels="channels"
            :current="currentChannel"
            :create-path="channelCreatePath"
            :open="railOpen"
            v-on:select="select"
            v-on:create="create"
            v-on:close="railOpen = false"
        />

        <!-- `min-w-0` sur la colonne : sans lui un message d'un seul mot très
             long pousse la conversation au-delà de la boîte et c'est le rail
             qui se fait écraser. -->
        <div class="flex min-h-0 min-w-0 flex-1 flex-col">
            <header class="flex items-center gap-2 border-b border-line/60 px-4 py-2.5">
                <!-- Sur téléphone le titre est la poignée du tiroir : c'est le
                     nom du salon qu'on touche pour en changer, ce qui économise
                     un bouton et dit où mène le geste. À partir de `md` il
                     redevient un titre, la liste étant déjà à côté. -->
                <button
                    v-if="hasRail"
                    type="button"
                    class="flex items-center gap-1.5 text-sm font-medium text-primary md:pointer-events-none"
                    :aria-expanded="railOpen"
                    v-on:click="railOpen = !railOpen"
                >
                    <PanelLeft class="h-3.5 w-3.5 md:hidden" :stroke-width="2" />
                    {{ openChannel?.name ?? t("shared.space_chat.title") }}
                </button>

                <h2 v-else class="text-sm font-medium text-primary">
                    {{ openChannel?.name ?? t("shared.space_chat.title") }}
                </h2>

                <!-- Dit dans l'en-tête et pas seulement sur la pastille : c'est la
                 phrase à lire avant d'écrire, et l'en-tête est là où le regard
                 revient entre deux messages. -->
                <span
                    v-if="channelCreatePath && openChannel && !openChannel.openToClient"
                    class="rounded bg-surface-2/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-muted"
                >
                    {{ t("shared.space_chat.channels.internal") }}
                </span>

                <!-- Said out loud, because the difference is invisible otherwise:
                 somebody typing into a chat that has stopped being live
                 deserves to know the other side will not see it appear. -->
                <span
                    v-if="expectsLive"
                    class="ml-auto flex items-center gap-1.5 text-xs"
                    :class="live ? 'text-emerald-500' : 'text-amber-500'"
                >
                    <component
                        :is="live ? Radio : WifiOff"
                        class="h-3.5 w-3.5"
                        :stroke-width="2"
                    />
                    {{ t(live ? "shared.space_chat.live" : "shared.space_chat.reconnecting") }}
                </span>
            </header>

            <SpaceChatRoomBar
                v-if="arrangeable"
                :channel="openChannel"
                :team="team"
                :invite-path="channelInvitePath"
                v-on:rename="rename"
                v-on:audience="setAudience"
                v-on:delete="onDrop"
                v-on:invite="invite"
            />

            <!-- `flex flex-col` sur le défilement, `mt-auto` sur les entrées : une
             conversation courte se pose en bas de la boîte plutôt que de
             flotter en haut d'un grand vide. Quand elle déborde, la marge
             automatique vaut zéro et le défilement redevient ordinaire. -->
            <div
                ref="scroller"
                class="flex flex-1 flex-col overflow-y-auto px-4 py-3"
                v-on:scroll="onScroll"
            >
                <!-- Un conteneur pour les entrées, et c'est lui qu'on observe : sa
                 hauteur est celle du contenu, la seule mesure qui dise qu'il y
                 a du nouveau sous le pli. -->
                <div ref="content" class="mt-auto space-y-2">
                    <p v-if="!entries.length" class="text-sm text-muted">
                        {{ t("shared.space_chat.empty") }}
                    </p>

                    <template v-for="entry in entries" :key="entry.key">
                        <div
                            v-if="'day' === entry.kind"
                            class="flex items-center gap-3 py-1"
                        >
                            <span class="h-px flex-1 bg-line/60" />
                            <!-- The day, without a clock: `short` would print
                             "17/09/2026 00:48" on a line whose whole job is to
                             say which day the messages under it belong to. -->
                            <span class="text-xs text-muted">
                                {{ d(entry.at, "long") }}
                            </span>
                            <span class="h-px flex-1 bg-line/60" />
                        </div>

                        <!-- Le cote decide l'alignement, la couleur le suit. Deux
                         signaux pour la meme chose plutot qu'un, parce que
                         l'alignement seul se perd sur un message d'une ligne et
                         que la couleur seule se perd pour qui la distingue mal. -->
                        <div
                            v-else
                            class="flex"
                            :class="mine(entry.message) ? 'justify-end' : 'justify-start'"
                        >
                            <div
                                class="group max-w-[min(42rem,80%)] rounded-lg px-3 py-2"
                                :class="
                                    mine(entry.message)
                                        ? 'border border-accent-500/20 bg-accent-500/5'
                                        : 'bg-surface-2/60'
                                "
                            >
                                <!-- `flex-wrap` et pas une ligne : un nom, une
                                     date et la pastille « client » font 130
                                     pixels, et une fenêtre de 250 les poussait
                                     hors de la bulle - la pastille sortait de
                                     l'écran par la droite. Ils se replient
                                     plutôt que de déborder. -->
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <!-- Le nom reste des deux cotes : un studio a
                                     plusieurs personnes, et « qui a repondu » est
                                     une question qu'on se pose de son propre cote
                                     aussi. -->
                                    <span class="min-w-0 break-all text-xs font-medium text-primary">
                                        {{ entry.message.author }}
                                    </span>
                                    <span class="text-xs text-muted">
                                        {{ d(new Date(entry.message.createdAt), "short") }}
                                    </span>
                                    <!-- Seulement quand ca apprend quelque chose : sur
                                     sa propre page, le client n'a pas besoin qu'on
                                     lui dise qu'il est le client. -->
                                    <span
                                        v-if="entry.message.fromClient && 'studio' === ownSide"
                                        class="text-xs text-accent-500"
                                    >
                                        {{ t("shared.space_chat.from_client") }}
                                    </span>
                                    <button
                                        v-if="deletePath && !entry.message.fromClient"
                                        type="button"
                                        class="ml-auto rounded p-1 text-muted opacity-0 transition-opacity hover:text-red-500 group-hover:opacity-100"
                                        :aria-label="t('shared.common.delete')"
                                        v-on:click="remove(entry.message)"
                                    >
                                        <Trash2 class="h-3 w-3" :stroke-width="2" />
                                    </button>
                                </div>
                                <p class="mt-1 whitespace-pre-line text-sm text-primary">
                                    {{ entry.message.body }}
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div v-if="canPost" class="space-y-2 border-t border-line/60 px-4 py-3">
                <!-- On the wrapper rather than on the field: `AppTextarea` is a
                 label, a control and a hint under one element, and hanging a
                 key handler on the component would rely on which of them
                 Vue happens to pass it to. Keystrokes bubble. -->
                <div v-on:keydown="onKeydown">
                    <AppTextarea
                        :model-value="draft"
                        :placeholder="t('shared.space_chat.placeholder')"
                        :hint="notice"
                        :rows="2"
                        v-on:update:model-value="draft = $event"
                    />
                </div>
                <div class="flex justify-end">
                    <AppButton
                        variant="primary"
                        size="sm"
                        :loading="loading"
                        :disabled="!draft.trim()"
                        v-on:click="send"
                    >
                        <Send class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.space_chat.send") }}
                    </AppButton>
                </div>
            </div>
        </div>
    </section>
</template>
