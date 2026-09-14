import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * "Put the whole médiathèque on that backend."
 *
 * Not the bulk action with everything ticked. A selection is a list the reader
 * built and can see; this is a standing instruction about a library whose size
 * they do not know, and the two want different words, a different confirmation
 * and a different report. Sending every id up would also mean the browser
 * deciding what "everything" was at the moment the page was drawn, which is
 * not the same set as when the button is pressed.
 *
 * Confirmed before it runs, unlike the per-row move. One row is a mistake you
 * undo by pressing the other button; eight hundred is a bill on somebody's
 * Cloudflare account and a long wait before the library is reachable again by
 * the route it was.
 *
 * Nothing is polled afterwards. The work happens in a worker and the state is
 * on each row, so the listing is reloaded once and says what it says - a
 * progress bar over a queue nobody is watching would be a request a second to
 * animate a number.
 */
export function useDocumentRelocateAll(props, reload) {
    const { t } = useI18n();
    const { request } = useRequest();

    /** The disk a confirmation is open for, or null when it is closed. */
    const pendingDisk = ref(null);
    const running = ref(false);

    const confirmLabel = computed(() =>
        pendingDisk.value === "local"
            ? t("backend.ged.documents.relocation.all_confirm_local")
            : t("backend.ged.documents.relocation.all_confirm_remote"),
    );

    function askRelocateAll(disk) {
        pendingDisk.value = disk;
    }

    function cancelRelocateAll() {
        pendingDisk.value = null;
    }

    async function confirmRelocateAll() {
        if (!props.relocateAllPath || !pendingDisk.value) return;

        running.value = true;
        try {
            const res = await request(props.relocateAllPath, {
                disk: pendingDisk.value,
            });
            if (!res) return;
            if (!res.success) {
                toast.error(t("shared.common.error"));

                return;
            }

            const queued = res.queued ?? 0;

            // "Nothing to do" and "nothing happened" look the same from the
            // outside, and the reader who just pressed a button deserves to be
            // told which of the two it was.
            toast.success(
                queued === 0
                    ? t("backend.ged.documents.relocation.all_nothing")
                    : t("backend.ged.documents.relocation.all_queued", {
                          queued,
                          skipped: res.alreadyThere ?? 0,
                      }),
            );

            pendingDisk.value = null;
            await reload?.();
        } finally {
            running.value = false;
        }
    }

    return {
        pendingDisk,
        confirmLabel,
        running,
        askRelocateAll,
        cancelRelocateAll,
        confirmRelocateAll,
    };
}
