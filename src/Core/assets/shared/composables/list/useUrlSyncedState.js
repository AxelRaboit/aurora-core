import { onBeforeUnmount, onMounted, ref } from "vue";

/**
 * Reactive state mirrored to the browser URL via history.pushState/popstate.
 *
 * Used to make tab/filter switches client-side while keeping the URL bar in
 * sync - back/forward navigation re-runs the consumer's `onSync` callback.
 *
 * The consumer owns:
 *   - `serialize(value)` - returns a URL or string for the new state, or null
 *     to skip pushState (e.g. when no URL change is needed).
 *   - `deserialize(event)` - given a popstate event, returns the value to
 *     restore. Should fall back to parsing window.location when state is
 *     missing (initial load + Back).
 *
 * Returns `{ state, set }`. Use `set(next)` from click handlers - it updates
 * the state, calls pushState, and invokes `onSync`. Read `state.value` in
 * templates.
 *
 * Note: assumes a single `useUrlSyncedState` per page (history.state is
 * stored under a fixed `value` key without namespacing).
 */
export function useUrlSyncedState({
    initial,
    serialize,
    deserialize,
    onSync = () => {},
} = {}) {
    const state = ref(initial);

    function set(next) {
        if (state.value === next) return;
        state.value = next;
        const target = serialize?.(next);
        if (target) history.pushState({ value: next }, "", target.toString());
        onSync(next);
    }

    function onPopState(event) {
        const next = deserialize?.(event);
        if (next === undefined || next === state.value) return;
        state.value = next;
        onSync(next);
    }

    onMounted(() => window.addEventListener("popstate", onPopState));
    onBeforeUnmount(() => window.removeEventListener("popstate", onPopState));

    return { state, set };
}
