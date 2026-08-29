import { describe, it, expect, vi, beforeEach } from "vitest";

const requestMock = vi.fn();
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({
        loading: { value: false },
        request: requestMock,
    }),
}));

const { useMarkdownTagsApi } = await import("./useMarkdownTagsApi.js");

const props = {
    tagsListPath: "/api/tags",
    tagsRenamePath: "/api/tags/rename",
    tagsMergePath: "/api/tags/merge",
    tagsDeletePath: "/api/tags/delete",
};

beforeEach(() => {
    requestMock.mockReset();
});

describe("useMarkdownTagsApi", () => {
    it("issues a GET on list", async () => {
        requestMock.mockResolvedValue({ success: true, tags: [] });
        const api = useMarkdownTagsApi(props);

        await api.list();

        expect(requestMock).toHaveBeenCalledWith("/api/tags", null, "GET");
    });

    it("posts oldTag and newTag on rename", async () => {
        requestMock.mockResolvedValue({ success: true, affected: 2 });
        const api = useMarkdownTagsApi(props);

        await api.rename("old", "new");

        expect(requestMock).toHaveBeenCalledWith("/api/tags/rename", {
            oldTag: "old",
            newTag: "new",
        });
    });

    it("posts sourceTags and targetTag on merge", async () => {
        requestMock.mockResolvedValue({ success: true, affected: 3 });
        const api = useMarkdownTagsApi(props);

        await api.merge(["a", "b"], "c");

        expect(requestMock).toHaveBeenCalledWith("/api/tags/merge", {
            sourceTags: ["a", "b"],
            targetTag: "c",
        });
    });

    it("posts tag on remove", async () => {
        requestMock.mockResolvedValue({ success: true, affected: 1 });
        const api = useMarkdownTagsApi(props);

        await api.remove("gone");

        expect(requestMock).toHaveBeenCalledWith("/api/tags/delete", {
            tag: "gone",
        });
    });
});
