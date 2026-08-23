import { onMounted, ref } from "vue";

/**
 * The three browser-like controls in the page header: back, forward, reload.
 *
 * **Built on `history`, not on the referrer.** The referrer says where this
 * page was reached from, once - it cannot say anything about "forward", it is
 * empty after a POST-redirect-GET, and it goes stale the moment the visitor
 * moves again. `history.back()` and `history.forward()` are the browser's own
 * mechanism and need no state kept in step with them.
 */
export function useTopbarNavigation() {
    /**
     * Whether there is anywhere to go back to.
     *
     * `history.length` counts this session's entries, so 1 means this page is
     * where the tab started. It is the only half of the question the platform
     * answers: **there is no way to ask whether a forward entry exists.** So
     * forward stays enabled and is sometimes a no-op - which is honest, where
     * greying it out on a guess would be a button lying about what it knows.
     */
    const canGoBack = ref(false);

    onMounted(() => {
        canGoBack.value = window.history.length > 1;
    });

    function back() {
        window.history.back();
    }

    function forward() {
        window.history.forward();
    }

    /** True while a reload is being prepared, so the button can say so. */
    const reloading = ref(false);

    /**
     * As close to Ctrl+F5 as a page can get, which is not all the way.
     *
     * **A script cannot force a hard reload.** `location.reload(true)` has been
     * ignored by every current browser for years - the argument is gone from
     * the specification, and this project has already been fooled by it: a
     * reload that was believed to discard unsaved editor state simply did not.
     *
     * What is actually reachable, in the order that matters:
     *
     * 1. **Service workers**, unregistered. One registered by *another* project
     *    on the same `localhost` origin will happily answer for this one - that
     *    is not hypothetical here, a worker from an unrelated app was found
     *    serving its own pages on this very port.
     * 2. **The Cache Storage API**, emptied. Same reasoning, same origin.
     * 3. **The HTTP cache** for this document, revalidated by re-fetching it
     *    with `cache: "reload"`, which is the one instruction that reaches it.
     *
     * Then an ordinary reload, which now finds fresh entries everywhere it
     * looks. A stale hashed asset - the thing this exists for - is gone.
     */
    async function hardReload() {
        if (reloading.value) {
            return;
        }

        reloading.value = true;

        try {
            if (navigator.serviceWorker) {
                const registrations =
                    await navigator.serviceWorker.getRegistrations();
                await Promise.all(registrations.map((r) => r.unregister()));
            }

            if (window.caches) {
                const keys = await caches.keys();
                await Promise.all(keys.map((key) => caches.delete(key)));
            }

            await fetch(window.location.href, {
                cache: "reload",
                credentials: "include",
            });
        } catch {
            // Every step above is best-effort: a browser that refuses one of
            // them still gets the reload below, which is what was asked for.
        }

        window.location.reload();
    }

    return { canGoBack, back, forward, reloading, hardReload };
}
