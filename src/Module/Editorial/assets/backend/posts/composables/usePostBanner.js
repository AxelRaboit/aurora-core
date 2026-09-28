import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
    emptyBannerCarousel,
    emptyBannerLayout,
    emptyBannerStripes,
} from "./usePostEditor.js";

/**
 * Drives the banner panel of the post editor.
 *
 * Holds no state of its own, and reads from two places on purpose: the
 * **layout** lives on the post and is shared by every language, the **texts**
 * live in the current translation. Switching locale therefore swaps the words
 * and leaves the design standing - which is the whole point of the split, and
 * it costs nothing here because `texts` is a computed the caller re-points.
 *
 * The banner is a list an author builds - add a text, add an image, reorder,
 * remove. Arrangements like "text then image" or "two images" are what that
 * list produces, not options this file enumerates.
 *
 * Every field is exposed as a writable computed rather than being bound
 * straight in the template. Two reasons, and the second is the real one: the
 * panel stays a pure template, and `v-model` on a prop's property is a
 * mutation ESLint rightly refuses - the write belongs here, next to the
 * mapping it goes through.
 */
const COLUMNS = 48;
const MAX_ITEMS = 6;
// Mirrors BannerNormalizer::MAX_SLIDES: slides after the banner's own.
const MAX_EXTRA_SLIDES = 6;

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

// Mirrors ThemeFontEnum: the families the site serves itself. Null follows
// the theme's font, which is what every banner did before it could choose.
const FONTS = [
    { value: "poppins", label: "Poppins" },
    { value: "inter", label: "Inter" },
    { value: "work-sans", label: "Work Sans" },
    { value: "nunito", label: "Nunito" },
    { value: "lora", label: "Lora" },
    { value: "playfair-display", label: "Playfair Display" },
    { value: "space-grotesk", label: "Space Grotesk" },
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
        descriptionSize: "md",
        titleFont: null,
        descriptionFont: null,
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

function emptyLocalBackground() {
    return {
        mediaId: null,
        mobileMediaId: null,
        tabletMediaId: null,
        media: null,
        mobileMedia: null,
        tabletMedia: null,
    };
}

/**
 * A further slide for a carousel, laid out like the one it follows.
 *
 * The items are the first slide's, with new ids and no picture: slides that
 * take turns in the same place read as a set when their words sit in the
 * same spot at the same size, and starting from that saves rebuilding it
 * by hand. The words themselves start empty - they are what changes.
 */
function newSlide(model) {
    return {
        id: newItemId(),
        accentColor: null,
        background: { ...emptyBannerLayout().background },
        items: (model?.items ?? []).map((item) => ({
            ...item,
            id: newItemId(),
            span: { ...item.span },
            mediaId: null,
            media: null,
        })),
    };
}

export function usePostBanner(layout, texts) {
    const { t } = useI18n();

    // Everything below reads the design here. `banner` is kept as the name so
    // the shape stays recognisable against BannerNormalizer's layout half.
    const banner = layout;

    // The slide the panel has open: 0 is the banner's own, which is also the
    // first slide of a carousel; 1 and up are `banner.slides[n - 1]`. Items
    // and background are edited on the open slide, everything else - width,
    // height, fade, bands, logo - on the banner, since the slides share it.
    const activeSlide = ref(0);

    const extraSlides = () => {
        banner.value.slides ??= [];

        return banner.value.slides;
    };

    // The design of a slide, by number. Falls back to the banner itself when
    // the number points past the end - a slide just removed in another tab.
    const layoutOf = (slide) =>
        slide > 0 ? (extraSlides()[slide - 1] ?? banner.value) : banner.value;

    // The words of a slide in the open language, created on demand like an
    // item's text: a translation saved before the slide existed has none.
    const textsOf = (slide) => {
        const target = layoutOf(slide);
        if (target === banner.value) {
            return texts.value;
        }

        texts.value.slides ??= {};
        texts.value.slides[target.id] ??= {
            items: {},
            background: emptyLocalBackground(),
        };

        return texts.value.slides[target.id];
    };

    const slideLayout = () => layoutOf(activeSlide.value);
    const slideTexts = () => textsOf(activeSlide.value);

    const slideCount = computed(() => 1 + (banner.value.slides?.length ?? 0));
    const isCarousel = computed(() => slideCount.value > 1);
    const canAddSlide = computed(
        () => (banner.value.slides?.length ?? 0) < MAX_EXTRA_SLIDES,
    );

    function selectSlide(slide) {
        activeSlide.value = Math.max(0, Math.min(slideCount.value - 1, slide));
    }

    function addSlide() {
        if (!canAddSlide.value) {
            return;
        }

        extraSlides().push(newSlide(banner.value));
        activeSlide.value = slideCount.value - 1;
    }

    /** Only a further slide: the first is the banner itself. */
    function removeSlide(slide) {
        if (slide < 1) {
            return;
        }

        const [removed] = extraSlides().splice(slide - 1, 1);
        if (removed && texts.value.slides) {
            delete texts.value.slides[removed.id];
        }

        activeSlide.value = Math.min(activeSlide.value, slideCount.value - 1);
    }

    /**
     * Among the further slides only. The first one is the banner's own,
     * with the page's <h1>, and stays first.
     */
    function moveSlide(slide, offset) {
        const list = extraSlides();
        const from = slide - 1;
        const to = from + offset;
        if (from < 0 || to < 0 || to >= list.length) {
            return;
        }

        [list[from], list[to]] = [list[to], list[from]];
        activeSlide.value = to + 1;
    }

    const carouselSettings = () => {
        banner.value.carousel ??= emptyBannerCarousel();

        return banner.value.carousel;
    };
    const carouselField = (key) =>
        writable(
            () => carouselSettings()[key],
            (value) => {
                carouselSettings()[key] = value;
            },
        );

    const options = (values, prefix) =>
        computed(() =>
            values.map((value) => ({
                value,
                label: t(`backend.posts.banner.${prefix}.${value}`),
            })),
        );

    // `image` follows the background's proportions: nothing is cropped, which
    // is what a picture with words set in it needs.
    const heightOptions = options(
        ["sm", "md", "lg", "full", "image"],
        "heights",
    );
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
    const stripeSideOptions = options(
        ["start", "center", "end"],
        "stripe_sides",
    );
    // How one slide gives way to the next: a fade, or a slide sideways.
    const transitionOptions = options(["fade", "slide"], "transitions");

    // The bands, ensured on the layout: a post saved before they existed
    // has none, and the fields below write into them.
    const stripes = () => {
        banner.value.stripes ??= emptyBannerStripes();

        return banner.value.stripes;
    };
    const MAX_STRIPES = 6;
    const stripeColors = computed(() => stripes().colors);
    const canAddStripe = computed(() => stripes().colors.length < MAX_STRIPES);
    function setStripeColor(index, value) {
        if (value) stripes().colors[index] = value;
    }
    function addStripe() {
        if (canAddStripe.value) stripes().colors.push("#ffffff");
    }
    function removeStripe(index) {
        // At least one band: none at all is what the switch is for.
        if (stripes().colors.length > 1) stripes().colors.splice(index, 1);
    }
    const stripeField = (key) =>
        writable(
            () => stripes()[key],
            (value) => {
                stripes()[key] = value;
            },
        );

    const widthOptions = computed(() =>
        WIDTHS.map(({ columns, key }) => ({
            value: columns,
            label: t(`backend.posts.banner.widths.${key}`),
        })),
    );

    // On a tablet an item can keep the phone's full width (null, which the
    // grid reads as "inherit") or take one of the same fractions.
    const tabletWidthOptions = computed(() => [
        { value: null, label: t("backend.posts.banner.widths.as_phone") },
        ...widthOptions.value,
    ]);

    const fontOptions = computed(() => [
        { value: null, label: t("backend.posts.banner.font_theme") },
        ...FONTS,
    ]);

    const items = computed(() => slideLayout().items);
    const canAddItem = computed(() => slideLayout().items.length < MAX_ITEMS);

    function addItem(type) {
        if (!canAddItem.value) {
            return;
        }

        const item = newItem(type);
        slideLayout().items.push(item);
        // Only this language's entry. The others gain theirs when the server
        // normalises their texts against the layout - an empty string is what
        // an untranslated item means, and inventing entries here would just be
        // guessing at state the editor cannot see.
        slideTexts().items[item.id] = newItemText();
    }

    function removeItem(index) {
        const [removed] = slideLayout().items.splice(index, 1);

        if (removed) {
            delete slideTexts().items[removed.id];
        }
    }

    /**
     * Up/down rather than drag: the project already reorders menu items, form
     * fields and post-type fields this way, and a banner holds at most six
     * entries - not enough to be worth a second interaction model.
     */
    function moveItem(index, offset) {
        const target = index + offset;
        if (target < 0 || target >= slideLayout().items.length) {
            return;
        }

        const list = slideLayout().items;
        [list[index], list[target]] = [list[target], list[index]];
    }

    const background = () => slideLayout().background;

    // This language's own background, for a picture with words in it. Created
    // on demand like an item's text: a translation saved before it existed
    // arrives without one.
    const localBackground = () => {
        const target = slideTexts();
        target.background ??= emptyLocalBackground();

        return target.background;
    };

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
        // The open slide's own crop of its wide picture. Null follows the
        // document's point, which is what the picker shows when cleared.
        backgroundFocalX: writable(
            () => background().focalX ?? null,
            (value) => {
                background().focalX = value ?? null;
            },
        ),
        backgroundFocalY: writable(
            () => background().focalY ?? null,
            (value) => {
                background().focalY = value ?? null;
            },
        ),
        // On the banner rather than on its background: it fades the whole
        // header into the page, picture or no picture.
        fadeOut: writable(
            () => banner.value.fadeOut ?? false,
            (value) => {
                banner.value.fadeOut = Boolean(value);
            },
        ),
        // The open slide's accent: the `×` of its title, and anything else
        // drawn in the theme's accent. Null keeps the theme's.
        accentColor: writable(
            () => slideLayout().accentColor ?? null,
            (value) => {
                slideLayout().accentColor = value || null;
            },
        ),
        carouselAutoplay: carouselField("autoplay"),
        carouselInterval: carouselField("interval"),
        carouselPauseOnHover: carouselField("pauseOnHover"),
        carouselArrows: carouselField("arrows"),
        carouselDots: carouselField("dots"),
        carouselTransition: carouselField("transition"),
        stripesEnabled: stripeField("enabled"),
        stripesSide: stripeField("side"),
        stripesThickness: stripeField("thickness"),
        stripesGap: stripeField("gap"),
        stripesAngle: stripeField("angle"),
        stripesOffset: stripeField("offset"),
        stripesOpacity: stripeField("opacity"),
        stripesHideOnPhone: stripeField("hideOnPhone"),
        backgroundMedia: writable(
            () => pickerModel(background(), "media", "mediaId"),
            (value) => applyPicked(background(), value, "media", "mediaId"),
        ),
        logoMedia: writable(
            () => pickerModel(banner.value, "logo", "logoMediaId"),
            (value) => applyPicked(banner.value, value, "logo", "logoMediaId"),
        ),
        // What a phone gets instead of the wide picture, which it would
        // otherwise crop to its middle.
        mobileBackgroundMedia: writable(
            () => pickerModel(background(), "mobileMedia", "mobileMediaId"),
            (value) =>
                applyPicked(
                    background(),
                    value,
                    "mobileMedia",
                    "mobileMediaId",
                ),
        ),
        // And what a tablet gets, where the header grows to hold its title
        // and a wide picture loses its sides.
        tabletBackgroundMedia: writable(
            () => pickerModel(background(), "tabletMedia", "tabletMediaId"),
            (value) =>
                applyPicked(
                    background(),
                    value,
                    "tabletMedia",
                    "tabletMediaId",
                ),
        ),
        // Per language: each replaces its shared counterpart when set.
        localBackgroundMedia: writable(
            () => pickerModel(localBackground(), "media", "mediaId"),
            (value) =>
                applyPicked(localBackground(), value, "media", "mediaId"),
        ),
        localMobileBackgroundMedia: writable(
            () =>
                pickerModel(localBackground(), "mobileMedia", "mobileMediaId"),
            (value) =>
                applyPicked(
                    localBackground(),
                    value,
                    "mobileMedia",
                    "mobileMediaId",
                ),
        ),
        localTabletBackgroundMedia: writable(
            () =>
                pickerModel(localBackground(), "tabletMedia", "tabletMediaId"),
            (value) =>
                applyPicked(
                    localBackground(),
                    value,
                    "tabletMedia",
                    "tabletMediaId",
                ),
        ),
    };

    // Any picture behind the banner, shared or this language's: the darkening
    // slider applies to whichever is drawn.
    const hasBackgroundImage = computed(
        () =>
            Boolean(background().media) ||
            Boolean(slideTexts().background?.media),
    );
    // The wide picture actually drawn, the language's own over the shared
    // one, and where its document says to crop it: what the focal picker aims
    // at and falls back to.
    const backgroundPreview = computed(() => {
        const media = slideTexts().background?.media ?? background().media;

        return {
            url: media?.url ?? "",
            inherited:
                media?.documentFocalPosition ??
                media?.focalPosition ??
                "50% 50%",
        };
    });
    const isSolidFill = computed(() => "solid" === background().type);
    const isGradientFill = computed(() => "gradient" === background().type);

    /**
     * A live swatch of the fill being composed. Cheap to derive here, and it
     * spares an author from saving just to find out which way the gradient
     * runs - the panel has no preview of the banner itself yet.
     */
    /**
     * Why the fill will not render, when it will not.
     *
     * `fillPreviewStyle` already knows - it answers null for a solid with no
     * colour and for a gradient missing a stop - but a 64×36 swatch that turns
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
        // Per slide as well as per index: the second item of the first slide
        // and of the third are two different fields.
        const slide = activeSlide.value;
        const key = `${slide}:${index}`;

        if (!itemFieldsCache.has(key)) {
            const item = () => layoutOf(slide).items[index];

            // The words for this item, in whichever language is open. Created
            // on demand rather than assumed present: a layout item added in
            // one locale reaches the others with no entry of its own until
            // someone types in them.
            const text = () => {
                const id = item()?.id;
                if (undefined === id) {
                    return {};
                }

                const target = textsOf(slide);
                target.items[id] ??= newItemText();

                return target.items[id];
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

            itemFieldsCache.set(key, {
                // Per language - the copy.
                title: localised("title"),
                description: localised("description"),
                alt: localised("alt"),
                label: localised("label"),
                // A link is copy too: a localised page has a localised address.
                url: localised("url"),
                // Shared - the design.
                titleColor: scalar("titleColor"),
                descriptionColor: scalar("descriptionColor"),
                align: scalar("align"),
                titleSize: scalar("titleSize"),
                descriptionSize: writable(
                    () => item()?.descriptionSize ?? "md",
                    (value) => {
                        item().descriptionSize = value;
                    },
                ),
                titleFont: writable(
                    () => item()?.titleFont ?? null,
                    (value) => {
                        item().titleFont = value || null;
                    },
                ),
                descriptionFont: writable(
                    () => item()?.descriptionFont ?? null,
                    (value) => {
                        item().descriptionFont = value || null;
                    },
                ),
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
                // Tablet: null inherits the phone's full width.
                tabletWidth: writable(
                    () => item()?.span?.md ?? null,
                    (value) => {
                        item().span.md = value || null;
                    },
                ),
                media: writable(
                    () => pickerModel(item(), "media", "mediaId"),
                    (value) => applyPicked(item(), value, "media", "mediaId"),
                ),
            });
        }

        return itemFieldsCache.get(key);
    }

    return {
        activeSlide,
        slideCount,
        isCarousel,
        canAddSlide,
        selectSlide,
        addSlide,
        removeSlide,
        moveSlide,
        transitionOptions,
        heightOptions,
        alignOptions,
        fillOptions,
        widthModeOptions,
        verticalAlignOptions,
        titleSizeOptions,
        stripeSideOptions,
        stripeColors,
        canAddStripe,
        setStripeColor,
        addStripe,
        removeStripe,
        widthOptions,
        tabletWidthOptions,
        fontOptions,
        items,
        canAddItem,
        addItem,
        removeItem,
        moveItem,
        hasBackgroundImage,
        backgroundPreview,
        isSolidFill,
        isGradientFill,
        fillPreviewStyle,
        fillWarning,
        fields,
        itemFields,
    };
}
