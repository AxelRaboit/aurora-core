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
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Radio, Send, Trash2, WifiOff } from "lucide-vue-next";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import { useSpaceChat } from "./composables/useSpaceChat.js";

const props = defineProps({
    messages: { type: Array, default: () => [] },
    postPath: { type: String, default: null },
    reloadPath: { type: String, required: true },
    /** Null on the client's page: only the studio removes its own messages. */
    deletePath: { type: String, default: null },
    /** Null when no hub is running, and then nothing tries to connect. */
    streamUrl: { type: String, default: null },
    /** Shown above the box: who reads what is typed here. */
    notice: { type: String, default: "" },
});

const { t, d } = useI18n();

const { messages, loading, live, expectsLive, post, remove } = useSpaceChat(
    props.messages,
    {
        postPath: props.postPath,
        reloadPath: props.reloadPath,
        deletePath: props.deletePath,
        streamUrl: props.streamUrl,
    },
);

const draft = ref("");
const scroller = ref(null);

const canPost = computed(() => !!props.postPath);

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

/**
 * Whether the reader is at the bottom, asked before the list grows.
 *
 * A chat that jumps to the newest message while somebody is reading last
 * week's is a chat they cannot read. So it only follows when they were already
 * following.
 */
function atBottom() {
    const element = scroller.value;
    if (!element) return true;

    return (
        element.scrollHeight - element.scrollTop - element.clientHeight < 80
    );
}

function toBottom() {
    if (scroller.value) {
        scroller.value.scrollTop = scroller.value.scrollHeight;
    }
}

/**
 * Opened at the bottom, like every conversation ever written.
 *
 * The watcher below only follows the list as it grows, which says nothing
 * about the first paint: a space with two days of history opened on its
 * oldest message, with the newest hidden under the fold. What somebody wants
 * on arriving is the last thing that was said.
 *
 * **Three attempts, and each one is for a different reason the first can
 * fail.** At `nextTick` the rows exist but the box around them may not have
 * its height yet, and scrolling a box that is not yet scrollable does nothing.
 * A frame later it does. And a web font landing after that rewraps the text,
 * which lengthens the list under a reader who was already at the bottom.
 */
onMounted(async () => {
    await nextTick();
    toBottom();

    requestAnimationFrame(toBottom);

    document.fonts?.ready.then(toBottom).catch(() => {});
});

watch(
    () => messages.value.length,
    async (now, before) => {
        if (now <= before) return;

        const follow = atBottom();
        await nextTick();

        if (follow) {
            toBottom();
        }
    },
);

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
    <section class="flex h-[32rem] flex-col rounded-lg border border-line/60 bg-surface-2/20">
        <header class="flex items-center gap-2 border-b border-line/60 px-4 py-2.5">
            <h2 class="text-sm font-medium text-primary">
                {{ t("shared.space_chat.title") }}
            </h2>

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

        <div ref="scroller" class="flex-1 space-y-2 overflow-y-auto px-4 py-3">
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

                <div
                    v-else
                    class="group max-w-[42rem] rounded-lg px-3 py-2"
                    :class="
                        entry.message.fromClient
                            ? 'border border-accent-500/20 bg-accent-500/5'
                            : 'bg-surface-2/60'
                    "
                >
                    <div class="flex items-baseline gap-2">
                        <span class="text-xs font-medium text-primary">
                            {{ entry.message.author }}
                        </span>
                        <span class="text-xs text-muted">
                            {{ d(new Date(entry.message.createdAt), "short") }}
                        </span>
                        <span
                            v-if="entry.message.fromClient"
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
            </template>
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
    </section>
</template>
