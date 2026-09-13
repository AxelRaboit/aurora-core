import { describe, it, expect, vi, beforeEach } from "vitest";

const toast = { success: vi.fn(), error: vi.fn() };
let response = { success: true };

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast }));
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request: () => Promise.resolve(response) }),
}));

const { useDelete } = await import("./useDelete.js");

describe("useDelete", () => {
    beforeEach(() => {
        toast.success.mockClear();
        toast.error.mockClear();
        response = { success: true };
    });

    it("removes the row and says so", async () => {
        const onSuccess = vi.fn();
        const { confirm, submit, pendingDelete } = useDelete(
            "/x/__id__",
            onSuccess,
            "deleted",
        );

        confirm({ id: 7 });
        await submit();

        expect(onSuccess).toHaveBeenCalledWith(7);
        expect(toast.success).toHaveBeenCalledWith("deleted");
        expect(pendingDelete.value).toBeNull();
    });

    it("shows the reason when the server refuses, and keeps the row", async () => {
        response = {
            success: false,
            errors: { customer: "Ce client a 2 contrats." },
        };
        const onSuccess = vi.fn();
        const { confirm, submit, pendingDelete } = useDelete(
            "/x/__id__",
            onSuccess,
            "deleted",
        );

        confirm({ id: 7 });
        await submit();

        expect(onSuccess).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith("Ce client a 2 contrats.");
        expect(pendingDelete.value).toBeNull();
    });

    it("falls back to the generic error when the refusal names nothing", async () => {
        response = { success: false };
        const { confirm, submit } = useDelete("/x/__id__", vi.fn(), "deleted");

        confirm({ id: 7 });
        await submit();

        expect(toast.error).toHaveBeenCalledWith("shared.common.error");
    });
});
