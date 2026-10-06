<script setup>
/**
 * The rail: which rooms this reader has, and which one is open.
 *
 * **Down the left, like every application people already use for this.** A
 * horizontal strip was the first shape and it was wrong twice over: it grows
 * sideways until it wraps into the conversation, and it puts the rooms on the
 * same line as the room's own name, so nothing says which of the two is the
 * list and which is the place you are. A column says it by position alone.
 *
 * **Under `md` it becomes a drawer rather than a row.** A strip above the
 * conversation was the second shape and it was wrong too: it takes height from
 * the one thing a phone screen has too little of, and the list is not something
 * you read - it is something you open, pick from, and close. So it slides in
 * over the conversation, from the side it lives on everywhere else, and shuts
 * as soon as a room is chosen.
 *
 * Same component, two arrangements: two components would be two lists to keep
 * in step.
 *
 * **Drawn on both sides, controllable on one.** The client sees the rooms that
 * were opened to them and switches between them; the button that opens a room
 * is bound to a path the client's page is never handed.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Eye, EyeOff, Hash, MessageCircle, Plus } from "lucide-vue-next";

const props = defineProps({
    channels: { type: Array, default: () => [] },
    current: { type: [Number, null], default: null },
    /** Null on the client's page: only the studio opens rooms. */
    createPath: { type: String, default: null },
    /** Who a private conversation can be opened with. Empty when nobody. */
    people: { type: Array, default: () => [] },
    /** Null when this reader may not start one. */
    directPath: { type: String, default: null },
    /** Whether the drawer is out. Ignored from `md` up, where the rail is always there. */
    open: { type: Boolean, default: false },
});

const emit = defineEmits(["select", "close", "ask-create", "ask-direct"]);

/**
 * Choosing a room closes the drawer.
 *
 * On a phone the list covers what it is choosing for, so leaving it open after
 * a pick would hide the answer behind the question. From `md` up nothing
 * listens to this, because nothing was covered.
 */
function choose(id) {
    emit("select", id);
    emit("close");
}

const { t } = useI18n();

const canArrange = computed(() => !!props.createPath);

/**
 * Two lists, because they are two things.
 *
 * A channel is a room one walks into, a private conversation is someone one
 * talks to. The mechanism underneath is the same - a room for two - and that is
 * deliberately invisible here: nobody thinks "the room for two with Marie".
 */
const rooms = computed(() => props.channels.filter((channel) => !channel.isDirect));
const directs = computed(() => props.channels.filter((channel) => channel.isDirect));

/** Those there is not already an open conversation with. */
const reachable = computed(() => {
    const already = new Set(directs.value.map((channel) => channel.name));

    return props.people.filter((person) => !already.has(person.label));
});

</script>

<template>
    <!-- The veil only takes the mouse when the drawer is out, and it
         disappears entirely from `md`: otherwise it would cover a
         conversation that no drawer hides. -->
    <div
        v-if="open"
        class="absolute inset-0 z-10 bg-black/50 md:hidden"
        v-on:click="emit('close')"
    />

    <aside
        class="absolute inset-y-0 left-0 z-20 flex w-56 shrink-0 flex-col gap-2 border-r border-line bg-surface p-3 shadow-xl transition-transform duration-200 md:static md:w-52 md:translate-x-0 md:bg-transparent md:shadow-none"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
        <!-- The heading disappears on a phone: a left column needs to be told
             what it is, a row of pills above the conversation reads without
             it. -->
        <span class="px-1 text-[0.65rem] font-medium uppercase tracking-wider text-muted">
            {{ t("shared.space_chat.channels.label") }}
        </span>

        <!-- A single scrolling line rather than a block that wraps: on a
             screen 812 pixels high, three wrapped channels took 107 pixels
             from the conversation, which is what one came to read. -->
        <div class="flex flex-col gap-0.5 overflow-y-auto">
            <button
                v-for="channel in rooms"
                :key="channel.id"
                type="button"
                class="flex w-full shrink-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-xs transition-colors"
                :class="
                    channel.id === current
                        ? 'bg-accent/15 text-accent'
                        : 'text-secondary hover:bg-surface-2/60 hover:text-primary'
                "
                v-on:click="choose(channel.id)"
            >
                <Hash class="h-3 w-3 shrink-0" :stroke-width="2" />
                <span class="truncate">{{ channel.name }}</span>

                <!-- Said on the channel's line, not only in its settings:
                     what is typed there does not leave the agency, and that
                     is worth knowing before writing rather than after.

                     **Both states, and no longer just one.** Only the
                     crossed-out eye was drawn: a channel open to the client
                     was inferred from a missing icon, which is easy to
                     confuse with an icon one did not see. An open door is
                     signalled as much as a closed one. -->
                <component
                    :is="channel.openToClient ? Eye : EyeOff"
                    v-if="canArrange && !channel.isDirect"
                    class="ml-auto h-3 w-3 shrink-0"
                    :class="channel.openToClient ? 'text-emerald-500 opacity-80' : 'opacity-60'"
                    :stroke-width="2"
                    :aria-label="t(channel.openToClient
                        ? 'shared.space_chat.channels.client_reads'
                        : 'shared.space_chat.channels.internal')"
                />
            </button>

            <!-- Asks, does not open: the name is given in a modal, for the
                 same reasons as picking a person just below. -->
            <button
                v-if="canArrange"
                type="button"
                class="flex w-full shrink-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-xs text-muted transition-colors hover:bg-surface-2/60 hover:text-primary"
                v-on:click="
                    emit('ask-create');
                    emit('close');
                "
            >
                <Plus class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t("shared.space_chat.channels.new") }}
            </button>
        </div>

        <template v-if="directPath && (directs.length || reachable.length)">
            <span
                class="mt-2 px-1 text-[0.65rem] font-medium uppercase tracking-wider text-muted"
            >
                {{ t("shared.space_chat.channels.directs") }}
            </span>

            <div class="flex flex-col gap-0.5 overflow-y-auto">
                <button
                    v-for="channel in directs"
                    :key="channel.id"
                    type="button"
                    class="flex w-full shrink-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-xs transition-colors"
                    :class="
                        channel.id === current
                            ? 'bg-accent/15 text-accent'
                            : 'text-secondary hover:bg-surface-2/60 hover:text-primary'
                    "
                    v-on:click="choose(channel.id)"
                >
                    <MessageCircle class="h-3 w-3 shrink-0" :stroke-width="2" />
                    <span class="truncate">{{ channel.name }}</span>
                </button>

                <!-- The question is asked by a modal, not by the rail. A
                     list of names unfolding here pushed the rooms down, in
                     two hundred pixels of width, at the very moment one is
                     looking for a name; and on a phone it opened inside a
                     drawer that already covers the conversation. The rail
                     asks, the panel opens, and the drawer closes. -->
                <button
                    v-if="reachable.length"
                    type="button"
                    class="flex w-full shrink-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-xs text-muted transition-colors hover:bg-surface-2/60 hover:text-primary"
                    v-on:click="
                        emit('ask-direct');
                        emit('close');
                    "
                >
                    <Plus class="h-3 w-3 shrink-0" :stroke-width="2" />
                    {{ t("shared.space_chat.channels.new_direct") }}
                </button>
            </div>
        </template>
    </aside>
</template>
