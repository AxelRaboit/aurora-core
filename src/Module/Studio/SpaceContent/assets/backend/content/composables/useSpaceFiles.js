import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useListViewMode } from "@/shared/composables/list/useListViewMode.js";

/**
 * The files of a space, flattened and ordered, plus the shape to draw them in.
 *
 * Out of the view because the convention puts transformation helpers - sort,
 * filter, group - in a composable and leaves the SFC its template. What is
 * left over there is bindings.
 *
 * @param {import('vue').Ref|import('vue').ComputedRef} attachments keyed by card id
 * @param {import('vue').Ref|import('vue').ComputedRef} items       the cards, for their titles
 */
export function useSpaceFiles(attachments, items) {
    const { t, n } = useI18n();

    // Its own parameter rather than the shared `view`: the space's own switcher
    // already owns that word on this page, and `?files=grid` says which of the
    // two it describes.
    const { viewMode, storedViewMode, setViewMode, container } =
        useListViewMode(["grid", "list"], "list", "files");

    const itemsById = computed(
        () => new Map((items.value ?? []).map((item) => [item.id, item])),
    );

    /**
     * Every file of the space, newest first.
     *
     * The card id is carried on each row rather than looked up later: the
     * payload is keyed by card, so it is free here and it is what the click
     * needs.
     */
    const files = computed(() =>
        Object.entries(attachments.value ?? {})
            .flatMap(([itemId, list]) =>
                (list ?? []).map((file) => ({
                    ...file,
                    itemId: Number(itemId),
                })),
            )
            .sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)),
    );

    function itemOf(itemId) {
        return itemsById.value.get(itemId) ?? null;
    }

    function titleOf(itemId) {
        return (
            itemOf(itemId)?.title ??
            t("backend.studio.space_content.files_unknown_card")
        );
    }

    /** Bytes as a row shows them. Absent size is a file filed before the column existed. */
    function weightOf(file) {
        if (!file.size) return null;

        return `${n(Math.max(1, Math.round(file.size / 1024)))} ko`;
    }

    return {
        viewMode,
        storedViewMode,
        setViewMode,
        container,
        files,
        itemOf,
        titleOf,
        weightOf,
    };
}
