<script setup>
/**
 * The comments of a note, beside it (09/10/2026), as Notion and Google Docs
 * keep them: a thread per passage, its replies, settled when done.
 *
 * The same panel in the suite and on a share page that writes. A guest has
 * no account: they give a name, kept in their browser, and cannot settle or
 * remove anything.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Check, MessageSquare, RotateCcw, Trash2, X } from "lucide-vue-next";
import AppButton from "@shared/components/action/AppButton.vue";
import AppIconButton from "@shared/components/action/AppIconButton.vue";
import AppInput from "@shared/components/form/input/AppInput.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { foldText } from "@notes/suite/markdown/composables/noteEmoji.js";
import { mentionMarkup } from "@notes/suite/markdown/composables/markedExtensions/markedMentions.js";

const props = defineProps({
    threads: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    /** The passage about to be commented, picked in the note; null for a general comment. */
    quote: { type: String, default: null },
    /** Whoever writes the note settles and removes any thread. */
    canModerate: { type: Boolean, default: false },
    /** A guest of a share page: asked for a name, never settles nor removes. */
    guest: { type: Boolean, default: false },
    /** Who `@` can mention: `[{id, name}]`. Empty for a guest. */
    people: { type: Array, default: () => [] },
    /** Ids of threads whose passage is no longer found in the note. */
    missing: { type: Object, default: () => new Set() },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "submit", "resolve", "delete", "focus-quote", "clear-quote"]);

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const GUEST_NAME_KEY = "aurora.notes.guestName";
const draft = ref("");
const replyTo = ref(null);
const replyDraft = ref("");
const showResolved = ref(false);
const guestName = ref("");
const composer = ref(null);

try {
    guestName.value = localStorage.getItem(GUEST_NAME_KEY) ?? "";
} catch {
    guestName.value = "";
}

watch(
    () => props.quote,
    (quote) => {
        if (quote) composer.value?.focus();
    },
);

const open = computed(() => props.threads.filter((thread) => !thread.resolvedAt));
const resolved = computed(() => props.threads.filter((thread) => thread.resolvedAt));

/** A comment's text in pieces: plain words, and the people it mentions. */
function segments(body) {
    const parts = [];
    const pattern = /@\[([^\]\n]{1,80})\]\(user:(\d{1,10})\)/g;
    let last = 0;
    for (const match of String(body ?? "").matchAll(pattern)) {
        if (match.index > last) parts.push({ text: body.slice(last, match.index) });
        parts.push({ mention: match[1] });
        last = match.index + match[0].length;
    }
    if (last < String(body ?? "").length) parts.push({ text: body.slice(last) });

    return parts;
}

/** `@` in a comment, the same way as in a note: the last word after `@` narrows the list. */
const mentionQuery = ref(null);
const mentionTarget = ref("draft");
const mentionIndex = ref(0);
const mentionChoices = computed(() => {
    if (null === mentionQuery.value) return [];
    const wanted = foldText(mentionQuery.value);

    return props.people.filter((person) => foldText(person.name ?? "").includes(wanted)).slice(0, 6);
});

function onType(event, target) {
    if (props.guest) return;
    const before = event.target.value.slice(0, event.target.selectionStart);
    const match = /(?:^|\s)@([\p{L}\p{N}._-]*)$/u.exec(before);
    mentionTarget.value = target;
    mentionQuery.value = match ? match[1] : null;
    mentionIndex.value = 0;
}

function pickMention(person) {
    const field = "draft" === mentionTarget.value ? draft : replyDraft;
    field.value = field.value.replace(/@([\p{L}\p{N}._-]*)$/u, `${mentionMarkup(person)} `);
    mentionQuery.value = null;
}

function rememberName() {
    try {
        localStorage.setItem(GUEST_NAME_KEY, guestName.value.trim());
    } catch {
        // Asked again next time.
    }
}

function send() {
    const body = draft.value.trim();
    if ("" === body || props.saving) return;
    if (props.guest) rememberName();
    emit("submit", { body, quote: props.quote, parentId: null, guestName: guestName.value.trim() });
    draft.value = "";
    mentionQuery.value = null;
}

function sendReply(thread) {
    const body = replyDraft.value.trim();
    if ("" === body || props.saving) return;
    if (props.guest) rememberName();
    emit("submit", { body, quote: null, parentId: thread.id, guestName: guestName.value.trim() });
    replyDraft.value = "";
    replyTo.value = null;
    mentionQuery.value = null;
}

function onComposerKeydown(event, submit) {
    // The selector, as the editor's: arrows move, Enter or Tab picks, Escape closes.
    if (mentionChoices.value.length) {
        if ("ArrowDown" === event.key || "ArrowUp" === event.key) {
            event.preventDefault();
            const step = "ArrowDown" === event.key ? 1 : -1;
            mentionIndex.value = (mentionIndex.value + step + mentionChoices.value.length) % mentionChoices.value.length;

            return;
        }
        if (("Enter" === event.key && !event.metaKey && !event.ctrlKey) || "Tab" === event.key) {
            event.preventDefault();
            pickMention(mentionChoices.value[mentionIndex.value]);

            return;
        }
    }
    if ("Escape" === event.key && null !== mentionQuery.value) {
        event.stopPropagation();
        mentionQuery.value = null;

        return;
    }
    if ("Enter" === event.key && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        submit();
    }
}

function canTouch(comment) {
    return !props.guest && (props.canModerate || comment.mine);
}
</script>

<template>
    <aside
        data-note-comments
        class="fixed inset-0 z-40 flex flex-col bg-surface md:relative md:inset-auto md:z-auto md:w-80 md:shrink-0 md:border-l md:border-line md:bg-surface-2/30"
    >
        <header class="flex items-center justify-between gap-2 border-b border-line p-3">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-primary">
                <MessageSquare class="h-4 w-4" :stroke-width="2" />
                {{ t('notes.markdown.comments.title') }}
                <span v-if="open.length" class="text-xs font-normal text-muted">{{ open.length }}</span>
            </h3>
            <AppIconButton :title="t('notes.markdown.links.close')" v-on:click="emit('close')">
                <X class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
        </header>

        <div class="flex flex-col gap-2 border-b border-line p-3">
            <AppInput
                v-if="guest"
                v-model="guestName"
                data-note-comment-guest-name
                :placeholder="t('notes.markdown.comments.guest_name')"
                :aria-label="t('notes.markdown.comments.guest_name')"
            />
            <blockquote
                v-if="quote"
                data-note-comment-quote
                class="m-0 flex items-start gap-2 border-l-2 border-amber-500 pl-2 text-xs text-secondary"
            >
                <span class="line-clamp-3 flex-1">{{ quote }}</span>
                <button type="button" class="shrink-0 text-muted hover:text-primary" :aria-label="t('notes.markdown.cancel')" v-on:click="emit('clear-quote')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                </button>
            </blockquote>
            <!-- The house textarea's look, as a plain field: the `@` menu
                 needs the caret, and Cmd+Enter the keys. -->
            <div class="relative">
                <textarea
                    ref="composer"
                    v-model="draft"
                    data-note-comment-composer
                    rows="3"
                    class="block w-full resize-y rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary placeholder-muted transition focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                    :placeholder="guest ? t('notes.markdown.comments.placeholder_guest') : t('notes.markdown.comments.placeholder')"
                    v-on:input="onType($event, 'draft')"
                    v-on:keydown="onComposerKeydown($event, send)"
                />
                <ul
                    v-if="'draft' === mentionTarget && mentionChoices.length"
                    class="absolute left-0 right-0 top-full z-10 m-0 mt-1 list-none rounded-md border border-line bg-surface p-1 shadow-lg"
                >
                    <li v-for="(person, position) in mentionChoices" :key="person.id">
                        <button
                            type="button"
                            class="w-full rounded px-2 py-1 text-left text-sm hover:bg-surface-2"
                            :class="position === mentionIndex ? 'bg-surface-2 text-primary' : 'text-secondary'"
                            v-on:mouseenter="mentionIndex = position"
                            v-on:click="pickMention(person)"
                        >
                            @{{ person.name }}
                        </button>
                    </li>
                </ul>
            </div>
            <AppButton
                class="self-end"
                size="sm"
                data-note-comment-send
                :disabled="saving || '' === draft.trim()"
                v-on:click="send"
            >
                {{ t('notes.markdown.comments.send') }}
            </AppButton>
        </div>

        <div class="flex-1 overflow-y-auto p-3">
            <p v-if="loading && 0 === threads.length" class="py-6 text-center text-sm text-muted">…</p>
            <p v-else-if="0 === open.length && 0 === resolved.length" class="py-6 text-center text-sm text-muted">
                {{ t('notes.markdown.comments.empty_list') }}
            </p>

            <template v-for="list in [open, showResolved ? resolved : []]" :key="list === open ? 'open' : 'resolved'">
                <article
                    v-for="thread in list"
                    :key="thread.id"
                    data-note-comment-thread
                    :data-note-comment-thread-id="thread.id"
                    class="mb-3 rounded-lg border border-line bg-surface p-3"
                    :class="thread.resolvedAt ? 'opacity-70' : ''"
                >
                    <button
                        v-if="thread.quote"
                        type="button"
                        class="mb-2 block w-full border-l-2 border-amber-500 pl-2 text-left text-xs text-secondary hover:text-primary"
                        v-on:click="emit('focus-quote', thread.id)"
                    >
                        <span class="line-clamp-2">{{ thread.quote }}</span>
                        <span v-if="missing.has(thread.id)" class="mt-0.5 block italic text-muted">{{ t('notes.markdown.comments.quote_missing') }}</span>
                    </button>

                    <div v-for="comment in [thread, ...thread.replies]" :key="comment.id" class="group/comment mt-2 first-of-type:mt-0">
                        <div class="flex items-baseline gap-2">
                            <span class="text-sm font-medium text-primary">{{ comment.authorName }}</span>
                            <span class="text-2xs text-muted">{{ formatDateTime(comment.createdAt) }}</span>
                            <button
                                v-if="canTouch(comment)"
                                type="button"
                                class="ml-auto text-muted opacity-0 transition-opacity hover:text-danger group-hover/comment:opacity-100 focus:opacity-100"
                                :title="t('notes.markdown.comments.delete')"
                                :aria-label="t('notes.markdown.comments.delete')"
                                v-on:click="emit('delete', comment.id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                            </button>
                        </div>
                        <p class="m-0 whitespace-pre-wrap break-words text-sm text-secondary">
                            <template v-for="(part, index) in segments(comment.body)" :key="index">
                                <span v-if="part.mention" class="rounded bg-accent-500/15 px-1 font-medium text-accent-600 dark:text-accent-300">@{{ part.mention }}</span>
                                <template v-else>{{ part.text }}</template>
                            </template>
                        </p>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button
                            v-if="replyTo !== thread.id"
                            type="button"
                            class="text-xs text-muted hover:text-primary"
                            v-on:click="replyTo = thread.id"
                        >
                            {{ t('notes.markdown.comments.reply') }}
                        </button>
                        <button
                            v-if="canTouch(thread)"
                            type="button"
                            data-note-comment-resolve
                            class="ml-auto inline-flex items-center gap-1 text-xs text-muted hover:text-primary"
                            v-on:click="emit('resolve', { id: thread.id, resolved: !thread.resolvedAt })"
                        >
                            <RotateCcw v-if="thread.resolvedAt" class="h-3.5 w-3.5" :stroke-width="2" />
                            <Check v-else class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ thread.resolvedAt ? t('notes.markdown.comments.reopen') : t('notes.markdown.comments.resolve') }}
                        </button>
                    </div>

                    <div v-if="replyTo === thread.id" class="relative mt-2 flex flex-col gap-2">
                        <textarea
                            v-model="replyDraft"
                            rows="2"
                            class="block w-full resize-y rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary placeholder-muted transition focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                            :placeholder="t('notes.markdown.comments.reply_placeholder')"
                            v-on:input="onType($event, 'reply')"
                            v-on:keydown="onComposerKeydown($event, () => sendReply(thread))"
                        />
                        <ul
                            v-if="'reply' === mentionTarget && mentionChoices.length"
                            class="absolute left-0 right-0 top-full z-10 m-0 mt-1 list-none rounded-md border border-line bg-surface p-1 shadow-lg"
                        >
                            <li v-for="(person, position) in mentionChoices" :key="person.id">
                                <button
                                    type="button"
                                    class="w-full rounded px-2 py-1 text-left text-sm hover:bg-surface-2"
                                    :class="position === mentionIndex ? 'bg-surface-2 text-primary' : 'text-secondary'"
                                    v-on:mouseenter="mentionIndex = position"
                                    v-on:click="pickMention(person)"
                                >
                                    @{{ person.name }}
                                </button>
                            </li>
                        </ul>
                        <div class="flex justify-end gap-2">
                            <AppButton variant="ghost" size="sm" v-on:click="replyTo = null">{{ t('notes.markdown.cancel') }}</AppButton>
                            <AppButton size="sm" :disabled="saving || '' === replyDraft.trim()" v-on:click="sendReply(thread)">
                                {{ t('notes.markdown.comments.reply') }}
                            </AppButton>
                        </div>
                    </div>
                </article>
            </template>

            <button
                v-if="resolved.length"
                type="button"
                class="w-full text-center text-xs text-muted hover:text-primary"
                v-on:click="showResolved = !showResolved"
            >
                {{ showResolved ? t('notes.markdown.comments.hide_resolved') : t('notes.markdown.comments.show_resolved', { count: resolved.length }) }}
            </button>
        </div>
    </aside>
</template>
