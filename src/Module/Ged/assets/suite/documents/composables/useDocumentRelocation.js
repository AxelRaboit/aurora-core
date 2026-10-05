import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * Moving one document's bytes to the other storage backend.
 *
 * Two outcomes to tell apart, and the difference matters to whoever pressed
 * the button. A small document is moved inside the request, so the row can be
 * updated on the spot and the toast can say it is done. A large one is handed
 * to a worker, the row goes to "moving", and the honest thing to say is that
 * it has started, not that it finished.
 *
 * The pending state is not polled. A move that is worth queueing takes long
 * enough that polling would be a stream of requests to watch a progress bar
 * nobody is looking at; the state is on the row, and a reload shows it.
 */
export function useDocumentRelocation(props, items) {
    const { t } = useI18n();
    const { request } = useRequest();

    const relocatingId = ref(null);

    function patch(id, changes) {
        const index = items.value.findIndex((doc) => doc.id === id);
        if (index !== -1) {
            items.value[index] = { ...items.value[index], ...changes };
        }
    }

    async function relocate(doc, disk) {
        if (!props.storagePath || relocatingId.value === doc.id) return;

        relocatingId.value = doc.id;
        try {
            const res = await request(
                props.storagePath.replace("__id__", doc.id),
                { disk },
            );
            if (!res) return;

            if (res.queued) {
                patch(doc.id, { storageTransferState: "pending" });
                toast.success(t("suite.ged.documents.relocation.queued"));

                return;
            }

            patch(doc.id, {
                storageDisk: res.disk,
                storageTransferState: res.state,
                storageTransferError: null,
            });

            toast.success(
                t(
                    res.alreadyThere
                        ? "suite.ged.documents.relocation.already_there"
                        : "suite.ged.documents.relocation.done",
                ),
            );
        } finally {
            relocatingId.value = null;
        }
    }

    return { relocate, relocatingId };
}
