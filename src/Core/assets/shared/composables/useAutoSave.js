import { ref, unref, onBeforeUnmount } from "vue";

/**
 * Generic debounced auto-save state machine.
 *
 * Decouples "schedule a save", "save in flight", "show feedback" from any
 * specific persistence model: the caller provides an `isDirty` predicate
 * and a `save` callback returning a Promise<boolean|{ok}>. The composable
 * owns the debounce timer, the saved-indicator timer and the status ref.
 *
 * Typical wiring inside an editor composable:
 *
 *   const { saveStatus, schedule, flush } = useAutoSave({
 *       isDirty,
 *       save: async () => {
 *           const { ok } = await api.update(id, payload);
 *           if (ok) loadedSnapshot.value = payload;
 *           return ok;
 *       },
 *       onError: () => toast.error(t("xxx.save_failed")),
 *   });
 *
 *   watch(form, () => { if (isDirty.value) schedule(); }, { deep: true });
 *   // Before navigation:    await flush();
 *   // Before delete/unload: await flush();
 *
 * Statuses :
 *  - `idle`    : nothing to do, nothing in flight
 *  - `pending` : debounce timer running, save will fire soon
 *  - `saving`  : the save callback is awaiting
 *  - `saved`   : last save succeeded - auto-resets to `idle` after
 *                `savedIndicatorMs` if still clean
 *  - `error`   : last save failed - caller can show a retry affordance
 *
 * @param {object}   options
 * @param {() => boolean | import('vue').Ref<boolean>} options.isDirty
 * @param {() => Promise<boolean | { ok: boolean }>}   options.save
 * @param {number}   [options.debounceMs=800]
 * @param {number}   [options.savedIndicatorMs=1500]
 * @param {() => void} [options.onError]   - fired once per failed save
 */
export function useAutoSave({
    isDirty,
    save,
    debounceMs = 800,
    savedIndicatorMs = 1500,
    onError = null,
}) {
    const saveStatus = ref("idle");
    /** Timestamp of the last successful save, or null until the first one. */
    const lastSavedAt = ref(null);

    let pendingTimer = null;
    let savedTimer = null;
    let inFlight = false;

    function dirtyNow() {
        return typeof isDirty === "function" ? isDirty() : unref(isDirty);
    }

    function clearPendingTimer() {
        if (pendingTimer) {
            clearTimeout(pendingTimer);
            pendingTimer = null;
        }
    }

    function clearSavedTimer() {
        if (savedTimer) {
            clearTimeout(savedTimer);
            savedTimer = null;
        }
    }

    function scheduleSavedIndicatorReset() {
        clearSavedTimer();
        savedTimer = setTimeout(() => {
            savedTimer = null;
            if (saveStatus.value === "saved" && !dirtyNow()) {
                saveStatus.value = "idle";
            }
        }, savedIndicatorMs);
    }

    /**
     * Cancel any pending debounce and start saving immediately, awaiting
     * completion. No-op if nothing is dirty or a save is already running.
     */
    async function performSave() {
        if (inFlight || !dirtyNow()) return;
        clearPendingTimer();
        inFlight = true;
        saveStatus.value = "saving";
        let ok = false;
        try {
            const result = await save();
            ok = typeof result === "boolean" ? result : (result?.ok ?? true);
            if (!ok) {
                saveStatus.value = "error";
                if (onError) onError();
                return;
            }
            saveStatus.value = "saved";
            lastSavedAt.value = new Date();
            scheduleSavedIndicatorReset();
        } finally {
            inFlight = false;
            // Only auto-reschedule after a successful save (typing during
            // a successful flush stays unsaved otherwise). On failure we
            // leave the `error` status visible and let the next user
            // action drive a fresh schedule - avoids retry-storms.
            if (ok && dirtyNow()) schedule();
        }
    }

    /**
     * Arm the debounce timer. Subsequent calls within `debounceMs` reset
     * the timer (standard trailing-edge debounce).
     */
    function schedule() {
        clearPendingTimer();
        saveStatus.value = "pending";
        pendingTimer = setTimeout(() => {
            pendingTimer = null;
            void performSave();
        }, debounceMs);
    }

    /**
     * Force the pending save (if any) to run *now*. Awaits the result so
     * the caller can chain navigation / deletion safely.
     */
    async function flush() {
        clearPendingTimer();
        if (dirtyNow() && !inFlight) {
            await performSave();
        }
    }

    /**
     * Drop the pending save without persisting. Useful when the target
     * entity is about to be deleted.
     */
    function cancel() {
        clearPendingTimer();
        clearSavedTimer();
        saveStatus.value = "idle";
    }

    onBeforeUnmount(() => {
        clearPendingTimer();
        clearSavedTimer();
    });

    return { saveStatus, lastSavedAt, schedule, flush, cancel };
}
