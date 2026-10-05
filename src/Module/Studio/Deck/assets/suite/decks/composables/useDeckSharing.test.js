import { beforeEach, describe, expect, it, vi } from "vitest";
import { toast } from "vue-sonner";
import { useDeckSharing } from "./useDeckSharing.js";

const request = vi.fn();

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));
vi.mock("vue-sonner", () => ({
    toast: { error: vi.fn(), success: vi.fn(), message: vi.fn() },
}));
vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

/**
 * The share panel of a presentation obeys the rule every Studio link obeys: a
 * link nobody opened is deleted, one that was opened is only revoked, and what
 * the server refuses is said in words rather than swallowed.
 */
const props = {
    shareCreatePath: "/d/1/share/create",
    shareRevokePath: "/d/1/share/__linkId__/revoke",
    shareDeletePath: "/d/1/share/__linkId__/delete",
    shareLinks: [
        { id: 1, openCount: 0 },
        { id: 2, openCount: 4 },
    ],
};

describe("useDeckSharing", () => {
    beforeEach(() => {
        request.mockReset();
        toast.error.mockReset();
        toast.success.mockReset();
    });

    it("tells a link that can be deleted from one that can only be revoked", () => {
        const { isDeletable } = useDeckSharing(props);

        expect(isDeletable({ openCount: 0 })).toBe(true);
        expect(isDeletable({ openCount: 1 })).toBe(false);
    });

    it("deletes through its own address and redraws the list from the answer", async () => {
        request.mockResolvedValueOnce({
            success: true,
            shareLinks: [{ id: 2, openCount: 4 }],
        });
        const { remove, links } = useDeckSharing(props);

        await remove({ id: 1, openCount: 0 });

        expect(request).toHaveBeenCalledWith("/d/1/share/1/delete");
        expect(links.value).toEqual([{ id: 2, openCount: 4 }]);
        expect(toast.success).toHaveBeenCalledWith(
            "suite.studio.decks.share_deleted_toast",
        );
    });

    it("says why a link could not be deleted and keeps the list", async () => {
        request.mockResolvedValueOnce({
            success: false,
            errors: { link: "suite.studio.sharing.errors.link_opened" },
        });
        const { remove, links } = useDeckSharing(props);

        await remove({ id: 1, openCount: 0 });

        expect(toast.error).toHaveBeenCalledWith(
            "suite.studio.sharing.errors.link_opened",
        );
        expect(links.value).toHaveLength(2);
    });

    it("says what the rules refused when a link is created", async () => {
        request.mockResolvedValueOnce({
            success: false,
            errors: {
                expiresInDays: "suite.studio.sharing.errors.expiry_invalid",
            },
        });
        const { createLink, expiresInDays } = useDeckSharing(props);
        expiresInDays.value = "400";

        await createLink();

        expect(toast.error).toHaveBeenCalledWith(
            "suite.studio.sharing.errors.expiry_invalid",
        );
    });

    it("hides only a retired or expired link that was opened", () => {
        const { isHideable } = useDeckSharing(props);
        const live = { id: 1, openCount: 3, revokedAt: null, expiresAt: null };

        expect(isHideable(live)).toBe(false);
        expect(
            isHideable({ ...live, revokedAt: "2026-10-05T10:00:00+00:00" }),
        ).toBe(true);
        expect(
            isHideable({ ...live, expiresAt: "2020-01-01T00:00:00+00:00" }),
        ).toBe(true);
        expect(
            isHideable({
                ...live,
                revokedAt: "2026-10-05T10:00:00+00:00",
                openCount: 0,
            }),
        ).toBe(false);
        expect(
            isHideable({
                ...live,
                revokedAt: "2026-10-05T10:00:00+00:00",
                hidden: true,
            }),
        ).toBe(false);
    });

    it("hides through its own address and keeps the hidden ones out of the shown list", async () => {
        const rows = [
            {
                id: 1,
                openCount: 3,
                revokedAt: "2026-10-05T10:00:00+00:00",
                hidden: true,
            },
            { id: 2, openCount: 1, revokedAt: null },
        ];
        request.mockResolvedValueOnce({ success: true, shareLinks: rows });
        const { hide, shownLinks, hiddenCount, showHidden } = useDeckSharing({
            ...props,
            shareHidePath: "/d/1/share/__linkId__/hide",
        });

        await hide({ id: 1 });

        expect(request).toHaveBeenCalledWith("/d/1/share/1/hide");
        expect(hiddenCount.value).toBe(1);
        expect(shownLinks.value.map((link) => link.id)).toEqual([2]);
        showHidden.value = true;
        expect(shownLinks.value.map((link) => link.id)).toEqual([1, 2]);
    });
});
