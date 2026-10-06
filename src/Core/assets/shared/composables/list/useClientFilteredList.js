import { ref, computed } from "vue";

/**
 * Flat admin list with client-side text filter.
 *
 * Use for short collections (< few hundred items) where loading the full set
 * on mount is cheap. For paginated/server-side search, see `useListPage`.
 *
 * `listPath` is optional. When provided, `reload()` refetches from it.
 * When absent (consumer manages `items` externally, e.g. through form-action
 * response payloads), `reload()` is a no-op.
 *
 * @template T
 * @param {T[]} initialItems         hydrated from SSR / Twig payload
 * @param {string|null} listPath     JSON endpoint returning `{ items: T[] }`,
 *                                   or null when items are updated externally
 * @param {(item: T, lowerQuery: string) => boolean} matcher  filter predicate
 *
 * **The filter is read from the address on the first render.** `useUrlSearchSync`
 * writes `?search=` into the URL so that a filtered list can be shared; without
 * this read at startup, the link landed on a whole list and the typed word only
 * served the person who typed it. It is also what makes it possible to send
 * someone from one screen to another already filtered - from a client to their
 * spaces, for example.
 * @returns {{
 *   items: import('vue').Ref<T[]>,
 *   searchInput: import('vue').Ref<string>,
 *   filteredItems: import('vue').ComputedRef<T[]>,
 *   reload: () => Promise<void>,
 * }}
 */
/**
 * What the address asks to search for, if anything.
 *
 * Wrapped: an exotic URL must not prevent a list from showing.
 */
function initialSearch() {
    try {
        return new URL(window.location.href).searchParams.get("search") ?? "";
    } catch {
        return "";
    }
}

export function useClientFilteredList(initialItems, listPath, matcher) {
    const items = ref([...(initialItems ?? [])]);
    const searchInput = ref(initialSearch());

    const filteredItems = computed(() => {
        const query = searchInput.value.toLowerCase().trim();
        if (!query) return items.value;
        return items.value.filter((item) => matcher(item, query));
    });

    async function reload() {
        if (!listPath) return;
        // Raw `fetch` on purpose, with the header written out. `useRequest`
        // would be the rule, but it calls `useI18n()` and so may only run
        // inside a component's setup; this is a plain list helper with no other
        // reason to be bound to one. `convention_no_raw_fetch` exists for the
        // header, and the header is here.
        const response = await fetch(listPath, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        });
        const json = await response.json();
        items.value = json.items ?? [];
    }

    return { items, searchInput, filteredItems, reload };
}
