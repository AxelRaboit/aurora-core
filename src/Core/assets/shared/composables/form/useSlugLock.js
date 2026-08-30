import { ref, watch } from "vue";
import { slugify } from "@/shared/utils/format/slugify.js";

/**
 * Two-way binding between a `title` source and a `slug` target with a manual lock.
 * - When locked (default), changes to `title` auto-update `slug` via slugify()
 * - When unlocked, the user can edit `slug` freely
 * - Toggling back to locked re-syncs slug from current title
 *
 * `getTitle` and `getSlug` return the current value; `setSlug` mutates it.
 * Pass them as plain getters/setter rather than refs so the consumer keeps
 * full control of where slug lives (often inside a nested reactive object).
 */
export function useSlugLock({ getTitle, setSlug }) {
    const locked = ref(true);

    watch(getTitle, (newTitle) => {
        if (locked.value) setSlug(slugify(newTitle));
    });

    function toggle() {
        locked.value = !locked.value;
        if (locked.value) setSlug(slugify(getTitle()));
    }

    return { locked, toggle };
}
