import { computed, reactive, watch } from "vue";

/**
 * The fonts a free slide's text may be set in.
 *
 * **Self-hosted, every one of them.** The families are npm packages bundled
 * with the application, so a deck opened from a share link asks nothing of
 * Google: no request that tells a third party who is reading which deck, which
 * is what the RGPD makes of a font loaded from someone else's server. The eight
 * families the back office already carries are drawn from `app.css`; the
 * others are loaded when a slide first names them, one dynamic import each, so
 * a reader downloads the two families a deck uses and not fifty.
 *
 * **Only the Latin subset of the fixed-weight families**, which covers French,
 * English and Spanish, ligatures and the oe included. The variable families
 * ship their subsets with `unicode-range`, so the browser fetches the same.
 *
 * Uploaded fonts are documents of the library, named `upload-<id>`. Their
 * files are served by `PublicDeckFontController`, on the application's own
 * origin, because the content security policy only lets a page load fonts
 * from there.
 */
export const FONT_CATALOGUE = [
    {
        key: "abril-fatface",
        name: "Abril Fatface",
        category: "display",
        stack: '"Abril Fatface", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource/abril-fatface/latin-400.css")]),
    },
    {
        key: "alfa-slab-one",
        name: "Alfa Slab One",
        category: "display",
        stack: '"Alfa Slab One", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource/alfa-slab-one/latin-400.css")]),
    },
    {
        key: "anton",
        name: "Anton",
        category: "display",
        stack: '"Anton", ui-sans-serif, system-ui, sans-serif',
        load: () => Promise.all([import("@fontsource/anton/latin-400.css")]),
    },
    {
        key: "archivo",
        name: "Archivo",
        category: "sans",
        stack: '"Archivo Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/archivo/wght.css"),
                import("@fontsource-variable/archivo/wght-italic.css"),
            ]),
    },
    {
        key: "archivo-black",
        name: "Archivo Black",
        category: "display",
        stack: '"Archivo Black", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource/archivo-black/latin-400.css")]),
    },
    {
        key: "barlow",
        name: "Barlow",
        category: "sans",
        stack: '"Barlow", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource/barlow/latin-400.css"),
                import("@fontsource/barlow/latin-400-italic.css"),
                import("@fontsource/barlow/latin-700.css"),
                import("@fontsource/barlow/latin-700-italic.css"),
            ]),
    },
    {
        key: "bebas-neue",
        name: "Bebas Neue",
        category: "display",
        stack: '"Bebas Neue", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource/bebas-neue/latin-400.css")]),
    },
    {
        key: "bodoni-moda",
        name: "Bodoni Moda",
        category: "serif",
        stack: '"Bodoni Moda Variable", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/bodoni-moda/wght.css"),
                import("@fontsource-variable/bodoni-moda/wght-italic.css"),
            ]),
    },
    {
        key: "bungee",
        name: "Bungee",
        category: "display",
        stack: '"Bungee", ui-sans-serif, system-ui, sans-serif',
        load: () => Promise.all([import("@fontsource/bungee/latin-400.css")]),
    },
    {
        key: "caveat",
        name: "Caveat",
        category: "script",
        stack: '"Caveat Variable", cursive',
        load: () =>
            Promise.all([import("@fontsource-variable/caveat/wght.css")]),
    },
    {
        key: "comfortaa",
        name: "Comfortaa",
        category: "display",
        stack: '"Comfortaa Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource-variable/comfortaa/wght.css")]),
    },
    {
        key: "cormorant-garamond",
        name: "Cormorant Garamond",
        category: "serif",
        stack: '"Cormorant Garamond Variable", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/cormorant-garamond/wght.css"),
                import("@fontsource-variable/cormorant-garamond/wght-italic.css"),
            ]),
    },
    {
        key: "crimson-pro",
        name: "Crimson Pro",
        category: "serif",
        stack: '"Crimson Pro Variable", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/crimson-pro/wght.css"),
                import("@fontsource-variable/crimson-pro/wght-italic.css"),
            ]),
    },
    {
        key: "dancing-script",
        name: "Dancing Script",
        category: "script",
        stack: '"Dancing Script Variable", cursive',
        load: () =>
            Promise.all([
                import("@fontsource-variable/dancing-script/wght.css"),
            ]),
    },
    {
        key: "dm-sans",
        name: "DM Sans",
        category: "sans",
        stack: '"DM Sans Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/dm-sans/wght.css"),
                import("@fontsource-variable/dm-sans/wght-italic.css"),
            ]),
    },
    {
        key: "dm-serif-display",
        name: "DM Serif Display",
        category: "serif",
        stack: '"DM Serif Display", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource/dm-serif-display/latin-400.css"),
                import("@fontsource/dm-serif-display/latin-400-italic.css"),
            ]),
    },
    {
        key: "eb-garamond",
        name: "EB Garamond",
        category: "serif",
        stack: '"EB Garamond Variable", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/eb-garamond/wght.css"),
                import("@fontsource-variable/eb-garamond/wght-italic.css"),
            ]),
    },
    {
        key: "figtree",
        name: "Figtree",
        category: "sans",
        stack: '"Figtree Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/figtree/wght.css"),
                import("@fontsource-variable/figtree/wght-italic.css"),
            ]),
    },
    {
        key: "fira-code",
        name: "Fira Code",
        category: "mono",
        stack: '"Fira Code Variable", ui-monospace, monospace',
        load: () =>
            Promise.all([import("@fontsource-variable/fira-code/wght.css")]),
    },
    {
        key: "fraunces",
        name: "Fraunces",
        category: "serif",
        stack: '"Fraunces Variable", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/fraunces/wght.css"),
                import("@fontsource-variable/fraunces/wght-italic.css"),
            ]),
    },
    {
        key: "great-vibes",
        name: "Great Vibes",
        category: "script",
        stack: '"Great Vibes", cursive',
        load: () =>
            Promise.all([import("@fontsource/great-vibes/latin-400.css")]),
    },
    {
        key: "inter",
        name: "Inter",
        category: "sans",
        stack: '"Inter", ui-sans-serif, system-ui, sans-serif',
        load: null,
    },
    {
        key: "jetbrains-mono",
        name: "JetBrains Mono",
        category: "mono",
        stack: '"JetBrains Mono", ui-monospace, monospace',
        load: null,
    },
    {
        key: "josefin-sans",
        name: "Josefin Sans",
        category: "sans",
        stack: '"Josefin Sans Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/josefin-sans/wght.css"),
                import("@fontsource-variable/josefin-sans/wght-italic.css"),
            ]),
    },
    {
        key: "kanit",
        name: "Kanit",
        category: "sans",
        stack: '"Kanit", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource/kanit/latin-400.css"),
                import("@fontsource/kanit/latin-400-italic.css"),
                import("@fontsource/kanit/latin-700.css"),
                import("@fontsource/kanit/latin-700-italic.css"),
            ]),
    },
    {
        key: "lato",
        name: "Lato",
        category: "sans",
        stack: '"Lato", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource/lato/latin-400.css"),
                import("@fontsource/lato/latin-400-italic.css"),
                import("@fontsource/lato/latin-700.css"),
                import("@fontsource/lato/latin-700-italic.css"),
            ]),
    },
    {
        key: "lexend",
        name: "Lexend",
        category: "sans",
        stack: '"Lexend Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource-variable/lexend/wght.css")]),
    },
    {
        key: "libre-baskerville",
        name: "Libre Baskerville",
        category: "serif",
        stack: '"Libre Baskerville", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource/libre-baskerville/latin-400.css"),
                import("@fontsource/libre-baskerville/latin-400-italic.css"),
                import("@fontsource/libre-baskerville/latin-700.css"),
                import("@fontsource/libre-baskerville/latin-700-italic.css"),
            ]),
    },
    {
        key: "lobster",
        name: "Lobster",
        category: "script",
        stack: '"Lobster", cursive',
        load: () => Promise.all([import("@fontsource/lobster/latin-400.css")]),
    },
    {
        key: "lora",
        name: "Lora",
        category: "serif",
        stack: '"Lora", ui-serif, Georgia, serif',
        load: null,
    },
    {
        key: "manrope",
        name: "Manrope",
        category: "sans",
        stack: '"Manrope Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource-variable/manrope/wght.css")]),
    },
    {
        key: "merriweather",
        name: "Merriweather",
        category: "serif",
        stack: '"Merriweather Variable", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/merriweather/wght.css"),
                import("@fontsource-variable/merriweather/wght-italic.css"),
            ]),
    },
    {
        key: "montserrat",
        name: "Montserrat",
        category: "sans",
        stack: '"Montserrat Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/montserrat/wght.css"),
                import("@fontsource-variable/montserrat/wght-italic.css"),
            ]),
    },
    {
        key: "nunito",
        name: "Nunito",
        category: "sans",
        stack: '"Nunito", ui-sans-serif, system-ui, sans-serif',
        load: null,
    },
    {
        key: "open-sans",
        name: "Open Sans",
        category: "sans",
        stack: '"Open Sans Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/open-sans/wght.css"),
                import("@fontsource-variable/open-sans/wght-italic.css"),
            ]),
    },
    {
        key: "oswald",
        name: "Oswald",
        category: "display",
        stack: '"Oswald Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource-variable/oswald/wght.css")]),
    },
    {
        key: "outfit",
        name: "Outfit",
        category: "sans",
        stack: '"Outfit Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource-variable/outfit/wght.css")]),
    },
    {
        key: "pacifico",
        name: "Pacifico",
        category: "script",
        stack: '"Pacifico", cursive',
        load: () => Promise.all([import("@fontsource/pacifico/latin-400.css")]),
    },
    {
        key: "permanent-marker",
        name: "Permanent Marker",
        category: "script",
        stack: '"Permanent Marker", cursive',
        load: () =>
            Promise.all([import("@fontsource/permanent-marker/latin-400.css")]),
    },
    {
        key: "playfair-display",
        name: "Playfair Display",
        category: "serif",
        stack: '"Playfair Display", ui-serif, Georgia, serif',
        load: null,
    },
    {
        key: "plus-jakarta-sans",
        name: "Plus Jakarta Sans",
        category: "sans",
        stack: '"Plus Jakarta Sans Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/plus-jakarta-sans/wght.css"),
                import("@fontsource-variable/plus-jakarta-sans/wght-italic.css"),
            ]),
    },
    {
        key: "poppins",
        name: "Poppins",
        category: "sans",
        stack: '"Poppins", ui-sans-serif, system-ui, sans-serif',
        load: null,
    },
    {
        key: "press-start-2p",
        name: "Press Start 2P",
        category: "display",
        stack: '"Press Start 2P", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource/press-start-2p/latin-400.css")]),
    },
    {
        key: "quicksand",
        name: "Quicksand",
        category: "sans",
        stack: '"Quicksand Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource-variable/quicksand/wght.css")]),
    },
    {
        key: "raleway",
        name: "Raleway",
        category: "sans",
        stack: '"Raleway Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/raleway/wght.css"),
                import("@fontsource-variable/raleway/wght-italic.css"),
            ]),
    },
    {
        key: "righteous",
        name: "Righteous",
        category: "display",
        stack: '"Righteous", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource/righteous/latin-400.css")]),
    },
    {
        key: "roboto",
        name: "Roboto",
        category: "sans",
        stack: '"Roboto Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/roboto/wght.css"),
                import("@fontsource-variable/roboto/wght-italic.css"),
            ]),
    },
    {
        key: "rubik",
        name: "Rubik",
        category: "sans",
        stack: '"Rubik Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/rubik/wght.css"),
                import("@fontsource-variable/rubik/wght-italic.css"),
            ]),
    },
    {
        key: "satisfy",
        name: "Satisfy",
        category: "script",
        stack: '"Satisfy", cursive',
        load: () => Promise.all([import("@fontsource/satisfy/latin-400.css")]),
    },
    {
        key: "shadows-into-light",
        name: "Shadows Into Light",
        category: "script",
        stack: '"Shadows Into Light", cursive',
        load: () =>
            Promise.all([
                import("@fontsource/shadows-into-light/latin-400.css"),
            ]),
    },
    {
        key: "sora",
        name: "Sora",
        category: "sans",
        stack: '"Sora Variable", ui-sans-serif, system-ui, sans-serif',
        load: () => Promise.all([import("@fontsource-variable/sora/wght.css")]),
    },
    {
        key: "source-serif-4",
        name: "Source Serif 4",
        category: "serif",
        stack: '"Source Serif 4 Variable", ui-serif, Georgia, serif',
        load: () =>
            Promise.all([
                import("@fontsource-variable/source-serif-4/wght.css"),
                import("@fontsource-variable/source-serif-4/wght-italic.css"),
            ]),
    },
    {
        key: "space-grotesk",
        name: "Space Grotesk",
        category: "sans",
        stack: '"Space Grotesk", ui-sans-serif, system-ui, sans-serif',
        load: null,
    },
    {
        key: "space-mono",
        name: "Space Mono",
        category: "mono",
        stack: '"Space Mono", ui-monospace, monospace',
        load: () =>
            Promise.all([
                import("@fontsource/space-mono/latin-400.css"),
                import("@fontsource/space-mono/latin-400-italic.css"),
                import("@fontsource/space-mono/latin-700.css"),
                import("@fontsource/space-mono/latin-700-italic.css"),
            ]),
    },
    {
        key: "syne",
        name: "Syne",
        category: "display",
        stack: '"Syne Variable", ui-sans-serif, system-ui, sans-serif',
        load: () => Promise.all([import("@fontsource-variable/syne/wght.css")]),
    },
    {
        key: "unbounded",
        name: "Unbounded",
        category: "display",
        stack: '"Unbounded Variable", ui-sans-serif, system-ui, sans-serif',
        load: () =>
            Promise.all([import("@fontsource-variable/unbounded/wght.css")]),
    },
    {
        key: "work-sans",
        name: "Work Sans",
        category: "sans",
        stack: '"Work Sans", ui-sans-serif, system-ui, sans-serif',
        load: null,
    },
];

const BY_KEY = Object.fromEntries(
    FONT_CATALOGUE.map((font) => [font.key, font]),
);

/** Fonts somebody uploaded, by key: `{ name, url }`. Filled from the deck. */
const uploaded = reactive(new Map());

/** What has been asked for already, so nothing is loaded twice. */
const loading = new Map();

const uploadFamily = (key) => `Aurora ${key}`;

/**
 * Make uploaded fonts known, from the deck's appearance or after an upload.
 *
 * @param {Array<{key: string, name: string, url: string}>} fonts
 */
export function registerUploadedFonts(fonts) {
    for (const font of fonts ?? []) {
        if (font?.key && font?.url) uploaded.set(font.key, font);
    }
}

export const uploadedFonts = () => [...uploaded.values()];

/** The CSS stack for a key, or null for the deck's own two roles. */
export function stackFor(key) {
    if (!key || key === "heading" || key === "body") return null;

    if (BY_KEY[key]) return BY_KEY[key].stack;

    if (uploaded.has(key))
        return `"${uploadFamily(key)}", ui-sans-serif, system-ui, sans-serif`;

    return null;
}

/** The name a person reads in the picker. */
export function labelFor(key) {
    return BY_KEY[key]?.name ?? uploaded.get(key)?.name ?? key;
}

/**
 * Start loading a family, once.
 *
 * Failures are swallowed on purpose: a font that does not arrive leaves the
 * fallback in its stack, which is a slide in another face rather than a slide
 * that does not draw.
 */
export function ensureFont(key) {
    if (!key || loading.has(key)) return loading.get(key) ?? Promise.resolve();

    let promise = Promise.resolve();

    if (BY_KEY[key]?.load) {
        promise = BY_KEY[key].load().catch(() => {});
    } else if (uploaded.has(key) && typeof FontFace !== "undefined") {
        const face = new FontFace(
            uploadFamily(key),
            `url("${uploaded.get(key).url}")`,
            { display: "swap" },
        );

        promise = face
            .load()
            .then((loaded) => document.fonts.add(loaded))
            .catch(() => {});
    }

    loading.set(key, promise);

    return promise;
}

/**
 * The families a slide names, as CSS stacks by key, loaded as they appear.
 *
 * The uploaded fonts of the deck travel in its appearance, so a share link
 * knows them without asking the back office.
 */
export function useFreeFonts(keys, appearance) {
    watch(
        () => appearance()?.fonts,
        (fonts) => registerUploadedFonts(fonts),
        { immediate: true },
    );

    watch(
        keys,
        (now) => {
            for (const key of new Set(now)) ensureFont(key);
        },
        { immediate: true },
    );

    const families = computed(() => {
        const map = {};

        for (const key of keys()) {
            const stack = stackFor(key);

            if (stack) map[key] = stack;
        }

        return map;
    });

    return { families };
}
