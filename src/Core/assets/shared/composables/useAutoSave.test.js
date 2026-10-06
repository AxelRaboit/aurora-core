import { describe, it, expect, vi } from "vitest";
import { ref } from "vue";
import { useAutoSave } from "./useAutoSave.js";

// onBeforeUnmount needs a component context. Stubbing it avoids the
// "no current instance" warning during unit tests.
vi.mock("vue", async () => {
    const actual = await vi.importActual("vue");
    return { ...actual, onBeforeUnmount: () => {} };
});

function wait(delayMs) {
    return new Promise((resolve) => setTimeout(resolve, delayMs));
}

describe("useAutoSave", () => {
    it("debounces schedule() and fires the save callback once", async () => {
        const dirty = ref(true);
        const save = vi.fn().mockImplementation(async () => {
            dirty.value = false; // mimic caller flipping the source clean on success
            return true;
        });
        const { saveStatus, schedule } = useAutoSave({
            isDirty: dirty,
            save,
            debounceMs: 20,
        });

        schedule();
        schedule();
        schedule();

        expect(save).not.toHaveBeenCalled();
        expect(saveStatus.value).toBe("pending");

        await wait(40);

        expect(save).toHaveBeenCalledTimes(1);
        expect(saveStatus.value).toBe("saved");
    });

    it("resets saved → idle after savedIndicatorMs when clean", async () => {
        const dirty = ref(true);
        const save = vi.fn().mockImplementation(async () => {
            dirty.value = false;
            return true;
        });
        const { saveStatus, schedule } = useAutoSave({
            isDirty: dirty,
            save,
            debounceMs: 10,
            savedIndicatorMs: 30,
        });

        schedule();
        await wait(20);
        expect(saveStatus.value).toBe("saved");

        await wait(40);
        expect(saveStatus.value).toBe("idle");
    });

    it("transitions to error when save returns false and invokes onError", async () => {
        const dirty = ref(true);
        const onError = vi.fn();
        const save = vi.fn().mockResolvedValue(false);
        const { saveStatus, schedule } = useAutoSave({
            isDirty: dirty,
            save,
            debounceMs: 10,
            onError,
        });

        schedule();
        await wait(20);

        expect(saveStatus.value).toBe("error");
        expect(onError).toHaveBeenCalledTimes(1);
    });

    it("flush() runs the pending save immediately", async () => {
        const dirty = ref(true);
        const save = vi.fn().mockResolvedValue(true);
        const { flush } = useAutoSave({
            isDirty: dirty,
            save,
            debounceMs: 10_000,
        });

        await flush();

        expect(save).toHaveBeenCalledTimes(1);
    });

    it("cancel() drops the pending save without calling save()", async () => {
        const dirty = ref(true);
        const save = vi.fn().mockResolvedValue(true);
        const { saveStatus, schedule, cancel } = useAutoSave({
            isDirty: dirty,
            save,
            debounceMs: 20,
        });

        schedule();
        cancel();
        await wait(40);

        expect(save).not.toHaveBeenCalled();
        expect(saveStatus.value).toBe("idle");
    });

    it("reschedules when isDirty is still true after save (typing mid-flight)", async () => {
        const dirty = ref(true);
        let callCount = 0;
        const save = vi.fn().mockImplementation(async () => {
            callCount++;
            // First save completes but the source remains dirty (mimicking
            // a keystroke during the request). Second save flips it clean.
            if (callCount >= 2) dirty.value = false;
            return true;
        });
        const { schedule } = useAutoSave({
            isDirty: dirty,
            save,
            debounceMs: 10,
        });

        schedule();
        await wait(50);

        expect(save).toHaveBeenCalledTimes(2);
    });

    it("does not call save() when isDirty becomes false before the debounce fires", async () => {
        const dirty = ref(true);
        const save = vi.fn().mockResolvedValue(true);
        const { schedule } = useAutoSave({
            isDirty: dirty,
            save,
            debounceMs: 20,
        });

        schedule();
        dirty.value = false;
        await wait(40);

        expect(save).not.toHaveBeenCalled();
    });
});
