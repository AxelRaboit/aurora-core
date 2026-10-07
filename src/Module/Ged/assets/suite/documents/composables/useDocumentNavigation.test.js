import { describe, expect, it, vi } from "vitest";
import { useDocumentNavigation } from "@ged/suite/documents/composables/useDocumentNavigation.js";

describe("useDocumentNavigation - the address", () => {
    it("keeps the search, the sort and the flat view when changing folder", async () => {
        window.history.replaceState(
            null,
            "",
            "/suite/ged/documents?search=carte&sort=name&dir=asc&familles=0&all=1",
        );
        const { navigateTo } = useDocumentNavigation(
            { folders: [] },
            vi.fn(),
            vi.fn(),
        );

        await navigateTo(7);

        const searchParameters = new URLSearchParams(window.location.search);
        expect(searchParameters.get("folderId")).toBe("7");
        expect(searchParameters.get("all")).toBeNull();
        expect(searchParameters.get("search")).toBe("carte");
        expect(searchParameters.get("sort")).toBe("name");
        expect(searchParameters.get("dir")).toBe("asc");
        expect(searchParameters.get("familles")).toBe("0");
    });

    it("drops the previous folder when going back to every document", async () => {
        window.history.replaceState(
            null,
            "",
            "/suite/ged/documents?folderId=7&familles=0",
        );
        const { navigateToAll } = useDocumentNavigation(
            { folders: [] },
            vi.fn(),
            vi.fn(),
        );

        await navigateToAll();

        const searchParameters = new URLSearchParams(window.location.search);
        expect(searchParameters.get("folderId")).toBeNull();
        expect(searchParameters.get("all")).toBe("1");
        expect(searchParameters.get("familles")).toBe("0");
    });
});
