import { describe, it, expect, vi, beforeEach } from "vitest";

const request = vi.fn();
const success = vi.fn();
const error = vi.fn();

// The parameters are half of what this composable decides, so the stub keeps
// them rather than returning the bare key: asserting on the message alone
// would pass whatever numbers were put in it.
vi.mock("vue-i18n", () => ({
    useI18n: () => ({
        t: (key, params) => (params ? `${key} ${JSON.stringify(params)}` : key),
    }),
}));
vi.mock("vue-sonner", () => ({ toast: { success, error } }));
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

const { useDocumentRelocateAll } = await import("./useDocumentRelocateAll.js");

const PROPS = { relocateAllPath: "/backend/ged/documents/relocate-all" };

describe("useDocumentRelocateAll", () => {
    beforeEach(() => {
        request.mockReset();
        success.mockReset();
        error.mockReset();
    });

    /**
     * The confirmation is the whole point of the flow. A per-row move is undone
     * by pressing the other button; this one is a bill on somebody's account.
     */
    it("asks before it sends anything", () => {
        const all = useDocumentRelocateAll(PROPS, vi.fn());

        all.askRelocateAll("r2");

        expect(all.pendingDisk.value).toBe("r2");
        expect(request).not.toHaveBeenCalled();
    });

    it("sends nothing when the confirmation is dismissed", async () => {
        const all = useDocumentRelocateAll(PROPS, vi.fn());

        all.askRelocateAll("r2");
        all.cancelRelocateAll();
        await all.confirmRelocateAll();

        expect(request).not.toHaveBeenCalled();
    });

    it("posts the chosen backend and reloads the listing", async () => {
        const reload = vi.fn();
        request.mockResolvedValue({
            success: true,
            queued: 12,
            alreadyThere: 3,
        });

        const all = useDocumentRelocateAll(PROPS, reload);
        all.askRelocateAll("local");
        await all.confirmRelocateAll();

        expect(request).toHaveBeenCalledWith(PROPS.relocateAllPath, {
            disk: "local",
        });
        expect(success).toHaveBeenCalledWith(
            'backend.ged.documents.relocation.all_queued {"queued":12,"skipped":3}',
        );
        expect(reload).toHaveBeenCalled();
        expect(all.pendingDisk.value).toBeNull();
    });

    /**
     * "Nothing to do" and "nothing happened" look identical from the outside,
     * and the reader who just pressed a button deserves to be told which.
     */
    it("says so when there was nothing left to move", async () => {
        request.mockResolvedValue({
            success: true,
            queued: 0,
            alreadyThere: 40,
        });

        const all = useDocumentRelocateAll(PROPS, vi.fn());
        all.askRelocateAll("r2");
        await all.confirmRelocateAll();

        expect(success).toHaveBeenCalledWith(
            "backend.ged.documents.relocation.all_nothing",
        );
    });

    it("keeps the confirmation open when the call fails", async () => {
        request.mockResolvedValue({ success: false });

        const all = useDocumentRelocateAll(PROPS, vi.fn());
        all.askRelocateAll("r2");
        await all.confirmRelocateAll();

        expect(error).toHaveBeenCalled();
        expect(all.pendingDisk.value).toBe("r2");
        expect(all.running.value).toBe(false);
    });
});
