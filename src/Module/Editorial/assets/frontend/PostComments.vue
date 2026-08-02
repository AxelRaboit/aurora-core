<script setup>
import { useI18n } from "vue-i18n";
import { usePostComments } from "./composables/usePostComments.js";

/**
 * The comment thread under a post.
 *
 * Fetched by the browser rather than rendered into the page: comments are
 * the one part of a post that differs between two readers of the same cached
 * HTML, and the public post response is meant to be cacheable.
 */
const props = defineProps({
    listPath: { type: String, required: true },
    submitPath: { type: String, required: true },
    reactPathTemplate: { type: String, required: true },
});

const { t, d } = useI18n();

const {
    comments, total, reactionTypes, loaded,
    form, errors, sending, notice, replyingTo,
    submit, replyTo, react,
} = usePostComments(props);

function formatDate(value) {
    return d(new Date(value), "short");
}

function reactionCount(comment, type) {
    return comment.reactions?.[type] ?? 0;
}
</script>

<template>
    <div class="space-y-8">
        <h2 class="text-xl font-semibold text-primary">
            {{ t("frontend.editorial.comments.title") }}
            <span v-if="total" class="text-secondary font-normal">({{ total }})</span>
        </h2>

        <p v-if="loaded && !comments.length" class="text-sm text-secondary">
            {{ t("frontend.editorial.comments.empty") }}
        </p>

        <ul class="space-y-6">
            <li v-for="comment in comments" :key="comment.id" class="space-y-3">
                <article class="space-y-2">
                    <header class="flex flex-wrap items-baseline gap-2 text-sm">
                        <span class="font-medium text-primary">{{ comment.authorName }}</span>
                        <time class="text-muted text-xs" :datetime="comment.createdAt">{{ formatDate(comment.createdAt) }}</time>
                    </header>

                    <p class="text-sm text-secondary whitespace-pre-line">{{ comment.content }}</p>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            v-for="reaction in reactionTypes"
                            :key="reaction.value"
                            type="button"
                            class="px-2 py-0.5 text-xs rounded-md border border-line/60 text-secondary hover:text-primary hover:border-line transition-colors"
                            :title="t(reaction.labelKey)"
                            :aria-label="t(reaction.labelKey)"
                            v-on:click="react(comment, reaction.value)"
                        >
                            {{ reaction.emoji }}
                            <span v-if="reactionCount(comment, reaction.value)">{{ reactionCount(comment, reaction.value) }}</span>
                        </button>

                        <button
                            type="button"
                            class="ml-2 text-xs text-secondary hover:text-primary transition-colors"
                            v-on:click="replyTo(comment)"
                        >
                            {{ t("frontend.editorial.comments.reply") }}
                        </button>
                    </div>
                </article>

                <ul v-if="comment.replies?.length" class="space-y-4 pl-5 border-l border-line/60">
                    <li v-for="reply in comment.replies" :key="reply.id" class="space-y-2">
                        <header class="flex flex-wrap items-baseline gap-2 text-sm">
                            <span class="font-medium text-primary">{{ reply.authorName }}</span>
                            <time class="text-muted text-xs" :datetime="reply.createdAt">{{ formatDate(reply.createdAt) }}</time>
                        </header>

                        <p class="text-sm text-secondary whitespace-pre-line">{{ reply.content }}</p>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                v-for="reaction in reactionTypes"
                                :key="reaction.value"
                                type="button"
                                class="px-2 py-0.5 text-xs rounded-md border border-line/60 text-secondary hover:text-primary hover:border-line transition-colors"
                                :title="t(reaction.labelKey)"
                                :aria-label="t(reaction.labelKey)"
                                v-on:click="react(reply, reaction.value)"
                            >
                                {{ reaction.emoji }}
                                <span v-if="reactionCount(reply, reaction.value)">{{ reactionCount(reply, reaction.value) }}</span>
                            </button>
                        </div>
                    </li>
                </ul>
            </li>
        </ul>

        <form class="space-y-3 border-t border-line pt-6" v-on:submit.prevent="submit">
            <p
                v-if="notice"
                class="text-sm rounded-lg px-3 py-2"
                :class="notice.type === 'success' ? 'bg-surface-2 text-primary' : 'bg-surface-2 text-rose-500'"
            >
                {{ notice.text }}
            </p>

            <p v-if="replyingTo" class="text-xs text-secondary">
                {{ t("frontend.editorial.comments.replying_to", { name: replyingTo.authorName }) }}
                <button type="button" class="underline ml-1" v-on:click="replyTo(null)">
                    {{ t("frontend.editorial.comments.cancel_reply") }}
                </button>
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block space-y-1">
                    <span class="text-xs text-secondary">{{ t("frontend.editorial.comments.name") }}</span>
                    <input
                        v-model="form.authorName"
                        type="text"
                        required
                        class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                    >
                    <span v-if="errors.authorName" class="block text-xs text-rose-500">{{ errors.authorName }}</span>
                </label>

                <label class="block space-y-1">
                    <span class="text-xs text-secondary">{{ t("frontend.editorial.comments.email") }}</span>
                    <input
                        v-model="form.authorEmail"
                        type="email"
                        required
                        class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                    >
                    <span class="block text-xs text-muted">{{ t("frontend.editorial.comments.email_hint") }}</span>
                    <span v-if="errors.authorEmail" class="block text-xs text-rose-500">{{ errors.authorEmail }}</span>
                </label>
            </div>

            <label class="block space-y-1">
                <span class="text-xs text-secondary">{{ t("frontend.editorial.comments.content") }}</span>
                <textarea
                    v-model="form.content"
                    rows="4"
                    required
                    class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                />
                <span v-if="errors.content" class="block text-xs text-rose-500">{{ errors.content }}</span>
            </label>

            <!-- The honeypot: hidden from readers and from screen readers, so
                 only something filling every input it finds will touch it. -->
            <input
                v-model="form.website"
                type="text"
                tabindex="-1"
                autocomplete="off"
                aria-hidden="true"
                class="hidden"
            >

            <button
                type="submit"
                class="px-4 py-2 rounded-lg bg-accent-600 text-white text-sm font-medium disabled:opacity-60"
                :disabled="sending"
            >
                {{ t("frontend.editorial.comments.submit") }}
            </button>
        </form>
    </div>
</template>
