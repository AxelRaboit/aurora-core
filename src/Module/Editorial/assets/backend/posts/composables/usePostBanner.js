import { computed } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Drives the banner panel of the post editor.
 *
 * Holds no state of its own: the banner lives inside the current translation,
 * so switching locale switches banner with it and saving carries it along
 * without this file knowing anything about the save.
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

function newItem(type) {
    return {
        type,
        // Full width on a phone, half on a large screen: the common case for
        // a second item, and a single item still fills the row because the
        // grid has nothing to put beside it.
        span: { base: COLUMNS, md: null, lg: 24 },
        title: "",
        description: "",
        titleColor: null,
        descriptionColor: null,
        align: "start",
        titleSize: "md",
        mediaId: null,
        alt: "",
        media: null,
        label: "",
        url: "",
        buttonColor: null,
        buttonTextColor: null,
    };
}

export function usePostBanner(banner) {
    const { t } = useI18n();

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
        ["contained", "full_aligned", "full"],
        "width_modes",
    );

    const widthOptions = computed(() =>
        WIDTHS.map(({ columns, key }) => ({
            value: columns,
            label: t(`backend.posts.banner.widths.${key}`),
        })),
    );

    const items = computed(() => banner.value.items);
    const canAddItem = computed(() => banner.value.items.length < MAX_ITEMS);

    function addItem(type) {
        if (canAddItem.value) {
            banner.value.items.push(newItem(type));
        }
    }

    function removeItem(index) {
        banner.value.items.splice(index, 1);
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
            const scalar = (key) =>
                writable(
                    () => item()?.[key],
                    (value) => {
                        item()[key] = value;
                    },
                );

            itemFieldsCache.set(index, {
                title: scalar("title"),
                description: scalar("description"),
                titleColor: scalar("titleColor"),
                descriptionColor: scalar("descriptionColor"),
                align: scalar("align"),
                titleSize: scalar("titleSize"),
                alt: scalar("alt"),
                label: scalar("label"),
                url: scalar("url"),
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
        fields,
        itemFields,
    };
}
