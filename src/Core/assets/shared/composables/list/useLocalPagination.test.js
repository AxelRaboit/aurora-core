import { describe, it, expect } from "vitest";
import { ref } from "vue";
import { useLocalPagination } from "./useLocalPagination.js";

const ITEMS = Array.from({ length: 25 }, (_, index) => ({ id: index + 1 }));

describe("useLocalPagination", () => {
    it("paginates a plain array, first page contains perPage items", () => {
        const { paginatedItems, totalPages } = useLocalPagination(ITEMS, 10);
        expect(paginatedItems.value).toHaveLength(10);
        expect(paginatedItems.value[0].id).toBe(1);
        expect(totalPages.value).toBe(3);
    });

    it("paginates a reactive ref source", () => {
        const source = ref(ITEMS);
        const { paginatedItems, totalPages } = useLocalPagination(source, 10);
        expect(paginatedItems.value).toHaveLength(10);
        expect(totalPages.value).toBe(3);
    });

    it("goToPage changes the current page and updates paginatedItems", () => {
        const { paginatedItems, goToPage } = useLocalPagination(ITEMS, 10);
        goToPage(2);
        expect(paginatedItems.value[0].id).toBe(11);
        expect(paginatedItems.value).toHaveLength(10);
    });

    it("last page contains the remainder", () => {
        const { paginatedItems, goToPage } = useLocalPagination(ITEMS, 10);
        goToPage(3);
        expect(paginatedItems.value).toHaveLength(5);
        expect(paginatedItems.value[0].id).toBe(21);
    });

    it("reacts when the source ref changes", () => {
        const source = ref(ITEMS.slice(0, 5));
        const { paginatedItems, totalPages } = useLocalPagination(source, 10);
        expect(totalPages.value).toBe(1);
        source.value = ITEMS;
        expect(totalPages.value).toBe(3);
        expect(paginatedItems.value).toHaveLength(10);
    });
});
