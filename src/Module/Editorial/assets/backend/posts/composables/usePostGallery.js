/**
 * The rules a gallery follows, kept out of its panel.
 *
 * Mirrors the two halves the server stores: `layout` on the post - which
 * pictures, in what order, laid out how - and `content` on the open translation,
 * the alt text and caption keyed by item id. The panel binds to both and decides
 * nothing.
 *
 * Every guard here is also enforced by `GalleryNormalizer` on the way in. That is
 * not duplication to remove: the front stops the author doing something the page
 * cannot show, the back stops anything at all reaching the column. Take either
 * away and the other is doing a different job alone.
 */
import { computed } from "vue";

function writable(get, set) {
    return computed({ get, set });
}

/** Kept in step with GalleryNormalizer::LAYOUTS. */
export const GALLERY_LAYOUTS = ["grid", "masonry"];

/** Kept in step with GalleryNormalizer::RATIOS. */
export const GALLERY_RATIOS = ["natural", "16x9", "4x3", "1x1", "3x4"];

/** Kept in step with GalleryNormalizer::COLUMNS. */
export const GALLERY_COLUMNS = [2, 3, 4, 5];

/** Kept in step with GalleryNormalizer::MAX_ITEMS. */
export const GALLERY_MAX_ITEMS = 60;

/**
 * A fresh id for an item. Random rather than a counter, for the reason
 * `usePostGrid` gives: a counter reuses the id of an item just removed, and the
 * next one created inherits its caption in every other language.
 */
function newItemId() {
    if ("function" === typeof globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID().replaceAll("-", "").slice(0, 24);
    }

    return Array.from({ length: 24 }, () =>
        Math.floor(Math.random() * 36).toString(36),
    ).join("");
}

/**
 * Takes the two reactive objects themselves, not refs to them: they come from
 * the editor's `form` and are already reactive, and a wrapper would only add a
 * `.value` to write through.
 *
 * The settings are handed back as writable computeds rather than left to the
 * panel to assign. That is the shape `usePostGrid` uses, and the reason is
 * `vue/no-mutating-props`: a panel binding `v-model` straight to a prop's field
 * is mutating something it does not own, and the rule is right to say so. The
 * mutation belongs here, where the object's owner passed it in.
 *
 * @param {object} layout reactive `form.galleryLayout`
 * @param {object} words  reactive `current.gallery`, the open locale's half
 */
export function usePostGallery(layout, words) {
    const items = computed(() => layout.items ?? []);

    const enabled = writable(
        () => Boolean(layout.enabled),
        (value) => {
            layout.enabled = Boolean(value);
        },
    );

    const mode = writable(
        () => layout.layout ?? "grid",
        (value) => {
            layout.layout = GALLERY_LAYOUTS.includes(value) ? value : "grid";
        },
    );

    const columns = writable(
        () => layout.columns ?? 3,
        (value) => {
            layout.columns = GALLERY_COLUMNS.includes(Number(value))
                ? Number(value)
                : 3;
        },
    );

    const ratio = writable(
        () => layout.ratio ?? "natural",
        (value) => {
            layout.ratio = GALLERY_RATIOS.includes(value) ? value : "natural";
        },
    );

    const isFull = computed(() => items.value.length >= GALLERY_MAX_ITEMS);

    /** Media ids already in, so the picker can refuse a second copy. */
    const pickedMediaIds = computed(
        () => new Set(items.value.map((item) => item.mediaId)),
    );

    function ensureWords(id) {
        if (!words.items) {
            words.items = {};
        }

        if (!words.items[id]) {
            words.items[id] = { alt: "", caption: "" };
        }
    }

    /**
     * Appends a picture, and answers whether it went in.
     *
     * Refused rather than silently ignored when it is already there: the author
     * picked it on purpose, and a picker that closes with nothing happening
     * reads as broken. The panel turns `false` into a message.
     */
    function addItem(media) {
        if (!media?.id || isFull.value || pickedMediaIds.value.has(media.id)) {
            return false;
        }

        const id = newItemId();
        layout.items = [
            ...items.value,
            { id, mediaId: media.id, url: media.url ?? null },
        ];
        ensureWords(id);

        return true;
    }

    /**
     * Drops a picture, and its words with it - in **this** locale only.
     *
     * The other languages keep theirs until the save, where the normalizer
     * settles the layout first and then rebuilds every locale's content from it.
     * Reaching across locales here would mean the panel knowing about languages
     * it does not have open.
     */
    function removeItem(id) {
        layout.items = items.value.filter((item) => item.id !== id);
        delete words.items?.[id];
    }

    /**
     * The whole order at once, which is what a drag emits.
     *
     * Filtered against the ids already in rather than assigned as it arrives: the
     * value comes from a library, and an item this gallery does not know about
     * would reach the column with no media behind it. Cheap, and it means the
     * drag and the arrows cannot disagree about what an item is.
     */
    function reorder(next) {
        if (!Array.isArray(next)) {
            return;
        }

        const known = new Map(items.value.map((item) => [item.id, item]));
        const ordered = next
            .map((item) => known.get(item?.id))
            .filter((item) => undefined !== item);

        // Anything lost on the way means the incoming order was not this
        // gallery's, so keep what is on screen rather than silently dropping a
        // picture.
        if (ordered.length === items.value.length) {
            layout.items = ordered;
        }
    }

    /** One step, either way, clamped at the ends rather than wrapping. */
    function moveItem(id, direction) {
        const from = items.value.findIndex((item) => item.id === id);
        const to = from + direction;

        if (from < 0 || to < 0 || to >= items.value.length) {
            return;
        }

        const next = [...items.value];
        [next[from], next[to]] = [next[to], next[from]];
        layout.items = next;
    }

    /**
     * The words for one item in the open locale, created on read.
     *
     * A gallery loaded from the server carries content for the items it had;
     * items added since have none, and binding a field to a key that is not
     * there would drop what is typed into it.
     */
    function wordsFor(id) {
        ensureWords(id);

        return words.items[id];
    }

    return {
        items,
        enabled,
        mode,
        columns,
        ratio,
        isFull,
        pickedMediaIds,
        addItem,
        removeItem,
        moveItem,
        reorder,
        wordsFor,
    };
}
