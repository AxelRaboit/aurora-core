import { describe, expect, it, vi } from "vitest";
import {
    NONE,
    useDocumentFilters,
} from "@ged/suite/documents/composables/useDocumentFilters.js";

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

describe("useDocumentFilters - the trash is not here any more", () => {
    it("never asks the server for trashed rows", () => {
        const { extraParams } = useDocumentFilters(vi.fn());

        // The listing shows the library, full stop. What was deleted lives on
        // the Trash screen, which reads it from its own sources.
        expect(extraParams().trashed).toBeUndefined();
    });
});

describe("useDocumentFilters - families", () => {
    it("folds families by default, without a word in the address", () => {
        window.history.replaceState(null, "", "/suite/ged/documents");
        const { filterOriginalsOnly, extraParams, hasActiveFilter } =
            useDocumentFilters(vi.fn());

        expect(filterOriginalsOnly.value).toBe(true);
        expect(extraParams().originalsOnly).toBe(1);
        expect(hasActiveFilter.value).toBe(false);
        expect(window.location.search).not.toContain("familles");
    });

    it("keeps the flat view in the address, and counts it as a filter", () => {
        window.history.replaceState(null, "", "/suite/ged/documents");
        const { filterOriginalsOnly, extraParams, hasActiveFilter } =
            useDocumentFilters(vi.fn());

        filterOriginalsOnly.value = false;

        expect(extraParams().originalsOnly).toBeUndefined();
        expect(hasActiveFilter.value).toBe(true);
        expect(window.location.search).toContain("familles=0");
    });

    it("reads the flat view back from a shared link", () => {
        window.history.replaceState(
            null,
            "",
            "/suite/ged/documents?familles=0",
        );
        const { filterOriginalsOnly } = useDocumentFilters(vi.fn());

        expect(filterOriginalsOnly.value).toBe(false);
    });

    it("goes back to folded on reset", () => {
        window.history.replaceState(
            null,
            "",
            "/suite/ged/documents?familles=0",
        );
        const { filterOriginalsOnly, resetFilters } = useDocumentFilters(
            vi.fn(),
        );

        resetFilters();

        expect(filterOriginalsOnly.value).toBe(true);
        expect(window.location.search).not.toContain("familles");
    });
});

describe("useDocumentFilters - wider search", () => {
    it("sends nothing new while everything is at rest", () => {
        const { extraParams, moreFiltersCount, hasActiveFilter } =
            useDocumentFilters(vi.fn());
        const params = extraParams();

        expect(params.searchIn).toBeUndefined();
        expect(params.addedFrom).toBeUndefined();
        expect(params.orientation).toBeUndefined();
        expect(params.weight).toBeUndefined();
        expect(moreFiltersCount.value).toBe(0);
        expect(hasActiveFilter.value).toBe(false);
    });

    it("sends each one it is given, and counts them", () => {
        const {
            searchIn,
            filterAddedFrom,
            filterAddedTo,
            filterOrientation,
            filterWeight,
            extraParams,
            moreFiltersCount,
            hasActiveFilter,
        } = useDocumentFilters(vi.fn());
        searchIn.value = "file";
        filterAddedFrom.value = "2026-09-01";
        filterAddedTo.value = "2026-09-30";
        filterOrientation.value = "portrait";
        filterWeight.value = "heavy";

        expect(extraParams()).toMatchObject({
            searchIn: "file",
            addedFrom: "2026-09-01",
            addedTo: "2026-09-30",
            orientation: "portrait",
            weight: "heavy",
        });
        expect(moreFiltersCount.value).toBe(5);
        expect(hasActiveFilter.value).toBe(true);
    });

    it("passes « none » through for documents without a category or a tag", () => {
        const { filterCategoryId, filterTagId, extraParams } =
            useDocumentFilters(vi.fn());
        filterCategoryId.value = NONE;
        filterTagId.value = NONE;

        expect(extraParams()).toMatchObject({
            categoryId: "none",
            tagId: "none",
        });
    });

    it("puts every one back on reset", () => {
        const reload = vi.fn();
        const {
            searchIn,
            filterWeight,
            filterAddedFrom,
            resetFilters,
            moreFiltersCount,
        } = useDocumentFilters(reload);
        searchIn.value = "text";
        filterWeight.value = "light";
        filterAddedFrom.value = "2026-09-01";

        resetFilters();

        expect(searchIn.value).toBe("all");
        expect(moreFiltersCount.value).toBe(0);
        expect(reload).toHaveBeenCalled();
    });
});
