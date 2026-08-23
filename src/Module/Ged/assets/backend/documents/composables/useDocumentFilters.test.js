import { describe, expect, it, vi } from "vitest";
import { useDocumentFilters } from "@ged/backend/documents/composables/useDocumentFilters.js";

describe("useDocumentFilters - initial state", () => {
    it("starts with all filters null", () => {
        const { filterCategoryId, filterTagId, filterFolderId, filterStatus } =
            useDocumentFilters(vi.fn());

        expect(filterCategoryId.value).toBeNull();
        expect(filterTagId.value).toBeNull();
        expect(filterFolderId.value).toBeNull();
        expect(filterStatus.value).toBeNull();
    });

    it("hasActiveFilter is false when all filters are null", () => {
        const { hasActiveFilter } = useDocumentFilters(vi.fn());
        expect(hasActiveFilter.value).toBe(false);
    });
});

describe("useDocumentFilters - hasActiveFilter", () => {
    it("becomes true when categoryId is set", () => {
        const { filterCategoryId, hasActiveFilter } = useDocumentFilters(
            vi.fn(),
        );
        filterCategoryId.value = 3;
        expect(hasActiveFilter.value).toBe(true);
    });

    it("becomes true when tagId is set", () => {
        const { filterTagId, hasActiveFilter } = useDocumentFilters(vi.fn());
        filterTagId.value = 1;
        expect(hasActiveFilter.value).toBe(true);
    });

    it("becomes true when folderId is set", () => {
        const { filterFolderId, hasActiveFilter } = useDocumentFilters(vi.fn());
        filterFolderId.value = 7;
        expect(hasActiveFilter.value).toBe(true);
    });

    it("becomes true when status is set", () => {
        const { filterStatus, hasActiveFilter } = useDocumentFilters(vi.fn());
        filterStatus.value = "published";
        expect(hasActiveFilter.value).toBe(true);
    });

    it("returns to false after all filters are cleared", () => {
        const { filterCategoryId, filterStatus, hasActiveFilter } =
            useDocumentFilters(vi.fn());
        filterCategoryId.value = 1;
        filterStatus.value = "draft";
        filterCategoryId.value = null;
        filterStatus.value = null;
        expect(hasActiveFilter.value).toBe(false);
    });
});

describe("useDocumentFilters - extraParams", () => {
    it("returns undefined for null filters", () => {
        const { extraParams } = useDocumentFilters(vi.fn());
        const params = extraParams();
        expect(params.categoryId).toBeUndefined();
        expect(params.tagId).toBeUndefined();
        expect(params.folderId).toBeUndefined();
        expect(params.status).toBeUndefined();
    });

    it("includes set filter values", () => {
        const {
            filterCategoryId,
            filterTagId,
            filterFolderId,
            filterStatus,
            extraParams,
        } = useDocumentFilters(vi.fn());

        filterCategoryId.value = 2;
        filterTagId.value = 5;
        filterFolderId.value = 8;
        filterStatus.value = "archived";

        const params = extraParams();
        expect(params.categoryId).toBe(2);
        expect(params.tagId).toBe(5);
        expect(params.folderId).toBe(8);
        expect(params.status).toBe("archived");
    });

    it("excludes filter that is back to null", () => {
        const { filterCategoryId, extraParams } = useDocumentFilters(vi.fn());
        filterCategoryId.value = 3;
        filterCategoryId.value = null;
        expect(extraParams().categoryId).toBeUndefined();
    });
});

describe("useDocumentFilters - applyFilter / resetFilters", () => {
    it("applyFilter calls reload once", () => {
        const reload = vi.fn();
        const { applyFilter } = useDocumentFilters(reload);
        applyFilter();
        expect(reload).toHaveBeenCalledOnce();
    });

    it("resetFilters clears all filter values", () => {
        const reload = vi.fn();
        const {
            filterCategoryId,
            filterTagId,
            filterFolderId,
            filterStatus,
            resetFilters,
        } = useDocumentFilters(reload);

        filterCategoryId.value = 1;
        filterTagId.value = 2;
        filterFolderId.value = 3;
        filterStatus.value = "draft";

        resetFilters();

        expect(filterCategoryId.value).toBeNull();
        expect(filterTagId.value).toBeNull();
        expect(filterFolderId.value).toBeNull();
        expect(filterStatus.value).toBeNull();
    });

    it("resetFilters calls reload", () => {
        const reload = vi.fn();
        const { resetFilters } = useDocumentFilters(reload);
        resetFilters();
        expect(reload).toHaveBeenCalledOnce();
    });
});
