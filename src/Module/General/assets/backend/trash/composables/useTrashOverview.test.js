import { describe, expect, it, vi, beforeEach } from "vitest";

const toast = { success: vi.fn(), error: vi.fn() };
const requests = [];
let response = { success: true };

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast }));
vi.mock("@/shared/nav/navMeta.js", () => ({
    resolveNavIcon: (name) => `icon:${name}`,
}));
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({
        request: (url, body, opts) => {
            requests.push({ url, body, method: opts?.method });

            return Promise.resolve(response);
        },
    }),
}));

const { useTrashOverview, daysLeft } = await import("./useTrashOverview.js");

const DAY = 86_400_000;

function props(overrides = {}) {
    return {
        listPath: "/backend/trash/list",
        retentionDays: 30,
        trashes: [
            {
                key: "ged_documents",
                labelKey: "backend.nav.documents",
                icon: "folder-open",
                count: 2,
                oldestDeletedAt: new Date(Date.now() - 4 * DAY).toISOString(),
                restorePath: "/backend/ged/documents/__id__/restore",
                forceDeletePath: "/backend/ged/documents/__id__/force-delete",
                emptyTrashPath: "/backend/ged/documents/empty-trash",
                items: [{ id: 7, label: "Contrat", deletedAt: null }],
            },
            {
                key: "notes_markdown",
                labelKey: "backend.nav.notes_markdown",
                icon: "notebook-pen",
                count: 0,
                oldestDeletedAt: null,
                items: [],
            },
        ],
        ...overrides,
    };
}

describe("daysLeft", () => {
    it("counts the days left before the purge takes the oldest row", () => {
        expect(daysLeft(new Date(Date.now() - 4 * DAY).toISOString(), 30)).toBe(
            26,
        );
    });

    it("says nothing when the trash is empty or the purge is off", () => {
        expect(daysLeft(null, 30)).toBeNull();
        expect(daysLeft(new Date().toISOString(), 0)).toBeNull();
    });

    it("never counts backwards, since the purge only runs at night", () => {
        expect(
            daysLeft(new Date(Date.now() - 40 * DAY).toISOString(), 30),
        ).toBe(0);
    });
});

describe("useTrashOverview", () => {
    beforeEach(() => {
        requests.length = 0;
        toast.success.mockClear();
        toast.error.mockClear();
        response = { success: true };
    });

    it("opens on the first trash, resolves its icon and totals everything", () => {
        const { rows, total, active } = useTrashOverview(props());

        expect(rows.value[0].iconComponent).toBe("icon:folder-open");
        expect(total.value).toBe(2);
        expect(active.value.key).toBe("ged_documents");
    });

    it("posts a restore to the address the module gave, then re-reads the list", async () => {
        const api = useTrashOverview(props());

        await api.restore(api.active.value, { id: 7 });

        expect(requests[0].url).toBe("/backend/ged/documents/7/restore");
        expect(requests[1].url).toBe("/backend/trash/list");
        expect(toast.success).toHaveBeenCalledWith("backend.trash.restored");
    });

    it("asks before destroying, and only then posts", async () => {
        const api = useTrashOverview(props());

        api.askForceDelete(api.active.value, { id: 7, label: "Contrat" });
        expect(requests).toHaveLength(0);

        await api.doForceDelete();

        expect(requests[0].url).toBe("/backend/ged/documents/7/force-delete");
        expect(api.pendingForceDelete.value).toBeNull();
    });

    it("shows the module's own refusal rather than a generic failure", async () => {
        response = {
            success: false,
            errors: { document: "Ce document est verrouillé." },
        };
        const api = useTrashOverview(props());

        await api.restore(api.active.value, { id: 7 });

        expect(toast.error).toHaveBeenCalledWith("Ce document est verrouillé.");
        // No reload: nothing moved.
        expect(requests).toHaveLength(1);
    });

    it("moves off a tab that disappeared while it was open", async () => {
        const api = useTrashOverview(props());
        response = {
            success: true,
            trashes: [
                {
                    key: "notes_markdown",
                    count: 1,
                    items: [],
                    icon: "notebook-pen",
                },
            ],
        };

        await api.reload();

        expect(api.activeKey.value).toBe("notes_markdown");
    });

    it("holds up with nothing contributed", () => {
        const { rows, total, active } = useTrashOverview(
            props({ trashes: [] }),
        );

        expect(rows.value).toEqual([]);
        expect(total.value).toBe(0);
        expect(active.value).toBeNull();
    });
});
