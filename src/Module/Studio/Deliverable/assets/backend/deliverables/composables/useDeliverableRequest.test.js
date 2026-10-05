import { beforeEach, describe, expect, it, vi } from "vitest";
import { toast } from "vue-sonner";
import { useDeliverableRequest } from "./useDeliverableRequest.js";

const request = vi.fn();

vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));
vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

/**
 * Ce qu'une action de liste dit quand le serveur refuse : elle recevait
 * `success: false` et ne disait rien, la ligne périmée restant à l'écran.
 */
describe("useDeliverableRequest", () => {
    beforeEach(() => {
        request.mockReset();
        toast.error.mockReset();
    });

    it("hands a successful answer back without a word", async () => {
        request.mockResolvedValue({ success: true, personal: [] });

        const data = await useDeliverableRequest().send("/x", { a: 1 });

        expect(data.success).toBe(true);
        expect(toast.error).not.toHaveBeenCalled();
        // 403 and 404 are answers, not breakdowns.
        expect(request).toHaveBeenCalledWith(
            "/x",
            { a: 1 },
            { accept: [403, 404] },
        );
    });

    it("says nothing more when the request itself failed: useRequest already did", async () => {
        request.mockResolvedValue(null);

        expect(await useDeliverableRequest().send("/x")).toBeNull();
        expect(toast.error).not.toHaveBeenCalled();
    });

    it("tells that a deliverable is gone and refreshes the list", async () => {
        const onList = vi.fn();
        request
            .mockResolvedValueOnce({ success: false, error: "not_found" })
            .mockResolvedValueOnce({
                success: true,
                personal: [1],
                shared: [],
            });

        await useDeliverableRequest({ listPath: "/lists", onList }).send(
            "/x/delete",
        );

        expect(toast.error).toHaveBeenCalledWith(
            "backend.studio.deliverables.gone",
        );
        expect(request).toHaveBeenLastCalledWith("/lists", null, {
            method: "GET",
            silent: true,
        });
        expect(onList).toHaveBeenCalledWith({
            success: true,
            personal: [1],
            shared: [],
        });
    });

    it("tells that the right is gone, and refreshes too", async () => {
        const onList = vi.fn();
        request
            .mockResolvedValueOnce({ success: false, error: "forbidden" })
            .mockResolvedValueOnce({ success: true });

        await useDeliverableRequest({ listPath: "/lists", onList }).send(
            "/x/scope",
        );

        expect(toast.error).toHaveBeenCalledWith(
            "backend.studio.deliverables.not_allowed",
        );
        expect(onList).toHaveBeenCalled();
    });

    it("does not refresh for a refusal that says nothing about staleness", async () => {
        const onList = vi.fn();
        request.mockResolvedValue({
            success: false,
            errors: {
                title: "backend.studio.deliverables.errors.title_required",
            },
        });

        await useDeliverableRequest({ listPath: "/lists", onList }).send(
            "/x/create",
        );

        // The first field error, translated, instead of a generic message.
        expect(toast.error).toHaveBeenCalledWith(
            "backend.studio.deliverables.errors.title_required",
        );
        expect(onList).not.toHaveBeenCalled();
    });

    it("falls back to the generic message for an unexplained refusal", async () => {
        request.mockResolvedValue({ success: false });

        await useDeliverableRequest().send("/x");

        expect(toast.error).toHaveBeenCalledWith("shared.common.error");
    });

    it("leaves a code the caller handles itself to the caller", async () => {
        request.mockResolvedValue({
            success: false,
            error: "confirmation_needed",
            placeholders: 2,
        });

        const data = await useDeliverableRequest().send(
            "/x/visibility",
            {},
            { own: ["confirmation_needed"] },
        );

        expect(data.error).toBe("confirmation_needed");
        expect(toast.error).not.toHaveBeenCalled();
    });

    it("does nothing to refresh when no route was given", async () => {
        request.mockResolvedValue({ success: false, error: "not_found" });

        await useDeliverableRequest().send("/x");

        expect(request).toHaveBeenCalledTimes(1);
    });
});
