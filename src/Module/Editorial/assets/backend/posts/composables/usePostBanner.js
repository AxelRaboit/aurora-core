import { computed } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Drives the banner panel of the post editor.
 *
 * Holds no state of its own, and reads from two places on purpose: the
 * **layout** lives on the post and is shared by every language, the **texts**
 * live in the current translation. Switching locale therefore swaps the words
 * and leaves the design standing — which is the whole point of the split, and
 * it costs nothing here because `texts` is a computed the caller re-points.
 *
 * The banner is a list an author builds — add a text, add an image, reorder,
 * remove. Arrangements like "text then image" or "two images" are what that
 * list produces, not options this file enumerates.
 *
 * Every field is exposed as a writable computed rather than being bound
 * straight in the template. Two reasons, and the second is the real one: the
 * panel stays a pure template, and `v-model` on a prop's property is a
 * mutation ESLint rightly refuses — the write belongs here, next to the
 * mapping it goes through.
 */
const COLUMNS = 48;
const MAX_ITEMS = 6;

// Widths offered in the picker. All whole numbers on a 48-column grid, which
// is why 48 was chosen: it is 4 × 12 and 2 × 24.
const WIDTHS = [
    { columns: 48, key: "full" },
    { columns: 36, key: "three_quarters" },
    { columns: 32, key: "two_thirds" },
    { columns: 24, key: "half" },
    { columns: 16, key: "third" },
    { columns: 12, key: "quarter" },
];

function writable(get, set) {
    return computed({ get, set });
}

function pickerModel(target, mediaKey, idKey) {
    return {
        id: target[idKey],
        url: target[mediaKey]?.url ?? null,
    };
}

function applyPicked(target, picked, mediaKey, idKey) {
    target[idKey] = picked?.id ?? null;
    // Keep the url the picker handed back so the preview survives until the
    // next save; the server re-resolves it from the id on the way out.
    target[mediaKey] = picked?.id ? { url: picked.url ?? null } : null;
}

/**
 * A fresh id for a layout item. Random rather than a counter: a counter reuses
 * the id of an item that was just removed, and the next item created would
 * silently inherit its text in every other language.
 */
function newItemId() {
    // Available on localhost and over https, which is everywhere the backend
    // runs; the fallback is for jsdom, where tests run without it.
    if ("function" === typeof globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID().replaceAll("-", "").slice(0, 24);
    }

    return Array.from({ length: 24 }, () =>
        Math.floor(Math.random() * 36).toString(36),
    ).join("");
}

function newItem(type) {
    return {
        id: newItemId(),
        type,
        // Full width on a phone, half on a large screen: the common case for
        // a second item, and a single item still fills the row because the
        // grid has nothing to put beside it.
        span: { base: COLUMNS, md: null, lg: 24 },
        titleColor: null,
        descriptionColor: null,
        align: "start",
        titleSize: "md",
        mediaId: null,
        media: null,
        buttonColor: null,
        buttonTextColor: null,
    };
}

/** The five per-language fields, as a translation starts with them. */
function newItemText() {
    return { title: "", description: "", alt: "", label: "", url: "" };
}

export function usePostBanner(layout, texts) {
    const { t } = useI18n();

    // Everything below reads the design here. `banner` is kept as the name so
    // the shape stays recognisable against BannerNormalizer's layout half.
    const banner = layout;

    const options = (values, prefix) =>
        computed(() =>
            values.map((value) => ({
                value,
                label: t(`backend.posts.banner.${prefix}.${value}`),
            })),
        );

    const heightOptions = options(["sm", "md", "lg", "full"], "heights");
    const alignOptions = options(["start", "center", "end"], "aligns");
    const fillOptions = options(["none", "solid", "gradient"], "fills");
    const widthModeOptions = options(
        // Mirrors BannerNormalizer::WIDTHS. `full` retired 2026-08-09.
        ["contained", "full_aligned"],
        "width_modes",
    );
    const verticalAlignOptions = options(
        ["start", "center", "end"],
        "verticals",
    );
    const titleSizeOptions = options(["sm", "md", "lg", "xl"], "title_sizes");

    const widthOptions = computed(() =>
        WIDTHS.map(({ columns, key }) => ({
            value: columns,
            label: t(`backend.posts.banner.widths.${key}`),
        })),
    );

    const items = computed(() => banner.value.items);
    const canAddItem = computed(() => banner.value.items.length < MAX_ITEMS);

    function addItem(type) {
        if (!canAddItem.value) {
            return;
        }

        const item = newItem(type);
        banner.value.items.push(item);
        // Only this language's entry. The others gain theirs when the server
        // normalises their texts against the layout — an empty string is what
        // an untranslated item means, and inventing entries here would just be
        // guessing at state the editor cannot see.
        texts.value.items[item.id] = newItemText();
    }

    function removeItem(index) {
        const [removed] = banner.value.items.splice(index, 1);

        if (removed) {
            delete texts.value.items[removed.id];
        }
    }

    /**
     * Up/down rather than drag: the project already reorders menu items, form
     * fields and post-type fields this way, and a banner holds at most six
     * entries — not enough to be worth a second interaction model.
     */
    function moveItem(index, offset) {
        const target = index + offset;
        if (target < 0 || target >= banner.value.items.length) {
            return;
        }

        const list = banner.value.items;
        [list[index], list[target]] = [list[target], list[index]];
    }

    const background = () => banner.value.background;

    const fields = {
        enabled: writable(
            () => banner.value.enabled,
            (value) => {
                banner.value.enabled = value;
            },
        ),
        height: writable(
            () => banner.value.height,
            (value) => {
                banner.value.height = value;
            },
        ),
        widthMode: writable(
            () => banner.value.width,
            (value) => {
                banner.value.width = value;
            },
        ),
        verticalAlign: writable(
            () => banner.value.verticalAlign,
            (value) => {
                banner.value.verticalAlign = value;
            },
        ),
        fillType: writable(
            () => background().type,
            (value) => {
                background().type = value;
            },
        ),
        backgroundColor: writable(
            () => background().color,
            (value) => {
                background().color = value;
            },
        ),
        gradientFrom: writable(
            () => background().gradientFrom,
            (value) => {
                background().gradientFrom = value;
            },
        ),
        gradientTo: writable(
            () => background().gradientTo,
            (value) => {
                background().gradientTo = value;
            },
        ),
        gradientAngle: writable(
            () => background().gradientAngle,
            (value) => {
                background().gradientAngle = value;
            },
        ),
        overlay: writable(
            () => background().overlay,
            (value) => {
                background().overlay = value;
            },
        ),
        backgroundMedia: writable(
            () => pickerModel(background(), "media", "mediaId"),
            (value) => applyPicked(background(), value, "media", "mediaId"),
        ),
        logoMedia: writable(
            () => pickerModel(banner.value, "logo", "logoMediaId"),
            (value) => applyPicked(banner.value, value, "logo", "logoMediaId"),
        ),
    };

    const hasBackgroundImage = computed(() => null !== background().media);
    const isSolidFill = computed(() => "solid" === background().type);
    const isGradientFill = computed(() => "gradient" === background().type);

    /**
     * A live swatch of the fill being composed. Cheap to derive here, and it
     * spares an author from saving just to find out which way the gradient
     * runs — the panel has no preview of the banner itself yet.
     */
    /**
     * Why the fill will not render, when it will not.
     *
     * `fillPreviewStyle` already knows — it answers null for a solid with no
     * colour and for a gradient missing a stop — but a 64×36 swatch that turns
     * blank is not an explanation. The renderer refuses an incomplete fill on
     * purpose, so that nobody's half-finished gradient is guessed at; the cost
     * is a header that draws nothing, and white text on a white panel reads as
     * the editor being broken rather than as a choice left unfinished.
     *
     * Reported here rather than in the SFC because it is the same rule the
     * preview swatch runs on, and two copies of a rule are two places to drop
     * it. Null when there is nothing to say.
     */
    const fillWarning = computed(() => {
        const { type, color, gradientFrom, gradientTo } = background();

        if ("solid" === type && !color) {
            return t("backend.posts.banner.fill_needs_color");
        }

        if ("gradient" === type && !(gradientFrom && gradientTo)) {
            return t("backend.posts.banner.fill_needs_both_stops");
        }

        return null;
    });

    const fillPreviewStyle = computed(() => {
        const { type, color, gradientFrom, gradientTo, gradientAngle } =
            background();

        if ("solid" === type && color) {
            return { backgroundColor: color };
        }

        if ("gradient" === type && gradientFrom && gradientTo) {
            return {
                backgroundImage: `linear-gradient(${gradientAngle}deg, ${gradientFrom}, ${gradientTo})`,
            };
        }

        return null;
    });

    // Built once per index and cached: the template calls itemFields(index) on
    // every render, and handing back a fresh set of computeds each time would
    // throw away their caching for nothing.
    const itemFieldsCache = new Map();

    function itemFields(index) {
        if (!itemFieldsCache.has(index)) {
            const item = () => banner.value.items[index];

            // The words for this item, in whichever language is open. Created
            // on demand rather than assumed present: a layout item added in
            // one locale reaches the others with no entry of its own until
            // someone types in them.
            const text = () => {
                const id = item()?.id;
                if (undefined === id) {
                    return {};
                }

                texts.value.items[id] ??= newItemText();

                return texts.value.items[id];
            };

            const scalar = (key) =>
                writable(
                    () => item()?.[key],
                    (value) => {
                        item()[key] = value;
                    },
                );

            const localised = (key) =>
                writable(
                    () => text()[key] ?? "",
                    (value) => {
                        text()[key] = value;
                    },
                );

            itemFieldsCache.set(index, {
                // Per language — the copy.
                title: localised("title"),
                description: localised("description"),
                alt: localised("alt"),
                label: localised("label"),
                // A link is copy too: a localised page has a localised address.
                url: localised("url"),
                // Shared — the design.
                titleColor: scalar("titleColor"),
                descriptionColor: scalar("descriptionColor"),
                align: scalar("align"),
                titleSize: scalar("titleSize"),
                buttonColor: scalar("buttonColor"),
                buttonTextColor: scalar("buttonTextColor"),
                // The width control drives the large-screen span only. Below
                // that an item stays full width, which is what the stored
                // `base` says and what reads best on a phone.
                width: writable(
                    () => item()?.span?.lg ?? COLUMNS,
                    (value) => {
                        item().span.lg = value;
                    },
                ),
                media: writable(
                    () => pickerModel(item(), "media", "mediaId"),
                    (value) => applyPicked(item(), value, "media", "mediaId"),
                ),
            });
        }

        return itemFieldsCache.get(index);
    }

    return {
        heightOptions,
        alignOptions,
        fillOptions,
        widthModeOptions,
        verticalAlignOptions,
        titleSizeOptions,
        widthOptions,
        items,
        canAddItem,
        addItem,
        removeItem,
        moveItem,
        hasBackgroundImage,
        isSolidFill,
        isGradientFill,
        fillPreviewStyle,
        fillWarning,
        fields,
        itemFields,
    };
}
