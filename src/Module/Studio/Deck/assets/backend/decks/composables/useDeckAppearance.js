import { computed, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * The deck's look: a theme, and what this deck changes about it.
 *
 * **Two shapes for the same thing, and both are needed.** `style` is what
 * somebody chose, where an absent key means "whatever the theme says";
 * `appearance` is the answer after the merge, which is what the frames draw.
 * Keeping only the merged one would make the panel unable to tell a deliberate
 * choice of the theme's own blue from no choice at all, and a theme changed
 * later would stop reaching the deck.
 *
 * The panel writes on close rather than on every keystroke: a colour is picked
 * by dragging through a hundred of them, and a request per hue would be a
 * hundred writes for one decision.
 */
export function useDeckAppearance(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const open = ref(false);
    const saving = ref(false);

    /** What the frames draw with, replaced wholesale by the server's answer. */
    const appearance = ref({ ...(props.deck.appearance ?? {}) });

    /**
     * What the server holds, which is not what the props hold after a save.
     *
     * The props are the page as it was rendered and never change again;
     * reverting a cancelled edit to them would put back the look the deck had
     * when the tab was opened, undoing a save made five minutes earlier.
     */
    const saved = ref({
        theme: props.deck.theme ?? "slate",
        style: { ...(props.deck.style ?? {}) },
    });

    const BLANK = {
        background: null,
        ink: null,
        accent: null,
        // "none" and not null, like `logoPlacement`: the flat ground is a
        // value somebody can pick, not the absence of a choice.
        gradient: null,
        pattern: null,
        margins: "normal",
        hairline: false,
        rules: false,
        titleCase: "normal",
        bullets: "disc",
        fontPair: null,
        logoMediaId: null,
        logoPlacement: "none",
        footerText: "",
        slideNumbers: false,
        transition: null,
    };

    const theme = ref(saved.value.theme);

    // `reactive` over a plain object rather than a ref per field: the panel has
    // seven controls and they are one decision, saved together.
    const style = reactive({ ...BLANK, ...saved.value.style });

    /** The picked logo, as the picker speaks it: an id here, an address there. */
    const logo = ref({
        id: props.deck.style?.logoMediaId ?? null,
        url: props.deck.appearance?.logoUrl ?? null,
    });

    const themeOf = (value) =>
        (props.themes ?? []).find((row) => row.value === value) ?? null;

    /**
     * The colours the current theme starts from.
     *
     * The panel needs them to show what an unset field will produce, which is
     * the difference between "inherits #101317" and an empty swatch that reads
     * as black.
     */
    const inherited = computed(
        () =>
            themeOf(theme.value)?.palette ?? {
                background: "",
                ink: "",
                accent: "",
            },
    );

    const isOverridden = computed(() =>
        ["background", "ink", "accent", "fontPair"].some((key) => style[key]),
    );

    function resetColours() {
        style.background = null;
        style.ink = null;
        style.accent = null;
        style.fontPair = null;
    }

    /** One door into the style, so the panel never writes into a prop. */
    function write(key, value) {
        if (!(key in BLANK)) return;

        style[key] = value;
    }

    function writeLogo(value) {
        logo.value = { id: value?.id ?? null, url: value?.url ?? null };
        style.logoMediaId = value?.id ?? null;

        // Picking a logo with no placement chosen would store a picture nothing
        // draws. The corner of every slide is the answer that makes the choice
        // visible straight away; it is one select away from the other two.
        if (style.logoMediaId && style.logoPlacement === "none") {
            style.logoPlacement = "every";
        }
    }

    /** The payload: nothing that was left alone, so the theme keeps reaching it. */
    function payload() {
        const written = {};

        for (const key of ["background", "ink", "accent", "fontPair"]) {
            if (style[key]) written[key] = style[key];
        }

        if (style.logoMediaId) {
            written.logoMediaId = style.logoMediaId;
            written.logoPlacement = style.logoPlacement;
        }

        if (style.footerText?.trim())
            written.footerText = style.footerText.trim();
        if (style.slideNumbers) written.slideNumbers = true;

        // Written even when it is the default, unlike the colours: `fade` is
        // what a deck does when nobody chose, and storing nothing for it would
        // make "I picked fade" and "I never looked at this" the same row.
        if (style.transition) written.transition = style.transition;

        return written;
    }

    async function save() {
        if (saving.value) return;

        saving.value = true;

        try {
            const data = await request(props.appearancePath, {
                theme: theme.value,
                style: payload(),
            });

            if (!data?.appearance) return;

            appearance.value = data.appearance;
            saved.value = { theme: data.theme, style: { ...data.style } };
            logo.value = {
                id: data.style?.logoMediaId ?? null,
                url: data.appearance.logoUrl,
            };
            toast.success(t("backend.studio.decks.appearance_saved"));
            open.value = false;
        } finally {
            saving.value = false;
        }
    }

    /**
     * The preview, live, without a round trip.
     *
     * The same merge the server does, repeated here and only here: the panel
     * has to answer "what would this look like" before anything is written, and
     * a preview that waited for the save would make choosing a colour a
     * sequence of saves. Everything that leaves the panel is drawn from the
     * server's answer instead.
     */
    const preview = computed(() => {
        const base = themeOf(theme.value);

        // The deck's own choice, else the theme's own pair, else whatever the
        // server last resolved. The middle one is why `themeOptions()` carries
        // `fontPair`: without it, picking Paper previewed its colours in the
        // previous theme's faces, which is the one thing the preview is for.
        const wanted =
            style.fontPair ?? base?.fontPair ?? appearance.value.fontPair;
        const faces = (props.fontPairs ?? []).find(
            (row) => row.value === wanted,
        ) ?? { heading: "inherit", body: "inherit" };

        return {
            background: style.background ?? base?.palette.background,
            ink: style.ink ?? base?.palette.ink,
            accent: style.accent ?? base?.palette.accent,
            // Both default to the flat ground rather than to the theme: they
            // are the deck's own, and no theme carries one.
            gradient: style.gradient ?? base?.gradient ?? "none",
            pattern: style.pattern ?? base?.pattern ?? "none",
            margins: style.margins ?? "normal",
            hairline: style.hairline === true,
            rules: style.rules === true,
            titleCase: style.titleCase ?? "normal",
            bullets: style.bullets ?? "disc",
            headingFont: faces.heading,
            bodyFont: faces.body,
            logoUrl: logo.value.url,
            logoAlt: appearance.value.logoAlt ?? "",
            logoPlacement: style.logoMediaId ? style.logoPlacement : "none",
            footerText: style.footerText?.trim() || null,
            slideNumbers: style.slideNumbers === true,
            transition: style.transition ?? "fade",
        };
    });

    // Switching theme clears nothing by itself, but a reader who has overridden
    // the accent and then picks another theme almost never wants the old accent
    // carried over. Said out loud in the panel rather than done silently.
    const carriesOverrides = computed(
        () => isOverridden.value && theme.value !== saved.value.theme,
    );

    watch(open, (isOpen) => {
        if (isOpen) return;

        // Closing without saving puts back what the server holds, so a panel
        // reopened shows the deck as it is rather than as it was nearly made.
        theme.value = saved.value.theme;
        Object.assign(style, { ...BLANK, ...saved.value.style });
        logo.value = {
            id: saved.value.style.logoMediaId ?? null,
            url: appearance.value.logoUrl ?? null,
        };
    });

    return {
        open,
        saving,
        appearance,
        theme,
        style,
        logo,
        inherited,
        isOverridden,
        carriesOverrides,
        preview,
        resetColours,
        write,
        writeLogo,
        save,
    };
}
