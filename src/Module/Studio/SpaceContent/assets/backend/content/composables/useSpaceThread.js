import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * The threads of a space, and the two writes on them.
 *
 * Shared by the board and the calendar for the same reason the item form is:
 * they open the same card and show the same conversation, and two copies would
 * disagree the first time one of them changed.
 *
 * `comments` arrives keyed by card id, which is what the server sends: one
 * query for the whole space rather than a fetch on every card opened.
 *
 * @param {import('vue').Ref} comments      keyed by item id
 * @param {object} paths                    commentPostPath, commentDeletePath
 * @param {(data: object) => void} applyBoard
 */
export function useSpaceThread(comments, paths, applyBoard) {
    const { t } = useI18n();
    const { request } = useRequest();

    const commentLoading = ref(false);

    /** The messages on one card, oldest first, or none. */
    const threadOf = computed(
        () => (item) => (item ? (comments.value[item.id] ?? []) : []),
    );

    async function postComment(item, body) {
        if (!item || commentLoading.value) return;

        commentLoading.value = true;
        try {
            const data = await request(
                buildPath(paths.commentPostPath, { id: item.id }),
                { body },
            );

            if (!data?.success) return;

            applyBoard(data);
            toast.success(t("backend.studio.space_content.comment_posted"));
        } finally {
            commentLoading.value = false;
        }
    }

    async function deleteComment(comment) {
        if (!comment || commentLoading.value) return;

        commentLoading.value = true;
        try {
            const data = await request(
                buildPath(paths.commentDeletePath, { id: comment.id }),
            );

            if (!data?.success) return;

            applyBoard(data);
            toast.success(t("backend.studio.space_content.comment_deleted"));
        } finally {
            commentLoading.value = false;
        }
    }

    return { commentLoading, threadOf, postComment, deleteComment };
}
