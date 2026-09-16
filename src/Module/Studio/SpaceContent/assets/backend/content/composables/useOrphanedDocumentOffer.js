import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Offers to bin the files a removal just left used by nobody.
 *
 * **Offered after the fact, never asked before.** Taking a file off a card is
 * one click today, and putting a dialog in front of it to ask about a
 * consequence most people do not care about would tax every removal to catch
 * the few that matter. The removal happens; the offer arrives beside it and is
 * ignored by doing nothing.
 *
 * **Because three gestures leave the same leftover.** Detaching, deleting the
 * card and deleting the space all remove the join and leave the document: a
 * draft in the space's folder that nothing points at. Nothing surfaces it
 * either - the library's picker lists published documents only - so it is
 * found by browsing the folder or not at all.
 *
 * The server decides what is offered, and offers nothing to somebody without
 * `ged.documents.delete`; this only draws what came back. The trash catches
 * the mistake, so the worst case of a hasty click is a restore.
 */
export function useOrphanedDocumentOffer() {
    const { t } = useI18n();
    const { request } = useRequest();

    async function trash(documents) {
        for (const document of documents) {
            await request(document.trashPath);
        }

        toast.success(
            t("backend.studio.space_content.orphaned_trashed", {
                count: documents.length,
            }),
        );
    }

    /**
     * @param {object} data the answer of a detach or a card deletion
     */
    function offer(data) {
        const documents = data?.orphanedDocuments ?? [];

        if (documents.length === 0) return;

        toast(
            documents.length === 1
                ? t("backend.studio.space_content.orphaned_one", {
                      title: documents[0].title,
                  })
                : t("backend.studio.space_content.orphaned_many", {
                      count: documents.length,
                  }),
            {
                action: {
                    label: t("backend.studio.space_content.orphaned_trash"),
                    onClick: () => trash(documents),
                },
            },
        );
    }

    return { offer };
}
