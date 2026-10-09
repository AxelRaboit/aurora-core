<script setup>
import "@notes/suite/markdown/components/preview.css";
import "@notes/share/appearance.css";
import "@notes/share/print.css";

import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { CircleHelp, Clock, Columns, Eye, Pencil } from "lucide-vue-next";
import NoteMarkdownHelp from "@notes/suite/markdown/components/NoteMarkdownHelp.vue";
import NoteHoverCard from "@notes/suite/markdown/components/NoteHoverCard.vue";
import { noteExcerpt, useWikiLinkHoverCard } from "@notes/suite/markdown/composables/useWikiLinkHoverCard.js";
import AppButton from "@shared/components/action/AppButton.vue";
import AppBadge from "@shared/components/feedback/AppBadge.vue";
import AppTab from "@shared/components/nav/AppTab.vue";
import { useMediaQuery } from "@/shared/composables/useMediaQuery.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpStatus } from "@/shared/utils/http/HttpStatus.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useMarkdownRenderer } from "@notes/suite/markdown/composables/useMarkdownRenderer.js";
import { useFootnoteLabels, useNoteHtmlEnhancer } from "@notes/suite/markdown/composables/useNoteHtmlEnhancer.js";
import { markdownSection } from "@notes/suite/markdown/composables/noteHtmlEnhancer.js";
import { useEditorPaneMode } from "@notes/suite/markdown/composables/useEditorPaneMode.js";
import { useNoteLive } from "@notes/suite/markdown/composables/useNoteLive.js";
import { useNoteCoedit } from "@notes/suite/markdown/composables/useNoteCoedit.js";
import { canCoedit } from "@notes/suite/markdown/composables/noteCoeditProtocol.js";
import NoteCollaborators from "@notes/suite/markdown/components/NoteCollaborators.vue";
import NoteRemoteCarets from "@notes/suite/markdown/components/NoteRemoteCarets.vue";
import NoteReaderOutline from "@notes/suite/markdown/components/NoteReaderOutline.vue";
import { outlineOf, readingMinutes, wordCount } from "@notes/suite/markdown/composables/noteOutline.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { shareHtml } from "@notes/share/useSharedNoteHtml.js";
import { shareEditorModes, shareEditorView } from "@notes/share/shareEditorView.js";
import { withoutLeadingTitle } from "@notes/suite/markdown/composables/noteBody.js";
import { lightWhilePrinting } from "@notes/share/useNotePrint.js";

const props = defineProps({
    imagePrefix: { type: String, required: true },
    shareImagePath: { type: String, required: true },
    shareNotePath: { type: String, required: true },
    noteId: { type: Number, required: true },
    noteTitle: { type: String, default: "" },
    content: { type: String, default: "" },
    /** list<{id, title}> - every note of the share, titles only. */
    tree: { type: Array, default: () => [] },
    /** lower-cased title -> id, for resolving `[[links]]` inside the share. */
    titleIndex: { type: Object, default: () => ({}) },
    /**
     * The note's banner: `{url, creditName, creditUrl, position}`.
     *
     * The image stays with whoever hosts it; we only keep its address. The
     * credit goes with it because the licence requires it, and because there
     * is no longer a media library record to carry it.
     */
    cover: { type: Object, default: null },
    /** {@see NoteAppearanceEnum} - the note's background and its ink. */
    appearance: { type: String, default: "plain" },
    /**
     * What else the note is made of, for the share page: `{tags, updatedAt}`.
     *
     * A shared note showed its banner, title and text, and none of the rest
     * the suite shows (09/10/2026): its tags, when it last changed, how long
     * it reads, and its outline on a wide screen. Null where the page around
     * already says it - the public reader has its own bar.
     */
    meta: { type: Object, default: null },
    /**
     * `{icon, properties, fullWidth, smallText, font}`, as
     * `MarkdownNoteDisplay` describes it (09/10/2026).
     */
    display: { type: Object, default: null },
    /**
     * Whether this link may rewrite this note.
     *
     * Decided by the server from the link alone, and about this note only:
     * a share carrying linked notes stays read-only on all of them. The page
     * is told, it never works it out.
     */
    canWrite: { type: Boolean, default: false },
    saveNotePath: { type: String, default: "" },
    /** The note's version as the page was served: what a save starts from. */
    noteVersion: { type: Number, default: null },
    /**
     * Whether the link opens live co-editing on its note: ticked on the link,
     * and only ever on one that writes. The server's word, like `canWrite`.
     */
    coediting: { type: Boolean, default: false },
    /** Where a guest beats to be in the room, `__id__` template. */
    liveBeatPath: { type: String, default: "" },
});

const { t } = useI18n();
const { render } = useMarkdownRenderer({ footnotes: useFootnoteLabels() });
const { request } = useRequest();

/**
 * Writing, when the link allows it.
 *
 * **The markdown source in a plain field, with its preview beside it.** This
 * page has no account behind it, so it gets the smallest surface that does
 * the job: no image upload, no slash commands, no wiki-link autocomplete.
 * Somebody invited to correct a paragraph needs the text and what it will
 * look like, not the editor - and every feature added here is another thing
 * an unauthenticated endpoint has to be safe about. The preview is rendered
 * in the browser and sends nothing, which is why it passes that bar.
 */
const editing = ref(false);
const draftTitle = ref(props.noteTitle ?? "");
const draftContent = ref(props.content ?? "");
const savedTitle = ref(props.noteTitle ?? "");
const savedContent = ref(props.content ?? "");
const version = ref(props.noteVersion);
const saving = ref(false);

/**
 * How the field and the preview share the screen while writing.
 *
 * The editor's own choice, remembered in the same place, so somebody who
 * usually works in split gets split here too. Only ever shown while editing:
 * a reader who is not writing sees the rendered note, as before. The phone
 * breakpoint is the editor's, and followed live, so turning a tablet over
 * brings split back or takes it away.
 */
const { mode: viewMode } = useEditorPaneMode();
const { matches: isMobile } = useMediaQuery("(max-width: 767px)");
const view = computed(() => shareEditorView(viewMode.value, isMobile.value));
const modeOptions = computed(() =>
    shareEditorModes(isMobile.value).map((value) => ({
        value,
        icon: { edit: Pencil, split: Columns, preview: Eye }[value],
        label: t(`notes.markdown.view.${value}`),
    })),
);

function pickMode(value) {
    viewMode.value = value;
}

/**
 * Somebody else wrote while this page was open.
 *
 * Nothing goes out on its own after that: the text on screen started from a
 * state that no longer exists, and saving it would erase their work without
 * either of them knowing. The page says so and offers to reload.
 *
 * **The draft stays on screen.** Closing the field here used to hide what the
 * guest had typed, and the reload the page offers then lost it for good - a
 * refusal that cost them their paragraph. Saving is what is closed instead:
 * another try would be refused the same way.
 */
const conflicted = ref(false);

/**
 * Writing with the others, letter by letter, when the link opens it.
 *
 * **The back office's own live code, not a copy of it.** The guest beats on
 * the link's route instead of the back office's, and the answer has the same
 * shape - the room, where to listen, the right to publish a caret, who this
 * guest is - so `useNoteLive` and `useNoteCoedit` run here untouched. Two
 * copies of a protocol would be how the guest and the owner drift into
 * sessions that cannot hear each other.
 *
 * **Accounts write back, guests only for a room of guests.** The room elects
 * its lowest id and a guest's is above every account's (the server sees to
 * that), so whenever somebody with an account is on the note, their ordinary
 * save carries the text, three-way merge included. This page's write-back
 * only ever runs for a room with nobody else in it but guests.
 *
 * **Refusing is still the safe answer.** No hub, no answer from the room: the
 * session never starts and the page writes the way it always has, type then
 * save. Half a session would be the one outcome worse than none.
 */
const liveLink = props.coediting && props.canWrite && "" !== props.liveBeatPath;
const liveNoteId = ref(liveLink ? props.noteId : null);
const coeditLive = ref(false);

const {
    people: roomPeople,
    cursors: roomCursors,
    live: roomStreaming,
    publishCursor,
    channel: roomChannel,
} = useNoteLive({
    noteId: liveNoteId,
    editing,
    beatPath: props.liveBeatPath,
});

const coeditAllowed = computed(
    () =>
        liveLink &&
        canCoedit({
            spaceAllows: roomChannel.coeditable.value,
            canWrite: props.canWrite,
            hasChannel: roomChannel.ready.value,
            selfUserId: roomChannel.selfUserId(),
        }),
);

useNoteCoedit({
    noteId: liveNoteId,
    allowed: coeditAllowed,
    live: coeditLive,
    text: computed(() => draftContent.value),
    applyText: (value) => {
        draftContent.value = value;
    },
    title: computed(() => draftTitle.value),
    applyTitle: (value) => {
        draftTitle.value = value;
    },
    room: roomPeople,
    channel: roomChannel,
    writeBack: (markdown, sharedTitle) => writeBackForTheRoom(markdown, sharedTitle),
});

/**
 * The room's text, saved for everybody, when this guest is the one elected.
 *
 * Through the link's write route, flagged as a session's so it counts against
 * the session's limit rather than the one meant for a guest pressing Save. The
 * title is the room's too; when the session hands none back (an empty one,
 * see `useNoteCoedit`), the stored title goes back unchanged.
 *
 * **A conflict here means the note was saved from outside the room** - by a
 * restore, say, since every account that opens a co-editable note joins it.
 * The room's text is what everybody in it is looking at, so it is written
 * again on top of the version the server answered with: what it replaces is
 * still in the history, kept by the save that caused the conflict.
 */
async function writeBackForTheRoom(markdown, sharedTitle = null, retried = false) {
    const title = sharedTitle ?? savedTitle.value;
    const payload = await request(
        props.saveNotePath,
        {
            title,
            content: markdown,
            version: version.value,
            coedit: true,
        },
        // Nobody pressed anything: a failure here shows nothing, and the next
        // pause in the typing writes again.
        { accept: [HttpStatus.TooManyRequests], silent: true, noGuard: true },
    );

    // Says whether it wrote, so the session tries again after a failure
    // rather than believing the text is stored.
    if (!payload) return false;

    if (payload.conflict) {
        version.value = payload.version ?? version.value;

        return retried ? false : await writeBackForTheRoom(markdown, sharedTitle, true);
    }

    if (false === payload.success) return false;

    version.value = payload.version ?? version.value;
    savedContent.value = markdown;
    savedTitle.value = title;

    return true;
}

/** Where this guest's caret is, said at most five times a second. */
const fieldRef = ref(null);
let caretTimer = null;

function reportCaret() {
    if (!coeditLive.value || caretTimer) return;

    caretTimer = setTimeout(() => {
        caretTimer = null;
        void publishCursor(fieldRef.value?.selectionStart ?? null);
    }, 200);
}

onUnmounted(() => {
    if (caretTimer) clearTimeout(caretTimer);
});

// A live link opens straight on the field, the way a shared document does:
// there is nothing to "start" when everybody is already writing.
onMounted(() => {
    if (liveLink) startEditing();
});

function startEditing() {
    draftTitle.value = savedTitle.value;
    draftContent.value = savedContent.value;
    editing.value = true;
}

function cancelEditing() {
    editing.value = false;
}

async function save() {
    saving.value = true;
    try {
        const payload = await request(
            props.saveNotePath,
            {
                title: draftTitle.value,
                content: draftContent.value,
                version: version.value,
            },
            // A 429 says which wall was hit and what to do; swallowed into a
            // generic message it would leave somebody retrying into it.
            { accept: [HttpStatus.TooManyRequests] },
        );

        if (!payload) return;

        if (payload.conflict) {
            conflicted.value = true;

            return;
        }

        if (payload.success === false) {
            toast.error(t(payload.message ?? "notes.markdown.errors.save_failed"));

            return;
        }

        savedTitle.value = draftTitle.value;
        savedContent.value = draftContent.value;
        version.value = payload.version ?? version.value;
        editing.value = false;
        toast.success(t("notes.markdown.share.saved"));
    } finally {
        saving.value = false;
    }
}

function reload() {
    window.location.reload();
}

// Paper is light: the dark theme steps aside while printing, on the reader
// as on a share, from the button as from Ctrl+P.
let stopPrintTheme = () => {};
onMounted(() => {
    stopPrintTheme = lightWhilePrinting();
});
onUnmounted(() => stopPrintTheme());

function htmlOf(source, title) {
    // The page already writes the title above the body. Rendered from what
    // was last saved rather than from the prop, so a guest's own save shows
    // without a reload.
    return shareHtml(render(withoutLeadingTitle(source, title)), {
        imagePrefix: props.imagePrefix,
        shareImagePath: props.shareImagePath,
        shareNotePath: props.shareNotePath,
        titleIndex: props.titleIndex,
    });
}

const html = computed(() => htmlOf(savedContent.value, savedTitle.value));

// The preview while writing shows what is typed, not what was saved: that is
// the whole point of looking at it beside the field.
const draftHtml = computed(() => htmlOf(draftContent.value, draftTitle.value));

const { formatDate, formatDateTime } = useDateFormat();

/** The note's typeface and size, as set in the suite. */
const readingClass = computed(() => [
    "serif" === props.display?.font ? "note-font-serif" : "",
    "mono" === props.display?.font ? "note-font-mono" : "",
    props.display?.smallText ? "note-small-text" : "",
]);
/** The text being shown: the draft while writing, the saved one otherwise. */
const shownContent = computed(() => (editing.value ? draftContent.value : savedContent.value));
const metaTags = computed(() => props.meta?.tags ?? []);
const updatedLabel = computed(() =>
    props.meta?.updatedAt && Number.isFinite(Date.parse(props.meta.updatedAt))
        ? `${t("notes.markdown.library.columns.updated")} ${formatDateTime(props.meta.updatedAt)}`
        : "",
);
const minutes = computed(() => readingMinutes(wordCount(shownContent.value)));
/**
 * Where the outline looks for headings, and when it has something to say:
 * beside a rendered note only, never beside the source being typed.
 */
const bodyRef = ref(null);

const helpOpen = ref(false);

/** An example from the cheat sheet, at the caret of the field. */
async function insertFromHelp({ text, caret }) {
    if (!view.value.showEditor) pickMode("split");
    await nextTick();

    const field = fieldRef.value;
    const content = draftContent.value ?? "";
    const start = field ? field.selectionStart : content.length;
    const end = field ? field.selectionEnd : start;
    draftContent.value = content.slice(0, start) + text + content.slice(end);

    await nextTick();
    const caretAt = start + (null === caret ? text.length : caret);
    fieldRef.value?.focus();
    fieldRef.value?.setSelectionRange(caretAt, caretAt);
}

/**
 * A note included in this one (`![[Note]]`), when the link shares it too:
 * asked from the share's own route, which answers for the notes in its scope
 * and nothing else. Out of scope, the inclusion stays a title.
 */
const includedNotes = new Map();
async function sharedNoteContent(title) {
    const id = props.titleIndex?.[String(title ?? "").toLowerCase()];
    if (undefined === id || null === id) return null;

    if (!includedNotes.has(id)) {
        includedNotes.set(
            id,
            request(props.shareNotePath.replace("__id__", String(id)), null, { method: HttpMethod.Get, silent: true, noGuard: true })
                .then((payload) => payload?.note?.content ?? null)
                .catch(() => null),
        );
    }

    return includedNotes.get(id);
}

async function loadEmbed({ title, heading }) {
    const content = await sharedNoteContent(title);
    if (null === content) return null;

    return htmlOf(markdownSection(content, heading), "");
}

/**
 * A linked note's beginning, on hover (09/10/2026) - only for the notes this
 * link shares too, read through the same route as an inclusion.
 */
const { card: hoverCard, onCardEnter, onCardLeave } = useWikiLinkHoverCard(bodyRef, async (title, heading) => {
    const content = await sharedNoteContent(title);
    if (null === content) return null;

    // The card names the note already: its `# Title` would say it twice.
    return htmlOf(noteExcerpt(markdownSection(content, heading)), heading ? "" : title);
});

useNoteHtmlEnhancer(bodyRef, () => [html.value, draftHtml.value, editing.value, view.value.mode], { loadEmbed });
const showsOutline = computed(() => null !== props.meta && (!editing.value || "preview" === view.value.mode));
// Remounted when the headings change, so a title typed in the room shows up.
const outlineKey = computed(() => outlineOf(shownContent.value).map((heading) => heading.text).join("\n"));

// The list only earns its place when the share carries more than the one note.
const hasTree = computed(() => props.tree.length > 1);

function titleOf(node) {
    return node.title?.trim() || t("notes.markdown.untitled");
}

const coverUrl = computed(() => props.cover?.url || "");

/**
 * Where to cut the photo, as a percentage of its height.
 *
 * A banner shows a strip of an image that was not framed for it: without
 * this setting, a portrait shows a forehead or a chin.
 */
const coverStyle = computed(() => ({
    objectPosition: `50% ${Number(props.cover?.position ?? 50)}%`,
}));

// `plain` sets no class: a note without styling follows the person's light
// or dark theme, and a class that repainted it in hard colours would take
// that choice away.
const lookClass = computed(() =>
    "plain" === props.appearance ? "" : `note-look note-look-${props.appearance}`,
);
</script>

<template>
    <div class="flex flex-col gap-2 sm:gap-4 md:flex-row md:items-start">
        <nav
            v-if="hasTree"
            class="aurora-card w-full shrink-0 p-2 md:w-64 print:hidden"
            :aria-label="t('notes.markdown.share.tree_label')"
        >
            <ul class="flex flex-col">
                <li v-for="node in tree" :key="node.id">
                    <a
                        :href="shareNotePath.replace('__id__', String(node.id))"
                        class="block truncate rounded-md px-2 py-1.5 text-sm transition-colors"
                        :class="
                            node.id === noteId
                                ? 'bg-surface-2 font-medium text-primary'
                                : 'text-secondary hover:bg-surface-2'
                        "
                        :style="{ paddingLeft: '0.5rem' }"
                    >{{ titleOf(node) }}</a>
                </li>
            </ul>
        </nav>

        <article
            class="note-print-article aurora-card min-w-0 flex-1 overflow-hidden"
            :class="[lookClass, readingClass]"
        >
            <!-- The banner, when the note has one. The image lives with
                 whoever hosts it: if it disappears from there, the frame
                 stays empty and another one is picked. -->
            <figure v-if="coverUrl" class="relative m-0">
                <img
                    :src="coverUrl"
                    :alt="''"
                    class="h-48 w-full object-cover sm:h-72"
                    :style="coverStyle"
                    loading="lazy"
                >
                <figcaption
                    v-if="cover?.creditName"
                    class="note-look-caption absolute bottom-0 right-0 bg-black/40 px-2 py-0.5 text-2xs text-white"
                >
                    <a
                        v-if="cover?.creditUrl"
                        :href="cover.creditUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-white no-underline hover:underline"
                    >{{ t("notes.markdown.cover.credit", { name: cover.creditName }) }}</a>
                    <span v-else>{{ t("notes.markdown.cover.credit", { name: cover.creditName }) }}</span>
                </figcaption>
            </figure>

            <div class="p-4 sm:p-5">
                <!-- Somebody wrote while this page was open. Nothing is sent
                     after that: the text on screen started from a state that
                     no longer exists. -->
                <div
                    v-if="conflicted"
                    data-share-conflict
                    class="mb-4 rounded-md border border-line bg-surface-2 p-3"
                >
                    <p class="text-sm text-primary">{{ t("notes.markdown.share.conflict") }}</p>
                    <AppButton class="mt-2" variant="secondary" size="sm" v-on:click="reload">
                        {{ t("notes.markdown.share.reload") }}
                    </AppButton>
                </div>

                <!-- The note's emoji, over the banner as in the suite. -->
                <div
                    v-if="display?.icon"
                    data-share-icon
                    class="mb-2 text-5xl leading-none"
                    :class="coverUrl ? '-mt-14 relative' : ''"
                >
                    {{ display.icon }}
                </div>

                <div class="mb-4 flex items-start gap-2">
                    <h2 v-if="!editing" class="min-w-0 flex-1 text-xl font-semibold text-primary">
                        {{ savedTitle?.trim() || t("notes.markdown.untitled") }}
                    </h2>
                    <input
                        v-else
                        v-model="draftTitle"
                        data-share-title-field
                        class="min-w-0 flex-1 rounded-md border border-line bg-surface px-3 py-1.5 text-xl font-semibold text-primary outline-none focus:border-accent-400"
                        :disabled="saving"
                        :placeholder="t('notes.markdown.title_placeholder')"
                        :aria-label="t('notes.markdown.title')"
                    >
                    <AppButton
                        v-if="canWrite && !editing && !conflicted"
                        data-share-edit
                        variant="secondary"
                        size="sm"
                        class="shrink-0 print:hidden"
                        v-on:click="startEditing"
                    >
                        <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("notes.markdown.share.edit") }}
                    </AppButton>
                </div>

                <!-- What else the note is made of, and who is on it, under
                     the title rather than beside it (09/10/2026): on the
                     title's line the badges squeezed the field and read as
                     part of it. -->
                <div
                    v-if="meta || coeditLive || (liveLink && roomPeople.length)"
                    data-share-meta
                    class="-mt-2 mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted"
                >
                    <span v-if="metaTags.length" data-share-tags class="flex flex-wrap gap-1">
                        <AppBadge v-for="tag in metaTags" :key="tag" color="gray" size="xs">{{ tag }}</AppBadge>
                    </span>
                    <span v-if="updatedLabel" data-share-updated>{{ updatedLabel }}</span>
                    <span v-if="meta && minutes" data-share-length class="inline-flex items-center gap-1">
                        <Clock class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("notes.markdown.outline.minutes", { minutes }) }}
                    </span>
                    <span class="ml-auto inline-flex items-center gap-2">
                        <!-- Said once the session has really started, never
                             before: a hub can be configured and still be down. -->
                        <span
                            v-if="coeditLive"
                            data-share-live-badge
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-secondary"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-success" aria-hidden="true" />
                            {{ t("notes.markdown.share.coediting_heading") }}
                        </span>
                        <!-- Who else is on the note, the way the back office
                             shows it: one face per person, in their caret's
                             colour. -->
                        <span v-if="liveLink && roomPeople.length" data-note-room class="inline-flex shrink-0 items-center">
                            <NoteCollaborators
                                :people="roomPeople"
                                :status="roomStreaming ? t('notes.markdown.live.streaming') : t('notes.markdown.live.polling')"
                            />
                        </span>
                    </span>
                </div>

                <!-- The note's properties, as written in the suite. -->
                <dl
                    v-if="display?.properties?.length"
                    data-share-properties
                    class="-mt-2 mb-4 grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm"
                >
                    <template v-for="property in display.properties" :key="property.key">
                        <dt class="truncate text-muted">{{ property.key }}</dt>
                        <dd class="m-0 min-w-0 text-primary">
                            <span v-if="'checkbox' === property.type">{{ property.value ? '☑' : '☐' }}</span>
                            <a
                                v-else-if="'url' === property.type && property.value"
                                :href="property.value"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="break-all"
                            >{{ property.value }}</a>
                            <span v-else-if="'status' === property.type && property.value" class="rounded-full bg-accent-500/15 px-2 py-0.5 text-xs text-accent-600 dark:text-accent-300">{{ property.value }}</span>
                            <span v-else-if="'date' === property.type && property.value">{{ formatDate(property.value) }}</span>
                            <span v-else-if="'person' === property.type">{{ property.label ?? '' }}</span>
                            <span v-else>{{ property.value ?? '' }}</span>
                        </dd>
                    </template>
                </dl>

                <div class="flex gap-8">
                    <div ref="bodyRef" class="min-w-0 flex-1">
                        <!-- The markdown source, plainly. No upload, no slash
                     commands, no autocomplete: this page has no account
                     behind it, and every feature here is one more thing an
                     unauthenticated endpoint has to be safe about. -->
                        <template v-if="editing">
                            <!-- The editor's own view toggle, the same segmented
                         AppTab control, so the shared page reads as the
                         same tool and not as a lookalike. -->
                            <div class="mb-3 flex print:hidden">
                                <div class="inline-flex h-9.5 items-stretch overflow-hidden rounded-lg border border-line">
                                    <AppTab
                                        v-for="option in modeOptions"
                                        :key="option.value"
                                        :data-share-mode="option.value"
                                        size="sm"
                                        align="center"
                                        shape-class="rounded-none"
                                        :active="view.mode === option.value"
                                        :aria-pressed="view.mode === option.value"
                                        :title="option.label"
                                        :aria-label="option.label"
                                        v-on:click="pickMode(option.value)"
                                    >
                                        <component :is="option.icon" class="h-4 w-4" :stroke-width="2" />
                                    </AppTab>
                                </div>
                                <!-- The editor's cheat sheet (09/10/2026), here as in the suite. -->
                                <AppButton
                                    variant="secondary"
                                    class="ml-2"
                                    data-share-help
                                    :label="t('notes.markdown.help.title')"
                                    icon-only
                                    v-on:click="helpOpen = true"
                                >
                                    <CircleHelp class="h-4 w-4" :stroke-width="2" />
                                </AppButton>
                            </div>
                            <div
                                class="grid gap-3"
                                :class="'split' === view.mode ? 'md:grid-cols-2' : ''"
                            >
                                <textarea
                                    v-if="view.showEditor"
                                    ref="fieldRef"
                                    v-model="draftContent"
                                    data-share-content-field
                                    rows="18"
                                    class="min-w-0 w-full resize-y rounded-md border border-line bg-surface p-3 font-mono text-sm text-primary outline-none focus:border-accent-400"
                                    :disabled="saving"
                                    :placeholder="t('notes.markdown.content_placeholder')"
                                    :aria-label="t('notes.markdown.share.edit')"
                                    v-on:keyup="reportCaret"
                                    v-on:click="reportCaret"
                                    v-on:select="reportCaret"
                                />
                                <!-- eslint-disable-next-line vue/no-v-html -- the renderer sanitises
                             through DOMPurify before this ever reaches the page. -->
                                <div
                                    v-if="view.showPreview"
                                    data-share-preview
                                    class="note-preview prose prose-sm dark:prose-invert min-w-0 max-w-none overflow-x-auto"
                                    v-html="draftHtml"
                                />
                            </div>
                            <!-- The others' carets, drawn over the field: a textarea
                         cannot show a second one. -->
                            <NoteRemoteCarets
                                v-if="coeditLive && view.showEditor"
                                :textarea="fieldRef"
                                :cursors="roomCursors"
                                :text="draftContent"
                            />
                            <p class="mt-1 text-xs text-muted" :data-share-live-hint="coeditLive ? '' : null">
                                {{ coeditLive ? t("notes.markdown.share.live_hint") : t("notes.markdown.share.editing_hint") }}
                            </p>
                            <!-- No Save in a live session: the room writes itself back
                         on every pause, and a button that did nothing would
                         be the one lie this page must not tell. -->
                            <div v-if="!coeditLive" class="mt-3 flex flex-wrap gap-2">
                                <AppButton data-share-save :disabled="saving || conflicted" v-on:click="save">
                                    {{ t("notes.markdown.share.save") }}
                                </AppButton>
                                <AppButton variant="ghost" :disabled="saving" v-on:click="cancelEditing">
                                    {{ t("notes.markdown.share.cancel_edit") }}
                                </AppButton>
                            </div>
                        </template>
                        <!-- eslint-disable-next-line vue/no-v-html -- the renderer sanitises
                 through DOMPurify before this ever reaches the page. -->
                        <!-- The same classes as the editor preview: without
                     `prose`, a list lost its bullets and its indent, and
                     the note read online no longer looked like the note
                     as written. Seen at 375 px on the shared page. -->
                        <div v-else class="note-preview prose prose-sm dark:prose-invert max-w-none" v-html="html" />
                    </div>
                    <!-- The outline beside the rendered note on a wide screen,
                     as in the reader. -->
                    <aside v-if="showsOutline" class="hidden w-56 shrink-0 xl:block print:hidden">
                        <div class="sticky top-6">
                            <NoteReaderOutline :key="outlineKey" :root="bodyRef" />
                        </div>
                    </aside>
                </div>
            </div>
        </article>

        <NoteHoverCard :card="hoverCard" v-on:enter="onCardEnter" v-on:leave="onCardLeave" />

        <NoteMarkdownHelp
            :show="helpOpen"
            v-on:close="helpOpen = false"
            v-on:insert="insertFromHelp"
        />
    </div>
</template>
