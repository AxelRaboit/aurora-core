<script setup>
import "@notes/backend/markdown/components/preview.css";
import "@notes/share/appearance.css";

import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useMarkdownRenderer } from "@notes/backend/markdown/composables/useMarkdownRenderer.js";
import { shareHtml } from "@notes/share/useSharedNoteHtml.js";

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
     * Le bandeau de la note : `{url, creditName, creditUrl, position}`.
     *
     * L'image reste chez celui qui l'héberge ; on n'en a que l'adresse. Le
     * crédit l'accompagne parce que la licence le demande, et parce qu'il
     * n'y a plus de fiche en médiathèque pour le porter.
     */
    cover: { type: Object, default: null },
    /** {@see NoteAppearanceEnum} - le fond de la note et son encre. */
    appearance: { type: String, default: "plain" },
    /** Le chemin du retour vers l'éditeur, quand on lit sa propre note. */
    backPath: { type: String, default: "" },
});

const { t } = useI18n();
const { render } = useMarkdownRenderer();

const html = computed(() =>
    shareHtml(render(props.content), {
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
 * Où couper la photo, en pourcentage de sa hauteur.
 *
 * Un bandeau montre une bande d'une image qui n'a pas été cadrée pour ça :
 * sans ce réglage, un portrait montre un front ou un menton.
 */
const coverStyle = computed(() => ({
    objectPosition: `50% ${Number(props.cover?.position ?? 50)}%`,
}));

// `plain` ne pose aucune classe : une note sans habillage suit le thème
// clair ou sombre de la personne, et une classe qui la repeindrait en dur
// lui retirerait ce choix.
const lookClass = computed(() =>
    "plain" === props.appearance ? "" : `note-look note-look-${props.appearance}`,
);
</script>

<template>
    <div class="flex flex-col gap-2 sm:gap-4 md:flex-row md:items-start">
        <nav
            v-if="hasTree"
            class="w-full shrink-0 rounded-xl border border-line bg-surface p-2 md:w-64"
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
            class="min-w-0 flex-1 overflow-hidden rounded-xl border border-line bg-surface"
            :class="lookClass"
        >
            <!-- Le bandeau, quand la note en porte un. L'image vit chez celui
                 qui l'héberge : si elle disparaît de là-bas, le cadre reste
                 vide et on en choisit une autre. -->
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

            <div class="p-4 sm:p-6">
                <h2 class="mb-4 text-xl font-semibold text-primary">
                    {{ noteTitle?.trim() || t("notes.markdown.untitled") }}
                </h2>
                <!-- eslint-disable-next-line vue/no-v-html -- the renderer sanitises
                 through DOMPurify before this ever reaches the page. -->
                <div class="note-preview" v-html="html" />

                <!-- Le retour, seulement quand on lit sa propre note : un invité
                 n'a pas d'éditeur où revenir. -->
                <a
                    v-if="backPath"
                    :href="backPath"
                    class="mt-6 inline-block text-xs text-muted no-underline transition-colors hover:text-primary"
                >{{ t("notes.markdown.read.back") }}</a>
            </div>
        </article>
    </div>
</template>
