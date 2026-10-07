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
 * surfaces mount it: a key under `suite.` rendered on a page a customer reads
 * is a namespace that has stopped meaning anything.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { MoreHorizontal, PanelLeft, Radio, Send, Trash2, WifiOff, X } from "lucide-vue-next";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import SpaceChatChannels from "./SpaceChatChannels.vue";
import SpaceChatNewChannelModal from "./SpaceChatNewChannelModal.vue";
import SpaceChatPeopleModal from "./SpaceChatPeopleModal.vue";
import SpaceChatRoomModal from "./SpaceChatRoomModal.vue";
import { useSpaceChat } from "./composables/useSpaceChat.js";
import { useSpaceChatChannels } from "./composables/useSpaceChatChannels.js";

const props = defineProps({
    messages: { type: Array, default: () => [] },
    postPath: { type: String, default: null },
    reloadPath: { type: String, required: true },
    /** Null on the client's page: only the studio removes its own messages. */
    deletePath: { type: String, default: null },
    /** Where the page before this one is fetched from. */
    olderPath: { type: String, default: null },
    /** Null when this reader may not put a conversation away. */
    hidePath: { type: String, default: null },
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
    /**
     * Null without the right to share the space: showing a room to the client
     * or hiding it is that right, the same one that hands out access links.
     */
    channelAudiencePath: { type: String, default: null },
    channelDeletePath: { type: String, default: null },
    channelInvitePath: { type: String, default: null },
    /** Null on the client's page, and on a reader who may not arrange rooms. */
    channelUninvitePath: { type: String, default: null },
    /** Null when this reader may not start a private conversation. */
    chatDirectPath: { type: String, default: null },
    /** Who one can be started with. */
    people: { type: Array, default: () => [] },
    /**
     * Shown above the box: who reads what is typed here.
     *
     * The page's answer, used for the room everybody is in. A room that is not
     * that one answers for itself, below.
     */
    notice: { type: String, default: "" },
    /**
     * Takes the full height of its container instead of its fixed height.
     *
     * The container decides, not the panel: in a space, the conversation fills
     * the screen because it is the screen, and on the client's page it is one
     * block among others under the calendar. The same component, two places,
     * and the place settles it.
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
    /**
     * A message to open on rather than the end of the conversation.
     *
     * Set by the address a search result leads to. Scrolled to and marked
     * once it is on screen; when it is older than what the room loaded, the
     * panel opens at the end as usual.
     */
    focusMessageId: { type: [Number, null], default: null },
});

const { t, d: formatDate } = useI18n();

const {
    messages,
    loading,
    live,
    expectsLive,
    currentChannel,
    select,
    hasOlder,
    loadingOlder,
    loadOlder,
    post,
    remove,
} = useSpaceChat(
    props.messages,
    {
        postPath: props.postPath,
        reloadPath: props.reloadPath,
        deletePath: props.deletePath,
        olderPath: props.olderPath,
        streamUrl: props.streamUrl,
    },
    props.channelId,
);

/** What the three dots open, and the question the list of people asks. */
const roomModal = ref(false);
const peopleFor = ref(null);
/** The form for a new channel, opened from the rail. */
const newChannel = ref(false);
/**
 * The channel whose deletion is waiting for an answer.
 *
 * **Asked, because it is not tidying up.** Putting a conversation away keeps
 * it, removing someone keeps what they wrote; deleting a channel takes its
 * messages with it, and it is the only action in this panel that cannot be
 * undone. A red button in a list of settings is not a question asked.
 */
const pendingDrop = ref(null);

const { channels, create, rename, setAudience, drop, invite, removeMember, openDirect, hide } =
    useSpaceChatChannels(props.channels, {
        createPath: props.channelCreatePath,
        renamePath: props.channelRenamePath,
        audiencePath: props.channelAudiencePath,
        deletePath: props.channelDeletePath,
        invitePath: props.channelInvitePath,
        uninvitePath: props.channelUninvitePath,
        directPath: props.chatDirectPath,
        hidePath: props.hidePath,
    });

/**
 * Opens the private conversation and goes to it.
 *
 * Opening without going there would take a second action to see what was just
 * created, which no chat application does.
 */
async function startDirect(userId) {
    const id = await openDirect(userId);

    if (id) await select(id);
}

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
 * Who reads what is written here, said above the box.
 *
 * **Per room and not per page.** "Ce que vous écrivez ici est lu par le
 * client" is true of the main room and false of the two other kinds: an
 * internal channel does not leave the agency, a private conversation does not
 * leave the two people who have it. A false sentence under an input is worse
 * than no sentence at all - it silences those who believe it, and loosens the
 * tongue of those who no longer read it.
 */
const roomNotice = computed(() => {
    const room = openChannel.value;

    if (!room) return props.notice;
    if (room.isDirect) return t("shared.space_chat.channels.notice_direct");
    if (!room.openToClient) return t("shared.space_chat.channels.notice_internal");

    return props.notice;
});

/**
 * Who the list offers, depending on the question asked.
 *
 * Talking to someone: those there is not already a conversation with. Adding
 * to the channel: those who are not in it yet. Two questions, one list,
 * computed where it is known which one is asked.
 */
const peopleChoices = computed(() => {
    if ("invite" === peopleFor.value) {
        const inside = new Set((openChannel.value?.members ?? []).map((member) => member.label));

        return props.team.filter((person) => !inside.has(person.label));
    }

    const already = new Set(
        channels.value.filter((channel) => channel.isDirect).map((channel) => channel.name),
    );

    return props.people.filter((person) => !already.has(person.label));
});

/**
 * The modal's title, computed here rather than in the template.
 *
 * `t(condition ? 'a' : 'b')` reads badly for the tool that checks every key
 * exists: it takes the first literal for the key, and "invite" is not one. Two
 * separate calls say the same thing and stay checkable.
 */
const pickTitle = computed(() =>
    "invite" === peopleFor.value
        ? t("shared.space_chat.channels.pick_invite")
        : t("shared.space_chat.channels.pick_direct"),
);

/**
 * The live state spelled out, for the tooltip and the screen reader.
 *
 * Two separate calls rather than a ternary inside `t()`, for the same reason as
 * {@link pickTitle}: the tool that checks the keys does not read conditions.
 */
const liveLabel = computed(() =>
    live.value ? t("shared.space_chat.live") : t("shared.space_chat.reconnecting"),
);

/** What is done with the chosen name depends on the question that asked it. */
async function onPick({ id, purpose }) {
    peopleFor.value = null;

    if ("invite" === purpose) {
        await invite({ channel: openChannel.value, userId: id });

        return;
    }

    await startDirect(id);
}

/** Puts the open conversation away, and falls back on the first in the list. */
async function onHide(channel) {
    roomModal.value = false;
    await hide(channel);

    if (channel?.id === currentChannel.value) {
        await select(channels.value[0]?.id ?? null);
    }
}

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
    pendingDrop.value = null;
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

    // The top is getting close: fetch what comes before ahead of reaching it,
    // so scrolling up does not stop dead on a blank wall.
    if (element.scrollTop < 120) void fetchOlder();
}

/**
 * Goes back one page, giving the reader their place back.
 *
 * Adding lines above what is being looked at pushes the view down by as much:
 * without a correction, the thumb reaches the top and the screen jumps
 * elsewhere. The height is measured before, measured again after, and the
 * difference is handed back to the scroll - what every chat application does.
 */
async function fetchOlder() {
    const element = scroller.value;
    if (!element || loadingOlder.value || !hasOlder.value) return;

    const before = element.scrollHeight;
    const gained = await loadOlder();

    if (!gained) return;

    await nextTick();
    element.scrollTop += element.scrollHeight - before;
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

/** The message the address names, marked while it is the one being read. */
const focused = ref(props.focusMessageId);
/** Whether it still has to be scrolled to: once, on the first layout that can. */
let focusPending = null !== props.focusMessageId;

/**
 * Scrolls the named message into the middle of the box, once.
 *
 * Asked on the same layout events as the end of the conversation, for the
 * reason given above: before the box has a height, there is nothing to scroll.
 * Returns whether it did, so the caller does not then jump to the end.
 */
function revealFocus() {
    if (!focusPending || !scroller.value || 0 === scroller.value.clientHeight) return false;

    const target = scroller.value.querySelector(`[data-message-id="${focused.value}"]`);
    focusPending = false;

    if (!target) return false;

    following.value = false;
    target.scrollIntoView?.({ block: "center" });

    return true;
}

// Another room, another reading: the mark belongs to the room it was found in.
watch(currentChannel, () => {
    focused.value = null;
    focusPending = false;
});

onMounted(() => {
    if (typeof ResizeObserver === "undefined") {
        void nextTick().then(() => revealFocus() || toBottom());

        return;
    }

    observer = new ResizeObserver(() => {
        if (revealFocus()) return;
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
        class="relative flex flex-col overflow-hidden rounded-lg border border-line bg-surface-2/20 md:flex-row"
        :class="fill ? 'h-full' : 'h-[32rem]'"
    >
        <SpaceChatChannels
            v-if="hasRail"
            :channels="channels"
            :current="currentChannel"
            :create-path="channelCreatePath"
            :people="people"
            :direct-path="chatDirectPath"
            :open="railOpen"
            v-on:select="select"
            v-on:ask-create="newChannel = true"
            v-on:ask-direct="peopleFor = 'direct'"
            v-on:close="railOpen = false"
        />

        <!-- `min-w-0` on the column: without it a message of a single very
             long word pushes the conversation past the box and the rail is
             the one that gets crushed. -->
        <div class="flex min-h-0 min-w-0 flex-1 flex-col">
            <header class="flex items-center gap-2 border-b border-line px-2 py-2.5 sm:px-4">
                <!-- On a phone the title is the drawer's handle: the room's
                     name is what one touches to change rooms, which saves a
                     button and says where the gesture leads. From `md` it is
                     a title again, the list being already beside it. -->
                <button
                    v-if="hasRail"
                    type="button"
                    class="flex items-center gap-1.5 rounded-md py-1.5 -my-1.5 text-sm font-medium text-primary md:pointer-events-none md:py-0 md:my-0"
                    :aria-expanded="railOpen"
                    v-on:click="railOpen = !railOpen"
                >
                    <PanelLeft class="h-3.5 w-3.5 md:hidden" :stroke-width="2" />
                    {{ openChannel?.name ?? t("shared.space_chat.title") }}
                </button>

                <h2 v-else class="text-sm font-medium text-primary">
                    {{ openChannel?.name ?? t("shared.space_chat.title") }}
                </h2>

                <!-- Said in the header and not only on the badge: it is the
                 sentence to read before writing, and the header is where the
                 eye comes back between two messages.

                 Not on a private conversation: "internal" would answer a
                 question nobody asks there, and the sentence under the box
                 already says who reads it. -->
                <span
                    v-if="channelCreatePath && openChannel && !openChannel.openToClient && !openChannel.isDirect"
                    class="rounded bg-surface-2/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-muted"
                >
                    {{ t("shared.space_chat.channels.internal") }}
                </span>

                <!-- Said, because the difference is invisible otherwise:
                     someone writing in a conversation that has lost the live
                     link deserves to know the other side will not see it
                     appear.

                     **The icon alone.** "reconnexion…" takes a hundred and
                     thirty pixels out of three hundred and sixty, and takes
                     them from the room's name, which wrapped for a word read
                     once an hour. The crossed-out antenna and the colour say
                     the same thing; the word stays in the tooltip and for the
                     screen reader, where it never cost any room. -->
                <span
                    v-if="expectsLive"
                    class="ml-auto shrink-0"
                    :class="live ? 'text-emerald-500' : 'text-amber-500'"
                    :title="liveLabel"
                >
                    <component
                        :is="live ? Radio : WifiOff"
                        class="h-3.5 w-3.5"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    <span class="sr-only">{{ liveLabel }}</span>
                </span>

                <!-- Three dots rather than four buttons under the title: these
                     are rare actions, they have no business taking a line
                     above what one came to read. -->
                <button
                    v-if="openChannel"
                    type="button"
                    class="shrink-0 rounded-md p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                    :class="expectsLive ? '' : 'ml-auto'"
                    :aria-label="t('shared.space_chat.channels.room_settings')"
                    v-on:click="roomModal = true"
                >
                    <MoreHorizontal class="h-4 w-4" :stroke-width="2" />
                </button>
            </header>

            <!-- `flex flex-col` on the scroller, `mt-auto` on the entries: a
             short conversation sits at the bottom of the box rather than
             floating at the top of a large void. When it overflows, the auto
             margin is zero and scrolling is ordinary again. -->
            <div
                ref="scroller"
                class="flex flex-1 flex-col overflow-y-auto px-2 py-3 sm:px-4"
                v-on:scroll="onScroll"
            >
                <!-- A container for the entries, and it is the one observed: its
                 height is the content's, the only measure that says there is
                 something new below the fold. -->
                <div ref="content" class="mt-auto space-y-2">
                    <!-- The only sign that loading older messages is at work.
                         No button: scrolling triggers it by itself, and a
                         button doubling an automatic action casts doubt on
                         both. -->
                    <p v-if="loadingOlder" class="py-1 text-center text-xs text-muted">
                        {{ t("shared.space_chat.channels.older") }}
                    </p>

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
                                {{ formatDate(entry.at, "long") }}
                            </span>
                            <span class="h-px flex-1 bg-line/60" />
                        </div>

                        <!-- The side decides the alignment, the colour follows
                         it. Two signals for the same thing rather than one,
                         because alignment alone gets lost on a one-line
                         message and colour alone gets lost on whoever tells
                         it apart poorly. -->
                        <div
                            v-else
                            class="flex"
                            :class="mine(entry.message) ? 'justify-end' : 'justify-start'"
                            :data-message-id="entry.message.id"
                        >
                            <!-- The ring marks the message a search result
                                 led here, so the eye lands on it. -->
                            <div
                                class="group max-w-[min(42rem,80%)] rounded-lg px-3 py-2"
                                :class="[
                                    mine(entry.message)
                                        ? 'border border-accent-500/20 bg-accent-500/5'
                                        : 'bg-surface-2/60',
                                    focused === entry.message.id ? 'ring-2 ring-accent-500/60' : '',
                                ]"
                            >
                                <!-- `flex-wrap` and not one line: a name, a
                                     date and the "client" badge take 130
                                     pixels, and a 250-pixel window pushed
                                     them out of the bubble - the badge left
                                     the screen on the right. They wrap
                                     rather than overflow. -->
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <!-- The name stays on both sides: a studio has
                                     several people, and "who answered" is a
                                     question one asks on one's own side too. -->
                                    <span class="min-w-0 break-all text-xs font-medium text-primary">
                                        {{ entry.message.author }}
                                    </span>
                                    <span class="text-xs text-muted">
                                        {{ formatDate(new Date(entry.message.createdAt), "short") }}
                                    </span>
                                    <!-- Only when it tells something: on their
                                     own page, the client does not need to be
                                     told they are the client. -->
                                    <span
                                        v-if="entry.message.fromClient && 'studio' === ownSide"
                                        class="text-xs text-accent-500"
                                    >
                                        {{ t("shared.space_chat.from_client") }}
                                    </span>
                                    <button
                                        v-if="deletePath && !entry.message.fromClient"
                                        type="button"
                                        class="ml-auto rounded p-1 text-muted transition-opacity hover:text-red-500 sm:opacity-0 sm:group-hover:opacity-100 touch:opacity-100"
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

            <div v-if="canPost" class="space-y-2 border-t border-line px-2 py-3 sm:px-4">
                <!-- On the wrapper rather than on the field: `AppTextarea` is a
                 label, a control and a hint under one element, and hanging a
                 key handler on the component would rely on which of them
                 Vue happens to pass it to. Keystrokes bubble. -->
                <div v-on:keydown="onKeydown">
                    <AppTextarea
                        :model-value="draft"
                        :placeholder="t('shared.space_chat.placeholder')"
                        :hint="roomNotice"
                        :rows="2"
                        v-on:update:model-value="draft = $event"
                    />
                </div>
                <!-- Full width under the thumb, its own size as soon as there
                     is room: on a phone the area's only action deserves the
                     whole line rather than a ninety-pixel button stuck in a
                     corner. -->
                <div class="flex items-center justify-end gap-3">
                    <!-- The shortcut, where it exists. It used to live in
                         parentheses in the field itself, where it made the
                         sentence read before writing sixty characters longer;
                         it now fills the empty space left of the button,
                         which served no purpose, and it stays silent on
                         screens without a keyboard. -->
                    <p class="mr-auto hidden text-xs text-muted md:block">
                        {{ t("shared.space_chat.shortcut_hint") }}
                    </p>

                    <AppButton
                        class="w-full sm:w-auto"
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

        <SpaceChatRoomModal
            :show="roomModal"
            :channel="openChannel"
            :can-arrange="!!channelCreatePath"
            :can-set-audience="!!channelAudiencePath"
            :can-invite="!!channelInvitePath"
            :can-uninvite="!!channelUninvitePath"
            :can-hide="!!hidePath"
            v-on:close="roomModal = false"
            v-on:rename="rename"
            v-on:audience="setAudience"
            v-on:invite="peopleFor = 'invite'"
            v-on:remove-member="removeMember"
            v-on:delete="
                roomModal = false;
                pendingDrop = $event;
            "
            v-on:hide="onHide"
        />

        <AppModal
            :show="!!pendingDrop"
            max-width="sm"
            :closeable="false"
            :title="pendingDrop?.name ?? ''"
            :icon="Trash2"
            v-on:close="pendingDrop = null"
        >
            <p class="text-sm text-primary">
                {{ t("shared.space_chat.channels.delete_confirm") }}
            </p>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDrop = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="onDrop(pendingDrop)">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.space_chat.channels.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <SpaceChatNewChannelModal
            :show="newChannel"
            :can-show-to-client="!!channelAudiencePath"
            v-on:close="newChannel = false"
            v-on:create="create"
        />

        <SpaceChatPeopleModal
            :show="!!peopleFor"
            :title="pickTitle"
            :people="peopleChoices"
            :purpose="peopleFor"
            v-on:close="peopleFor = null"
            v-on:pick="onPick"
        />
    </section>
</template>
