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
 * **Le filtre se lit dans l'adresse au premier rendu.** `useUrlSearchSync`
 * écrit `?search=` dans l'URL pour qu'une liste filtrée se partage ; sans cette
 * lecture au démarrage, le lien arrivait sur une liste entière et le mot tapé
 * ne servait qu'à celui qui l'avait tapé. C'est aussi ce qui permet d'envoyer
 * quelqu'un d'un écran à un autre déjà filtré - d'un client vers ses espaces,
 * par exemple.
 * @returns {{
 *   items: import('vue').Ref<T[]>,
 *   searchInput: import('vue').Ref<string>,
 *   filteredItems: import('vue').ComputedRef<T[]>,
 *   reload: () => Promise<void>,
 * }}
 */
/**
 * Ce que l'adresse demande de chercher, s'il y a quelque chose.
 *
 * Enveloppé : une URL exotique ne doit pas empêcher une liste de s'afficher.
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
