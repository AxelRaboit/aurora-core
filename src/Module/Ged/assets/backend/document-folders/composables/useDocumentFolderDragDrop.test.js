/**
 * @vitest-environment happy-dom
 */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useDocumentFolderDragDrop } from "@ged/backend/document-folders/composables/useDocumentFolderDragDrop.js";

const MOVE_PATH = "/backend/ged/folders/__id__/move";
const REORDER_PATH = "/backend/ged/folders/reorder";

function makeFolder(id, name, parentId = null) {
    return { id, name, parentId: parentId ?? null };
}

function makeDragEvent(overrides = {}) {
    return {
        dataTransfer: {
            effectAllowed: null,
            dropEffect: null,
            setData: vi.fn(),
            getData: vi.fn(),
        },
        preventDefault: vi.fn(),
        ...overrides,
    };
}

function makeDragOverEvent(ratioY = 0.5, overrides = {}) {
    return {
        currentTarget: {
            getBoundingClientRect: () => ({ top: 0, height: 100 }),
        },
        clientY: ratioY * 100,
        preventDefault: vi.fn(),
        dataTransfer: { dropEffect: null },
        ...overrides,
    };
}

function mountDragDrop(onSuccess = vi.fn()) {
    let api;
    const Comp = defineComponent({
        setup: () => {
            api = useDocumentFolderDragDrop(MOVE_PATH, REORDER_PATH, onSuccess);
            return () => h("div");
        },
    });
    mount(Comp, { global: { plugins: [createTestI18n()] } });
    return api;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe("useDocumentFolderDragDrop - onDragStart", () => {
    it("sets draggingId to the folder id", () => {
        const { draggingId, onDragStart } = mountDragDrop();
        const event = makeDragEvent();
        onDragStart(event, makeFolder(5, "Root"));
        expect(draggingId.value).toBe(5);
    });

    it("sets dataTransfer effectAllowed to move", () => {
        const { onDragStart } = mountDragDrop();
        const event = makeDragEvent();
        onDragStart(event, makeFolder(1, "A"));
        expect(event.dataTransfer.effectAllowed).toBe("move");
    });
});

describe("useDocumentFolderDragDrop - getZone (via onDragOver dropTarget)", () => {
    it("zone is 'before' when cursor is in top 40%", () => {
        const { draggingId, dropTarget, onDragStart, onDragOver } =
            mountDragDrop();
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        onDragOver(makeDragOverEvent(0.2), makeFolder(2, "B"));
        expect(dropTarget.value?.zone).toBe("before");
    });

    it("zone is 'into' when cursor is in middle 40%", () => {
        const { draggingId, dropTarget, onDragStart, onDragOver } =
            mountDragDrop();
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        onDragOver(makeDragOverEvent(0.5), makeFolder(2, "B"));
        expect(dropTarget.value?.zone).toBe("into");
    });

    it("zone is 'after' when cursor is in bottom 30%", () => {
        const { draggingId, dropTarget, onDragStart, onDragOver } =
            mountDragDrop();
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        onDragOver(makeDragOverEvent(0.85), makeFolder(2, "B"));
        expect(dropTarget.value?.zone).toBe("after");
    });
});

describe("useDocumentFolderDragDrop - onDragOver", () => {
    it("does not set dropTarget when dragging over self", () => {
        const { dropTarget, onDragStart, onDragOver } = mountDragDrop();
        onDragStart(makeDragEvent(), makeFolder(3, "A"));
        onDragOver(makeDragOverEvent(0.5), makeFolder(3, "A"));
        expect(dropTarget.value).toBeNull();
    });

    it("sets dropTarget to the hovered folder", () => {
        const { dropTarget, onDragStart, onDragOver } = mountDragDrop();
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        onDragOver(makeDragOverEvent(0.5), makeFolder(2, "B"));
        expect(dropTarget.value?.id).toBe(2);
    });
});

describe("useDocumentFolderDragDrop - onDragEnd", () => {
    it("resets draggingId and dropTarget", () => {
        const { draggingId, dropTarget, onDragStart, onDragOver, onDragEnd } =
            mountDragDrop();
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        onDragOver(makeDragOverEvent(0.5), makeFolder(2, "B"));
        onDragEnd();
        expect(draggingId.value).toBeNull();
        expect(dropTarget.value).toBeNull();
    });
});

describe("useDocumentFolderDragDrop - onDrop", () => {
    it("calls reparent (POST to movePath) when zone is 'into'", async () => {
        const onSuccess = vi.fn();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ success: true, folders: [] }),
        });
        vi.stubGlobal("fetch", fetchMock);

        const { onDragStart, onDrop } = mountDragDrop(onSuccess);
        const dragFolder = makeFolder(1, "A");
        const targetFolder = makeFolder(2, "B");
        const flatTree = [dragFolder, targetFolder];

        onDragStart(makeDragEvent(), dragFolder);

        const dropEvent = makeDragOverEvent(0.5);
        await onDrop(dropEvent, targetFolder, flatTree);

        const calledUrl = fetchMock.mock.calls[0][0];
        expect(calledUrl).toContain("1");
    });

    it("calls reorder (POST to reorderPath) when zone is 'before' and same parent", async () => {
        const onSuccess = vi.fn();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ success: true, folders: [] }),
        });
        vi.stubGlobal("fetch", fetchMock);

        const { onDragStart, onDrop } = mountDragDrop(onSuccess);
        const folderA = makeFolder(1, "A", null);
        const folderB = makeFolder(2, "B", null);
        const flatTree = [folderA, folderB];

        onDragStart(makeDragEvent(), folderA);

        const dropEvent = makeDragOverEvent(0.2); // top = 'before'
        await onDrop(dropEvent, folderB, flatTree);

        const calledUrl = fetchMock.mock.calls[0][0];
        expect(calledUrl).toBe(REORDER_PATH);
    });

    it("calls onSuccess callback after successful reparent", async () => {
        const onSuccess = vi.fn();
        const updatedFolders = [makeFolder(1, "A"), makeFolder(2, "B")];
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ success: true, folders: updatedFolders }),
            }),
        );

        const { onDragStart, onDrop } = mountDragDrop(onSuccess);
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        await onDrop(makeDragOverEvent(0.5), makeFolder(2, "B"), [
            makeFolder(1, "A"),
            makeFolder(2, "B"),
        ]);

        expect(onSuccess).toHaveBeenCalledWith(updatedFolders);
    });

    it("does not call onSuccess when server returns success: false", async () => {
        const onSuccess = vi.fn();
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ success: false }),
            }),
        );

        const { onDragStart, onDrop } = mountDragDrop(onSuccess);
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        await onDrop(makeDragOverEvent(0.5), makeFolder(2, "B"), [
            makeFolder(1, "A"),
            makeFolder(2, "B"),
        ]);

        expect(onSuccess).not.toHaveBeenCalled();
    });

    it("does nothing when dragged id equals target id", async () => {
        const onSuccess = vi.fn();
        const fetchMock = vi.fn();
        vi.stubGlobal("fetch", fetchMock);

        const { onDragStart, onDrop } = mountDragDrop(onSuccess);
        onDragStart(makeDragEvent(), makeFolder(1, "A"));
        await onDrop(makeDragOverEvent(0.5), makeFolder(1, "A"), [
            makeFolder(1, "A"),
        ]);

        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe("useDocumentFolderDragDrop - reorderSiblings insert position", () => {
    it("inserts dragged folder before target when zone is 'before'", async () => {
        let capturedBody;
        vi.stubGlobal(
            "fetch",
            vi.fn().mockImplementation(async (url, opts) => {
                capturedBody = JSON.parse(opts.body);
                return {
                    ok: true,
                    json: async () => ({ success: true, folders: [] }),
                };
            }),
        );

        const { onDragStart, onDrop } = mountDragDrop();
        const folderA = makeFolder(1, "A", null);
        const folderB = makeFolder(2, "B", null);
        const folderC = makeFolder(3, "C", null);
        const flatTree = [folderA, folderB, folderC];

        onDragStart(makeDragEvent(), folderC); // drag C
        await onDrop(makeDragOverEvent(0.2), folderB, flatTree); // drop before B

        // Expected order: A, C, B
        expect(capturedBody.ids).toEqual([1, 3, 2]);
    });

    it("inserts dragged folder after target when zone is 'after'", async () => {
        let capturedBody;
        vi.stubGlobal(
            "fetch",
            vi.fn().mockImplementation(async (url, opts) => {
                capturedBody = JSON.parse(opts.body);
                return {
                    ok: true,
                    json: async () => ({ success: true, folders: [] }),
                };
            }),
        );

        const { onDragStart, onDrop } = mountDragDrop();
        const folderA = makeFolder(1, "A", null);
        const folderB = makeFolder(2, "B", null);
        const folderC = makeFolder(3, "C", null);
        const flatTree = [folderA, folderB, folderC];

        onDragStart(makeDragEvent(), folderA); // drag A
        await onDrop(makeDragOverEvent(0.85), folderB, flatTree); // drop after B

        // Expected order: B, A, C
        expect(capturedBody.ids).toEqual([2, 1, 3]);
    });
});
