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
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Send, Trash2 } from "lucide-vue-next";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppButton from "@/shared/components/action/AppButton.vue";

defineProps({
    comments: { type: Array, default: () => [] },
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

        <p v-if="!comments.length" class="text-sm text-muted">
            {{ t("shared.thread.empty") }}
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="comment in comments"
                :key="comment.id"
                class="group rounded-lg px-3 py-2"
                :class="
                    comment.fromClient
                        ? 'bg-accent-500/5 border border-accent-500/20'
                        : 'bg-surface-2/60'
                "
            >
                <div class="flex items-baseline gap-2">
                    <span class="text-xs font-medium text-primary">
                        {{ comment.author }}
                    </span>
                    <span class="text-xs text-muted">
                        {{ d(new Date(comment.createdAt), "short") }}
                    </span>
                    <span v-if="comment.fromClient" class="text-xs text-accent-500">
                        {{ t("shared.thread.from_client") }}
                    </span>
                    <button
                        v-if="canDelete && !comment.fromClient"
                        type="button"
                        class="ml-auto rounded p-1 text-muted opacity-0 transition-opacity hover:text-red-500 group-hover:opacity-100"
                        :aria-label="t('shared.common.delete')"
                        v-on:click="emit('delete', comment)"
                    >
                        <Trash2 class="h-3 w-3" :stroke-width="2" />
                    </button>
                </div>
                <p class="mt-1 whitespace-pre-line text-sm text-primary">
                    {{ comment.body }}
                </p>
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
