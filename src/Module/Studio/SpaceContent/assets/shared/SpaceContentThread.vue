<script setup>
/**
 * The conversation on one piece of content, both sides of it.
 *
 * **One shared thread.** What the studio writes here, the client reads on their
 * own page; the notice above the box says so, because a note meant for a
 * colleague typed into the wrong field is a note a customer reads. There is no
 * internal side, deliberately: a flag one forgets once is worse than not having
 * one.
 *
 * Only the studio's own messages can be removed. What a client wrote is what
 * the studio was asked to act on, and a provider able to delete a complaint has
 * a record of the engagement that proves nothing.
 *
 * It sits in `assets/shared/` rather than under `backend/` because both
 * surfaces mount it, and its own words are `shared.thread.*` for the same
 * reason: a key under `backend.` rendered on a page a customer reads is a
 * namespace that has stopped meaning anything. The one sentence that differs
 * between the two - who reads what is typed here - arrives as `notice`.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Check, MessageSquare, Send, Trash2 } from "lucide-vue-next";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    comments: { type: Array, default: () => [] },
    /**
     * The client's answer, shown in the stream rather than above it.
     *
     * It used to sit in a box of its own, with a sentence underneath warning
     * that rewriting the text would drop it. Three places for one conversation:
     * a verdict, a caveat, and the messages it was about. It is one line in the
     * thread now, at the moment it was given, which is where somebody reading
     * the exchange expects to meet it.
     *
     * The caveat went with it. A verdict that disappears from the thread the
     * moment the wording changes says the same thing, at the moment it matters,
     * without asking anybody to have read a warning first.
     */
    verdict: { type: String, default: "pending" },
    verdictBy: { type: String, default: "" },
    verdictAt: { type: String, default: null },
    /** False on a read-only reader, and then nothing below the list is drawn. */
    canPost: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    /** Shown above the box: who reads what is typed here. */
    notice: { type: String, default: "" },
});

const emit = defineEmits(["post", "delete"]);

const { t, d } = useI18n();

const draft = ref("");

/**
 * The messages and the verdict, in the order they happened.
 *
 * One stream rather than a panel above a list: what the client said and what
 * they decided are the same conversation, and reading them apart is how two
 * people end up disagreeing about what was agreed.
 */
const entries = computed(() => {
    const stream = props.comments.map((comment) => ({
        kind: "message",
        key: `m${comment.id}`,
        at: comment.createdAt,
        comment,
    }));

    if ("pending" !== props.verdict && props.verdictAt) {
        stream.push({
            kind: "verdict",
            key: "verdict",
            at: props.verdictAt,
        });
    }

    return stream.sort((a, b) => new Date(a.at) - new Date(b.at));
});

function post() {
    const body = draft.value.trim();
    if ("" === body) return;

    emit("post", body);
    draft.value = "";
}
</script>

<template>
    <section class="space-y-3">
        <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
            {{ t("shared.thread.title") }}
        </h3>

        <p v-if="!entries.length" class="text-sm text-muted">
            {{ t("shared.thread.empty") }}
        </p>

        <ul v-else class="space-y-2">
            <li v-for="entry in entries" :key="entry.key">
                <!-- The verdict as a line rather than a bubble: it is
                     something that happened, not something somebody said, and
                     giving it a message's shape would put words in the
                     client's mouth. One loop, so it sits at its own moment
                     among the messages instead of above them. -->
                <div
                    v-if="'verdict' === entry.kind"
                    class="flex items-center gap-2 px-3 py-1 text-xs"
                >
                    <Check
                        v-if="'approved' === verdict"
                        class="h-3.5 w-3.5 shrink-0 text-emerald-500"
                        :stroke-width="2"
                    />
                    <MessageSquare
                        v-else
                        class="h-3.5 w-3.5 shrink-0 text-amber-500"
                        :stroke-width="2"
                    />
                    <span class="text-primary">
                        {{ t(`shared.thread.verdict.${verdict}`, { who: verdictBy }) }}
                    </span>
                    <span class="text-muted">{{ d(new Date(entry.at), "short") }}</span>
                </div>

                <div
                    v-else
                    class="group rounded-lg px-3 py-2"
                    :class="
                        entry.comment.fromClient
                            ? 'bg-accent-500/5 border border-accent-500/20'
                            : 'bg-surface-2/60'
                    "
                >
                    <div class="flex items-baseline gap-2">
                        <span class="text-xs font-medium text-primary">
                            {{ entry.comment.author }}
                        </span>
                        <span class="text-xs text-muted">
                            {{ d(new Date(entry.comment.createdAt), "short") }}
                        </span>
                        <span
                            v-if="entry.comment.fromClient"
                            class="text-xs text-accent-500"
                        >
                            {{ t("shared.thread.from_client") }}
                        </span>
                        <button
                            v-if="canDelete && !entry.comment.fromClient"
                            type="button"
                            class="ml-auto rounded p-1 text-muted transition-opacity hover:text-red-500 sm:opacity-0 sm:group-hover:opacity-100"
                            :aria-label="t('shared.common.delete')"
                            v-on:click="emit('delete', entry.comment)"
                        >
                            <Trash2 class="h-3 w-3" :stroke-width="2" />
                        </button>
                    </div>
                    <p class="mt-1 whitespace-pre-line text-sm text-primary">
                        {{ entry.comment.body }}
                    </p>
                </div>
            </li>
        </ul>

        <div v-if="canPost" class="space-y-2">
            <AppTextarea
                :model-value="draft"
                :placeholder="t('shared.thread.placeholder')"
                :hint="notice"
                :rows="2"
                v-on:update:model-value="draft = $event"
            />
            <div class="flex justify-end">
                <AppButton
                    variant="ghost"
                    size="sm"
                    :loading="loading"
                    :disabled="!draft.trim()"
                    v-on:click="post"
                >
                    <Send class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.thread.send") }}
                </AppButton>
            </div>
        </div>
    </section>
</template>
