<script setup>
import "@notes/suite/markdown/components/preview.css";
import "@notes/share/appearance.css";
import "@notes/share/print.css";

import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Pencil } from "lucide-vue-next";
import AppButton from "@shared/components/action/AppButton.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpStatus } from "@/shared/utils/http/HttpStatus.js";
import { useMarkdownRenderer } from "@notes/suite/markdown/composables/useMarkdownRenderer.js";
import { shareHtml } from "@notes/share/useSharedNoteHtml.js";
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
});

const { t } = useI18n();
const { render } = useMarkdownRenderer();
const { request } = useRequest();

/**
 * Writing, when the link allows it.
 *
 * **The markdown source in a plain field, and nothing more.** This page has
 * no account behind it, so it gets the smallest surface that does the job:
 * no image upload, no slash commands, no wiki-link autocomplete. Somebody
 * invited to correct a paragraph needs the text, not the editor - and every
 * feature added here is another thing an unauthenticated endpoint has to be
 * safe about.
 */
const editing = ref(false);
const draftTitle = ref(props.noteTitle ?? "");
const draftContent = ref(props.content ?? "");
const savedTitle = ref(props.noteTitle ?? "");
const savedContent = ref(props.content ?? "");
const version = ref(props.noteVersion);
const saving = ref(false);

/**
 * Somebody else wrote while this page was open.
 *
 * Nothing goes out on its own after that: the text on screen started from a
 * state that no longer exists, and saving it would erase their work without
 * either of them knowing. The page says so and offers to reload.
 */
const conflicted = ref(false);

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
            editing.value = false;

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

const html = computed(() =>
    // The page already writes the title above the body. Rendered from what
    // was last saved rather than from the prop, so a guest's own save shows
    // without a reload.
    shareHtml(render(withoutLeadingTitle(savedContent.value, savedTitle.value)), {
        imagePrefix: props.imagePrefix,
        shareImagePath: props.shareImagePath,
        shareNotePath: props.shareNotePath,
        titleIndex: props.titleIndex,
    }),
);

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
            :class="lookClass"
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

                <!-- The markdown source, plainly. No upload, no slash
                     commands, no autocomplete: this page has no account
                     behind it, and every feature here is one more thing an
                     unauthenticated endpoint has to be safe about. -->
                <template v-if="editing">
                    <textarea
                        v-model="draftContent"
                        data-share-content-field
                        rows="18"
                        class="w-full resize-y rounded-md border border-line bg-surface p-3 font-mono text-sm text-primary outline-none focus:border-accent-400"
                        :disabled="saving"
                        :placeholder="t('notes.markdown.content_placeholder')"
                        :aria-label="t('notes.markdown.share.edit')"
                    />
                    <p class="mt-1 text-xs text-muted">{{ t("notes.markdown.share.editing_hint") }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <AppButton data-share-save :disabled="saving" v-on:click="save">
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
        </article>
    </div>
</template>
