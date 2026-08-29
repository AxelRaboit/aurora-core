import { describe, it, expect, vi, beforeEach } from "vitest";

const requestMock = vi.fn();
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({
        loading: { value: false },
        request: requestMock,
    }),
}));

const { useMarkdownNotesApi } = await import("./useMarkdownNotesApi.js");

const props = {
    listPath: "/api/notes",
    showPath: "/api/notes/__id__",
    createPath: "/api/notes/create",
    updatePath: "/api/notes/__id__/update",
    deletePath: "/api/notes/__id__/delete",
    movePath: "/api/notes/__id__/move",
    reorderPath: "/api/notes/reorder",
    backlinksPath: "/api/notes/__id__/backlinks",
    unlinkedMentionsPath: "/api/notes/__id__/mentions",
    graphPath: "/api/notes/graph",
    searchPath: "/api/notes/search",
    imageUploadPath: "/api/notes/images",
};

beforeEach(() => requestMock.mockReset());

/**
 * This layer called `fetch` by hand until now, which omitted
 * `X-Requested-With` - the header Symfony reads to answer JSON rather than an
 * HTML page. Going through `useRequest` is what puts it back, so these tests
 * are about the contract with it rather than about the URLs.
 */
describe("useMarkdownNotesApi", () => {
    it("goes through useRequest rather than calling fetch", async () => {
        requestMock.mockResolvedValue({ success: true, notes: [] });

        await useMarkdownNotesApi(props).list();

        expect(requestMock).toHaveBeenCalledWith(
            "/api/notes",
            null,
            expect.objectContaining({ method: "GET" }),
        );
    });

    it("skips the loading guard so concurrent calls are not dropped", async () => {
        // The page lists, shows and searches at once. `useRequest`'s guard is
        // right for a form button and wrong here: a silently dropped call would
        // look like a note that failed to open.
        requestMock.mockResolvedValue({ success: true });

        await useMarkdownNotesApi(props).show(7);

        expect(requestMock).toHaveBeenCalledWith(
            "/api/notes/7",
            null,
            expect.objectContaining({ noGuard: true }),
        );
    });

    it("substitutes the id placeholder", async () => {
        requestMock.mockResolvedValue({ success: true });

        await useMarkdownNotesApi(props).move(3, 9);

        expect(requestMock).toHaveBeenCalledWith(
            "/api/notes/3/move",
            { parentId: 9 },
            expect.anything(),
        );
    });

    it("reports a null answer as already reported, so callers stay quiet", async () => {
        // Null is transport or 5xx, and `useRequest` has toasted. A caller that
        // toasts again stacks two messages over each other - the defect this
        // flag exists to prevent.
        requestMock.mockResolvedValue(null);

        const result = await useMarkdownNotesApi(props).list();

        expect(result).toEqual({ ok: false, reported: true, payload: {} });
    });

    it("leaves a business failure to the caller to name", async () => {
        // A 422 comes back as a body: `useRequest` says nothing about it, so the
        // caller has to, and `reported` is false to say so.
        requestMock.mockResolvedValue({ success: false, error: "cycle" });

        const result = await useMarkdownNotesApi(props).move(1, 2);

        expect(result.ok).toBe(false);
        expect(result.reported).toBe(false);
        expect(result.payload.error).toBe("cycle");
    });

    it("uploads an image as a raw body so the browser writes the boundary", async () => {
        // The one Content-Type that must not be set by hand: a multipart header
        // without its boundary makes the body unparseable server-side.
        requestMock.mockResolvedValue({ success: true, filename: "a.webp" });

        await useMarkdownNotesApi(props).uploadImage(new Blob(["x"]));

        const [url, body, opts] = requestMock.mock.calls[0];
        expect(url).toBe("/api/notes/images");
        expect(body).toBeNull();
        expect(opts.rawBody).toBeInstanceOf(FormData);
    });
});
