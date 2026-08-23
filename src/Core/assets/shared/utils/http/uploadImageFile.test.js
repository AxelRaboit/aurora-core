import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { IMAGE_UPLOAD_ENDPOINT, uploadImageFile } from "./uploadImageFile.js";

function jsonResponse(body, ok = true) {
    return { ok, json: async () => body };
}

const filed = {
    success: true,
    document: { id: 42, fileUrl: "/uploads/ged/2026/08/pixel.png" },
};

describe("uploadImageFile", () => {
    beforeEach(() => {
        globalThis.fetch = vi.fn();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("returns the filed document", async () => {
        globalThis.fetch.mockResolvedValue(jsonResponse(filed));

        await expect(uploadImageFile(new Blob(["x"]))).resolves.toEqual({
            id: 42,
            url: "/uploads/ged/2026/08/pixel.png",
        });
    });

    /**
     * The block editor posted to /backend/media/media/upload for months after
     * that route was removed with the Media module. Nothing failed loudly -
     * the request 404'd and the image simply never appeared.
     */
    it("posts to the endpoint that exists", async () => {
        globalThis.fetch.mockResolvedValue(jsonResponse(filed));

        await uploadImageFile(new Blob(["x"]));

        expect(globalThis.fetch).toHaveBeenCalledWith(
            IMAGE_UPLOAD_ENDPOINT,
            expect.objectContaining({ method: "POST" }),
        );
        expect(IMAGE_UPLOAD_ENDPOINT).toBe(
            "/backend/ged/documents/upload-image",
        );
    });

    /** The endpoint reads `file`. This was `image` here, against an endpoint that never called it that. */
    it("sends the file under the field name the endpoint reads", async () => {
        globalThis.fetch.mockResolvedValue(jsonResponse(filed));

        await uploadImageFile(new Blob(["x"]));

        const body = globalThis.fetch.mock.calls[0][1].body;

        expect(body).toBeInstanceOf(FormData);
        expect(body.get("file")).not.toBeNull();
        expect(body.get("image")).toBeNull();
    });

    it("accepts an endpoint override", async () => {
        globalThis.fetch.mockResolvedValue(jsonResponse(filed));

        await uploadImageFile(new Blob(["x"]), "/ailleurs");

        expect(globalThis.fetch).toHaveBeenCalledWith(
            "/ailleurs",
            expect.anything(),
        );
    });

    it("returns null when the server refuses the file", async () => {
        globalThis.fetch.mockResolvedValue(jsonResponse({ success: false }));

        await expect(uploadImageFile(new Blob(["x"]))).resolves.toBeNull();
    });

    it("returns null on an error status", async () => {
        globalThis.fetch.mockResolvedValue(jsonResponse(filed, false));

        await expect(uploadImageFile(new Blob(["x"]))).resolves.toBeNull();
    });

    /**
     * A success carrying no url is the shape drift that would put an empty
     * `<img>` on the page rather than reporting a failure.
     */
    it("returns null when the body is not the shape agreed", async () => {
        globalThis.fetch.mockResolvedValue(
            jsonResponse({ success: true, document: { id: 42 } }),
        );

        await expect(uploadImageFile(new Blob(["x"]))).resolves.toBeNull();
    });

    it("returns null rather than throwing when the network fails", async () => {
        globalThis.fetch.mockRejectedValue(new Error("offline"));

        await expect(uploadImageFile(new Blob(["x"]))).resolves.toBeNull();
    });
});
