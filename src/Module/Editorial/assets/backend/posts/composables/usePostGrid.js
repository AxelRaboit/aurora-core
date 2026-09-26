import { computed } from "vue";
import { useI18n } from "vue-i18n";
import {
    AudioLines,
    ClipboardList,
    Code,
    FileDown,
    FileText,
    Film,
    Frame,
    Github,
    CircleDot,
    Clock,
    Timer,
    Contact,
    Heart,
    QrCode,
    ChartColumn,
    Smartphone,
    CalendarDays,
    Activity,
    Receipt,
    Vote,
    Map as MapIcon,
    Calculator,
    CalendarCheck,
    Image,
    Images,
    Columns2,
    Layers,
    LayoutPanelTop,
    Recycle,
    LayoutList,
    ListFilter,
    ListTree,
    Tags,
    MapPin,
    MessageSquare,
    MousePointerClick,
    Newspaper,
    Presentation,
    Search,
    SeparatorHorizontal,
} from "lucide-vue-next";

/**
 * Drives the content-grid panel of the post editor.
 *
 * Holds no state of its own, and reads from two places for the same reason
 * usePostBanner does: the **arrangement** lives on the post and is shared by
 * every language, the **content** of each zone lives in the current
 * translation. Switching locale swaps what fills the zones and leaves the
 * layout standing.
 *
 * Every field is a writable computed rather than being bound straight in the
 * template: `v-model` on a prop's property is a mutation ESLint rightly
 * refuses, and the write belongs next to the mapping it goes through.
 */
export const COLUMNS = 48;
const MAX_ZONES = 60;

/** Mirrors GridNormalizer::SNAPS - four is twelfths, the usual way to talk about a layout. */
export const SNAPS = [4, 2, 1];

/** What a zone may be inside a stack, where nesting stops. */
export const LEAF_ZONE_TYPES = [
    "text",
    "media",
    "post",
    "video",
    "audio",
    "document",
    "terms",
    "map",
    "gallery",
    "compare",
    "shared",
    "tabs",
    "embed",
    "search",
    "comments",
    "deck",
    "button",
    "separator",
    "items",
    "postList",
    "form",
    "code",
    "toc",
    "githubActivity",
    "availability",
    "openingHours",
    "countdown",
    "contactCard",
    "socialPost",
    "qrCode",
    "chart",
    "videoWall",
    "editorialCalendar",
    "activityFeed",
    "priceList",
    "poll",
    "travelMap",
    "quoteEstimator",
    "appointmentBooking",
];

/** Mirrors GridNormalizer::ZONE_TYPES - a stack is top level only. */
export const ZONE_TYPES = [...LEAF_ZONE_TYPES, "stack"];

/**
 * One picture per zone type, for the palette and for the boxes on the canvas.
 *
 * Here rather than in the two components that draw it. The map lived in both,
 * and both stopped at the five types that existed when it was written: every
 * zone added since - a button, a form, a summary - arrived in the palette as a
 * word with a gap where the others have a glyph.
 *
 * Every type has one, and ZoneIconCoverageTest fails if a new one does not.
 */
export const ZONE_ICONS = {
    text: FileText,
    media: Image,
    post: Newspaper,
    video: Film,
    // A waveform, not a speaker: the zone holds a recording, and a speaker is
    // what a reader turns off.
    audio: AudioLines,
    // The arrow is the whole point of this zone - a sheet of paper alone would
    // read as the text zone beside it.
    document: FileDown,
    // Labels on a string, which is what a set of terms is.
    terms: Tags,
    // A pin, and the one place in this map where a pin is honest: it marks
    // an address rather than standing on a tile somebody else served.
    map: MapPin,
    // Pictures, plural - the one thing that separates it from the media
    // zone above, and the whole of what it is.
    gallery: Images,
    // Two panes with a line between them, which is the zone in one glyph.
    compare: Columns2,
    // The same thing, coming round again on another page.
    shared: Recycle,
    // Panels behind one strip of labels.
    // A frame holding something that lives somewhere else.
    embed: Frame,
    tabs: LayoutPanelTop,
    search: Search,
    comments: MessageSquare,
    deck: Presentation,
    button: MousePointerClick,
    separator: SeparatorHorizontal,
    items: LayoutList,
    // A list that asks a question rather than naming its answers.
    postList: ListFilter,
    form: ClipboardList,
    code: Code,
    // Headings, indented under one another - which is what a summary is.
    toc: ListTree,
    // The one brand glyph in this map, because the zone is about one
    // brand's grid and nothing generic says so.
    githubActivity: Github,
    // A dot, which is the whole of what the zone says before its sentence.
    availability: CircleDot,
    openingHours: Clock,
    countdown: Timer,
    contactCard: Contact,
    // The counter every network puts under a post.
    socialPost: Heart,
    qrCode: QrCode,
    chart: ChartColumn,
    // A phone held upright: the shape of every film on the wall.
    videoWall: Smartphone,
    editorialCalendar: CalendarDays,
    activityFeed: Activity,
    priceList: Receipt,
    poll: Vote,
    travelMap: MapIcon,
    quoteEstimator: Calculator,
    appointmentBooking: CalendarCheck,
    stack: Layers,
};

/** Mirrors GridNormalizer::BUTTON_VARIANTS. */
export const BUTTON_VARIANTS = ["solid", "outline", "ghost"];

/** Mirrors GridNormalizer::SIZES - shared by the button and the separator. */
export const SIZES = ["sm", "md", "lg"];

/** Mirrors GridZoneOptions: the settings one kind of zone has and no other. */
export const GALLERY_LAYOUTS = ["grid", "carousel"];
export const FRAMES = ["none", "laptop", "phone", "browser"];
export const CODE_STYLES = ["plain", "terminal", "diff"];
export const LIST_LAYOUTS = ["cards", "index"];
export const GITHUB_MODES = ["activity", "repos", "releases"];
export const AVAILABILITIES = ["available", "soon", "busy"];
export const WEEKDAYS = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
export const SOCIAL_NETWORKS = ["instagram", "linkedin", "facebook", "x"];
export const CHART_TYPES = ["bar", "line", "donut", "growth"];
export const POLL_RESULTS = ["after", "always"];
export const SLOT_DURATIONS = [15, 30, 45, 60, 90];
export const BOOKING_WINDOWS = [7, 14, 21, 30, 45];

/**
 * What `options` holds on a new zone - every key, whatever the type, for the
 * reason the top-level keys are all there: switching a zone's type and back
 * must not lose what was set. Mirrors GridZoneOptions::defaults().
 */
export function defaultZoneOptions() {
    return {
        galleryLayout: "grid",
        frame: "none",
        parallax: false,
        codeStyle: "plain",
        listLayout: "cards",
        githubMode: "activity",
        githubRepos: [],
        availability: "available",
        availableFrom: null,
        hours: Object.fromEntries(WEEKDAYS.map((day) => [day, []])),
        closedDates: [],
        timezone: "Europe/Paris",
        countdownAt: null,
        contactName: "",
        contactPhone: "",
        contactEmail: "",
        contactWebsite: null,
        socialNetwork: "instagram",
        socialName: "",
        socialHandle: "",
        socialAvatarId: null,
        socialLikes: 0,
        socialComments: 0,
        socialShares: 0,
        socialDate: null,
        qrLogoId: null,
        qrDownload: true,
        chartType: "bar",
        chartUnit: "",
        showExif: false,
        backgroundVideo: false,
        calendarMonth: null,
        feedGithub: false,
        pollResults: "after",
        quoteCurrency: "€",
        slotDuration: 15,
        bookingWindowDays: 7,
    };
}

/** Mirrors GridNormalizer::SEPARATOR_STYLES. */
export const SEPARATOR_STYLES = ["line", "space", "wave", "diagonal", "bevel"];

/** Mirrors GridNormalizer::ITEM_DISPLAYS - the nine costumes of an item list. */
export const ITEM_DISPLAYS = [
    "steps",
    "stats",
    "faq",
    "quotes",
    "logos",
    "timeline",
    "offers",
    "people",
    "scrolly",
];

/** Mirrors GridNormalizer::ITEM_COLUMNS. */
export const ITEM_COLUMNS = [2, 3, 4];

/** Mirrors GridNormalizer::MAX_ITEMS. */
export const MAX_ITEMS = 12;

/** Mirrors GridNormalizer::MAX_TABS - six labels already wrap on a phone. */
export const MAX_TABS = 6;

/** Mirrors GridNormalizer::MAX_GALLERY_IMAGES. */
export const MAX_GALLERY_IMAGES = 24;

/** Mirrors GridNormalizer::COMPARE_IMAGES - before and after, never a third. */
export const COMPARE_IMAGES = 2;

/** Mirrors GridNormalizer::CARD_VARIANTS - how densely a card is drawn. */
export const CARD_VARIANTS = ["full", "compact", "horizontal"];

/** Mirrors GridNormalizer::MAX_LIST_LIMIT. */
export const MAX_LIST_LIMIT = 24;

/** Mirrors GridNormalizer::CODE_LANGUAGES. */
export const CODE_LANGUAGES = [
    "bash",
    "css",
    "html",
    "javascript",
    "json",
    "markdown",
    "php",
    "python",
    "sql",
    "typescript",
    "yaml",
];

/** Mirrors GridNormalizer::AUDIENCES - everybody, or somebody signed in. */
export const AUDIENCES = ["everyone", "members"];

/** Mirrors GridNormalizer::TEXT_SIZES. */
export const TEXT_SIZES = ["normal", "lead", "small"];

/** Mirrors GridNormalizer::SURFACES - what a zone sits on. */
export const SURFACES = ["none", "card", "soft", "accent", "custom"];

/** Mirrors GridNormalizer::ZONE_FILL_TYPES - a zone's own background, read only under `custom`. */
export const ZONE_FILL_TYPES = ["none", "solid", "gradient"];

/** Mirrors GridNormalizer::ZONE_CONTRASTS - forces a zone's own text/border scheme. */
export const ZONE_CONTRASTS = ["auto", "light", "dark"];

/** Mirrors GridNormalizer::REVEALS - how the page's zones arrive on scroll. */
export const REVEALS = ["none", "fade", "up", "left", "right", "zoom", "blur"];

/**
 * Mirrors GridNormalizer::ZONE_REVEALS - the same list, plus the answer a
 * zone gives by default: whatever the page says. Asking for the same arrival
 * ten times is how a page ends up animated in patches.
 */
export const ZONE_REVEALS = ["inherit", ...REVEALS];

/**
 * How many zones a stack may hold. Mirrors GridNormalizer::MAX_STACK_CHILDREN:
 * a stack splits one cell in two or three, and six zones sharing a row's height
 * are six slivers.
 */
const MAX_STACK_CHILDREN = 6;

/**
 * Mirrors GridNormalizer::RATIOS - the shape a media zone is cropped to, and
 * the only vertical control the grid offers. `natural` is what every zone
 * starts as, because the default has to be what is already published.
 */
export const ZONE_RATIOS = ["natural", "16x9", "4x3", "1x1", "3x4", "fill"];

/**
 * Mirrors GridNormalizer::SCALES - how much of its zone's width a picture
 * takes. A width rather than a height because a picture keeps its proportions:
 * halving the width halves the height, and a percentage survives a phone where
 * a number of pixels does not.
 */
export const ZONE_SCALES = [25, 33, 50, 66, 75, 100];

/**
 * Mirrors GridNormalizer::ALIGNMENTS - which side a picture sits on once it is
 * narrower than its zone. Centre first: it is what a smaller picture did before
 * this was a choice, and the default has to be the behaviour already published.
 */
export const ZONE_ALIGNMENTS = ["center", "left", "right"];

/**
 * The widths an author actually draws with, named the way they think of them.
 *
 * "24 of 48 columns" is a coordinate; "a half" is a thought. Every one of these
 * lands on a whole number of columns because 48 is 4 × 12 - and, as it happens,
 * every one is a multiple of four, so they survive `clampToSnap` at any step.
 * The snap and the fractions never disagree.
 *
 * Sixths (8 and 40) are arithmetically just as clean and are deliberately left
 * out: nobody lays a page out in fifths of anything, and this row is meant to be
 * easy to aim at. They stay reachable through the precise slider.
 *
 * Ordered by width, so the arrow that widens a zone here is the same direction
 * as the handle that widens it on the canvas.
 */
export const WIDTH_FRACTIONS = [
    { columns: 12, label: "1/4", name: "quarter" },
    { columns: 16, label: "1/3", name: "third" },
    { columns: 24, label: "1/2", name: "half" },
    { columns: 32, label: "2/3", name: "two_thirds" },
    { columns: 36, label: "3/4", name: "three_quarters" },
    { columns: 48, label: "1/1", name: "full" },
];

function writable(get, set) {
    return computed({ get, set });
}

/**
 * The width a zone has on a large screen, following the stylesheet's own
 * fallback chain: an unset breakpoint inherits the one below it.
 */
export function largeSpan(zone) {
    const columns =
        zone?.span?.lg ?? zone?.span?.md ?? zone?.span?.base ?? COLUMNS;

    return Math.min(COLUMNS, Math.max(1, columns));
}

/**
 * An offset the row can actually hold - never more than what is left once the
 * zone has taken its own width.
 *
 * Mirrors GridNormalizer::clampOffset. Written here as well as at the write
 * boundary because this one runs on the layout as it is being edited, before
 * anything has been saved: the canvas has to draw what a half-finished drag is
 * asking for, and it has to draw it the way the server will store it.
 */
export function clampOffset(offset, zone) {
    return Math.max(
        0,
        Math.min(COLUMNS - largeSpan(zone), Math.round(Number(offset) || 0)),
    );
}

/**
 * Where each zone lands: its row and its first column, both 1-based.
 *
 * Mirrors GridNormalizer::place. The canvas emits what this returns as
 * `--row-base` and `--start-base`, so the picture in the panel and the
 * published page are the same arithmetic rather than two guesses that happen to
 * agree.
 *
 * The row is worked out here rather than left to auto-placement, which was the
 * first attempt and was not enough: a grid puts an item with a definite column
 * in the first row where those columns are free, so a zone asked to start a new
 * row - whose columns happened to be free beside its neighbour - was placed
 * there and the break did nothing.
 *
 * Widths come from `span.lg`: the large-screen arrangement is the one an author
 * sets, and every zone is full width below that.
 *
 * @param {Array<object>} zones
 * @return {Array<{row: number, column: number}>} one per zone, in order
 */
export function placeZones(zones) {
    let row = 1;
    let used = 0;

    return zones.map((zone) => {
        const span = largeSpan(zone);
        const offset = clampOffset(zone?.offset, zone);

        if (zone?.newRow && used > 0) {
            row += 1;
            used = 0;
        }

        let start;

        if (offset > 0) {
            // A row fills from the left, so what is free on it is always its
            // tail: an asked-for column below the mark is taken, and the zone
            // goes to the next row where the same column is free by definition.
            if (offset < used) {
                row += 1;
                used = 0;
            }

            start = offset;
        } else {
            if (used + span > COLUMNS) {
                row += 1;
                used = 0;
            }

            start = used;
        }

        used = start + span;

        return { row, column: start + 1 };
    });
}

/**
 * What dropping a zone somewhere would come to, without doing it.
 *
 * Pure, and shared by the canvas and the composable for one reason: the ghost
 * the author sees under the pointer has to be the layout they get when they let
 * go. Computing the preview one way and the move another is how a drop lands
 * somewhere other than where it was aimed.
 *
 * `target` is a place in the order with the moved zone already taken out of it,
 * and `column` the zero-based column it was dropped on. `newRow` says the drop
 * fell between rows rather than beside a zone.
 *
 * **The flow is preferred over an annotation.** If the zone lands on the asked
 * column with no offset at all - dropped right after its neighbour, say - that
 * is what is kept, because a layout that flows survives its neighbours changing
 * width and one pinned to a column does not.
 *
 * @return {{at: number, offset: number, newRow: boolean, place: {row: number, column: number}}|null}
 */
export function planMove(zones, index, target, column, newRow) {
    const list = [...zones];
    const [moving] = list.splice(index, 1);

    if (undefined === moving) {
        return null;
    }

    const at = Math.min(Math.max(0, target), list.length);
    const planned = { ...moving, offset: 0, newRow: Boolean(newRow) };
    list.splice(at, 0, planned);

    let place = placeZones(list)[at];

    if (place.column - 1 !== column) {
        planned.offset = clampOffset(column, planned);
        place = placeZones(list)[at];
    }

    return { at, offset: planned.offset, newRow: planned.newRow, place };
}

/**
 * The picture a media zone shows, or null.
 *
 * Pure, and exported so the canvas can draw the same one the page will. A picked
 * document first, the address only when there is none - the order the renderer
 * uses. A canvas showing the other one would be a picture of a page nobody is
 * going to get.
 */
export function zoneImage(zone) {
    if ("media" !== zone?.type) {
        return null;
    }

    return zone.media?.url ?? (zone.mediaUrl || null);
}

/**
 * What a box says about itself.
 *
 * A linked publication shows its title, everything else its type. Both are
 * values already in the editor's state, not markup rebuilt from the content.
 *
 * Takes the translator rather than reaching for `useI18n`, which keeps it
 * callable from a test with no i18n instance and out of the SFC, where the
 * convention does not want a computed label living.
 */
export function zoneLabel(zone, postOptions, t) {
    if ("post" === zone?.type) {
        const linked = postOptions.find((post) => post.id === zone.postId);

        return linked?.title ?? t("backend.posts.grid.zone_types.post");
    }

    return t(`backend.posts.grid.zone_types.${zone?.type}`);
}

/**
 * A fresh id for a zone. Random rather than a counter: a counter reuses the id
 * of a zone just removed, and the next one created would silently inherit its
 * content in every other language.
 */
function newZoneId() {
    if ("function" === typeof globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID().replaceAll("-", "").slice(0, 24);
    }

    return Array.from({ length: 24 }, () =>
        Math.floor(Math.random() * 36).toString(36),
    ).join("");
}

function newZone(type) {
    return {
        id: newZoneId(),
        type,
        // Full width on a phone, half on a large screen. A zone alone on its
        // row still fills it, because the grid has nothing to put beside it.
        span: { base: COLUMNS, md: null, lg: 24 },
        // No gap to its left and no break before it: a new zone joins the flow
        // where the last one left off, which is what "add a zone" has always
        // meant. Both are annotations an author reaches for, never defaults.
        offset: 0,
        newRow: false,
        ratio: "natural",
        scale: 100,
        align: "center",
        mediaId: null,
        mediaIds: [],
        media: null,
        mediaUrl: "",
        postId: null,
        // Present whatever the type, like every key here: switching a zone
        // from a button to a list and back must not lose what was picked.
        variant: "solid",
        size: "md",
        separatorStyle: "line",
        display: "steps",
        columns: 3,
        items: [],
        // A list with no filter is the whole site, newest first - the answer
        // that needs no setting up, which is what a zone should do on arrival.
        taxonomyId: null,
        deckId: null,
        postTypeId: null,
        termId: null,
        limit: 3,
        cardVariant: "full",
        formId: null,
        language: null,
        textSize: "normal",
        lineNumbers: false,
        exclusiveOpen: false,
        // No limits and everybody: a zone arrives visible, which is what
        // every zone written before this existed already means.
        visibleFrom: null,
        visibleUntil: null,
        audience: "everyone",
        options: defaultZoneOptions(),
        // No name until someone means to link to the zone. An id on every
        // zone would be a page full of addresses nobody chose.
        anchor: "",
        // Nothing behind it and inside its column: a zone arrives as part of
        // the page, and becomes a section only when someone says so.
        surface: "none",
        // Read only under `custom`, but present on every zone like `surface`
        // itself - switching away and back must not lose what was picked.
        background: {
            type: "none",
            color: null,
            gradientFrom: null,
            gradientTo: null,
            gradientAngle: 180,
            mediaId: null,
            media: null,
            videoId: null,
            video: null,
            overlay: 0,
        },
        // Whatever the page says, like `reveal` beside it.
        contrast: "auto",
        // Whatever the page says, which is still unless the page says
        // otherwise. A zone only carries its own answer when an author gave
        // it one, so changing the page's moves everything that never
        // disagreed.
        reveal: "inherit",
        // Personne ne colle une zone par défaut : c'est une décision de mise
        // en page, pas un comportement.
        sticky: false,
        fullBleed: false,
        // Empty on every zone, filled only by a stack - the same reason every
        // other key is always present: switching a type back and forth in the
        // editor must not lose what was picked.
        children: [],
    };
}

/**
 * Give every zone of a stack an equal share, summing to 48.
 *
 * `flex-grow` is relative, so equal values would already split evenly whatever
 * they are. Making them sum to 48 is for the author, not the browser: it is
 * what lets the fraction row say the truth - with three children at 16, "1/3"
 * really is a third of the height.
 */
function shareEvenly(children) {
    if (0 === children.length) {
        return;
    }

    const each = Math.max(1, Math.round(COLUMNS / children.length));

    for (const child of children) {
        child.span.lg = each;
    }
}

/** The four per-language fields, as a translation starts with them. */
function newZoneContent() {
    return {
        blocks: [],
        alt: "",
        caption: "",
        url: "",
        label: "",
        items: {},
        code: "",
    };
}

/** The four fields an entry of an item list holds, in whichever language. */
function newItemText() {
    return { title: "", description: "", caption: "", url: "" };
}

export function usePostGrid(layout, content) {
    const { t } = useI18n();

    const zones = computed(() => layout.value.zones);
    const canAddZone = computed(() => layout.value.zones.length < MAX_ZONES);

    const enabled = writable(
        () => layout.value.enabled,
        (value) => {
            layout.value.enabled = value;
        },
    );

    const snap = writable(
        () => layout.value.snap,
        (value) => {
            layout.value.snap = value;
        },
    );

    // The page's own answer, which every zone inherits until one disagrees.
    const reveal = writable(
        () => layout.value.reveal,
        (value) => {
            layout.value.reveal = value;
        },
    );

    const revealOptions = computed(() => labelled(REVEALS, "reveals"));

    const snapOptions = computed(() =>
        SNAPS.map((step) => ({
            value: step,
            label: t(`backend.posts.grid.snaps.${step}`),
        })),
    );

    const typeOptions = computed(() => typeChoices(ZONE_TYPES));

    // A zone inside a stack cannot become a stack: depth stops at one, and the
    // normaliser would drop it rather than nest it.
    const leafTypeOptions = computed(() => typeChoices(LEAF_ZONE_TYPES));

    /**
     * The choices the three new zone types offer, in one bag rather than five
     * props: they arrived together, they are all lists of {value, label}, and
     * the panel would otherwise carry eight option props side by side.
     */
    const zoneChoices = computed(() => ({
        variant: labelled(BUTTON_VARIANTS, "button_variants"),
        size: labelled(SIZES, "sizes"),
        separatorStyle: labelled(SEPARATOR_STYLES, "separator_styles"),
        display: labelled(ITEM_DISPLAYS, "item_displays"),
        cardVariant: labelled(CARD_VARIANTS, "card_variants"),
        textSize: labelled(TEXT_SIZES, "text_sizes"),
        surface: labelled(SURFACES, "surfaces"),
        fillType: labelled(ZONE_FILL_TYPES, "fill_types"),
        contrast: labelled(ZONE_CONTRASTS, "contrasts"),
        reveal: labelled(ZONE_REVEALS, "reveals"),
        audience: labelled(AUDIENCES, "audiences"),
        // A language names itself; there is nothing to translate.
        language: CODE_LANGUAGES.map((value) => ({ value, label: value })),
        limit: Array.from({ length: MAX_LIST_LIMIT }, (_, i) => ({
            value: i + 1,
            label: String(i + 1),
        })),
        // The figures are the same in every language, so they are their own label.
        columns: ITEM_COLUMNS.map((value) => ({ value, label: String(value) })),
        galleryLayout: labelled(GALLERY_LAYOUTS, "gallery_layouts"),
        frame: labelled(FRAMES, "frames"),
        codeStyle: labelled(CODE_STYLES, "code_styles"),
        listLayout: labelled(LIST_LAYOUTS, "list_layouts"),
        githubMode: labelled(GITHUB_MODES, "github_modes"),
        availability: labelled(AVAILABILITIES, "availabilities"),
        chartType: labelled(CHART_TYPES, "chart_types"),
        pollResults: labelled(POLL_RESULTS, "poll_results_modes"),
        slotDuration: SLOT_DURATIONS.map((value) => ({
            value,
            label: t("backend.posts.grid.minutes", { count: value }),
        })),
        bookingWindowDays: BOOKING_WINDOWS.map((value) => ({
            value,
            label: t("backend.posts.grid.days_count", { count: value }),
        })),
        // A network names itself.
        socialNetwork: SOCIAL_NETWORKS.map((value) => ({
            value,
            label: {
                instagram: "Instagram",
                linkedin: "LinkedIn",
                facebook: "Facebook",
                x: "X",
            }[value],
        })),
    }));

    function labelled(values, group) {
        return values.map((value) => ({
            value,
            label: t(`backend.posts.grid.${group}.${value}`),
        }));
    }

    function typeChoices(types) {
        return types.map((type) => ({
            value: type,
            label: t(`backend.posts.grid.zone_types.${type}`),
        }));
    }

    // The figures are the same in every language; the word for "full" is not.
    const scaleOptions = computed(() =>
        ZONE_SCALES.map((scale) => ({
            value: scale,
            label:
                100 === scale
                    ? t("backend.posts.grid.scales.full")
                    : `${scale} %`,
        })),
    );

    const alignOptions = computed(() =>
        ZONE_ALIGNMENTS.map((align) => ({
            value: align,
            label: t(`backend.posts.grid.alignments.${align}`),
        })),
    );

    const ratioOptions = computed(() =>
        ZONE_RATIOS.map((ratio) => ({
            value: ratio,
            label: t(`backend.posts.grid.ratios.${ratio}`),
        })),
    );

    // The figures are the same in every language, so they stay literal; the
    // spelt-out name rides along as the tooltip.
    const widthOptions = computed(() =>
        WIDTH_FRACTIONS.map((fraction) => ({
            value: fraction.columns,
            label: fraction.label,
            title: t(`backend.posts.grid.fractions.${fraction.name}`),
        })),
    );

    /**
     * The same fractions, less "the whole thing".
     *
     * A zone of a stack shares its height with the others by definition, so
     * offering it the full height offers a contradiction: the rebalance would
     * have to leave the others a sliver, and the button would promise something
     * the page cannot show. Three quarters is the most one zone may claim.
     */
    const shareOptions = computed(() =>
        widthOptions.value.filter((option) => option.value !== COLUMNS),
    );

    /**
     * How far a zone may be pushed from the left of its row, in the same
     * fractions its width is set in - so "1/2 wide, pushed by 1/2" reads as one
     * thought rather than as two unrelated numbers.
     *
     * "None" leads, and is what every zone has: pushing one is the exception,
     * and the row has to offer a way back to the flow.
     */
    const offsetOptions = computed(() => [
        { value: 0, label: t("backend.posts.grid.offsets.none") },
        ...WIDTH_FRACTIONS.filter((fraction) => fraction.columns < COLUMNS).map(
            (fraction) => ({
                value: fraction.columns,
                label: fraction.label,
                title: t(`backend.posts.grid.fractions.${fraction.name}`),
            }),
        ),
    ]);

    /**
     * Add a zone at a given place in the order, optionally opening a row for it.
     *
     * What the canvas's between-rows buttons call, and what makes "add an empty
     * row and put something in it" one gesture rather than three. There is no
     * empty row to add: a row is what zones make, so it appears with the zone
     * and goes when the last one leaves. Nothing to store, nothing to arbitrate
     * on a phone, and no way to leave a page with a blank band in it that
     * nobody meant.
     *
     * @return {number|null} where it landed, so a caller can select it.
     */
    function addZoneAt(type, target, options = {}) {
        if (!canAddZone.value) {
            return null;
        }

        const zone = newZone(type);
        zone.newRow = Boolean(options.newRow);

        // Filling a hole rather than opening a row: the zone takes the hole's
        // width, rounded *down* to the step so it cannot spill past it and push
        // the neighbour it was meant to sit beside onto another row.
        if (null !== (options.width ?? null)) {
            zone.span.lg = Math.max(
                snap.value,
                Math.floor(options.width / snap.value) * snap.value,
            );
        }

        const at = Math.min(
            Math.max(0, target ?? layout.value.zones.length),
            layout.value.zones.length,
        );
        layout.value.zones.splice(at, 0, zone);

        // The flow first, an annotation only if it does not already land there
        // - the same preference `planMove` applies, and for the same reason: a
        // zone that flows survives its neighbours changing width.
        if (
            null !== (options.column ?? null) &&
            placeZones(layout.value.zones)[at].column - 1 !== options.column
        ) {
            zone.offset = clampOffset(options.column, zone);
        }
        // Only this language's entry. The others gain theirs when the server
        // normalises their content against the layout - an empty zone is what
        // an untranslated one means.
        content.value.zones[zone.id] = newZoneContent();

        return at;
    }

    function addZone(type) {
        addZoneAt(type, layout.value.zones.length);
    }

    /**
     * The zones a stack holds, or an empty list for anything else - so a caller
     * can ask any zone without first checking what it is.
     */
    function childrenOf(index) {
        return layout.value.zones[index]?.children ?? [];
    }

    function canAddChild(index) {
        return childrenOf(index).length < MAX_STACK_CHILDREN;
    }

    /**
     * Adding re-shares the height evenly across the stack. An author who has
     * set proportions deliberately will set them again; one who has not gets
     * halves, then thirds, which is what "add another" is expected to mean.
     */
    function addChild(index, type) {
        const zone = layout.value.zones[index];

        if (undefined === zone || !canAddChild(index)) {
            return;
        }

        const child = newZone(type);
        zone.children.push(child);
        shareEvenly(zone.children);
        content.value.zones[child.id] = newZoneContent();
    }

    function removeChild(index, childIndex) {
        const zone = layout.value.zones[index];
        const [removed] = zone?.children?.splice(childIndex, 1) ?? [];

        if (removed) {
            delete content.value.zones[removed.id];
            shareEvenly(zone.children);
        }
    }

    function moveChild(index, childIndex, offset) {
        const list = layout.value.zones[index]?.children ?? [];
        const target = childIndex + offset;

        if (target < 0 || target >= list.length) {
            return;
        }

        [list[childIndex], list[target]] = [list[target], list[childIndex]];
    }

    /**
     * What a zone of a stack really gets, as a percentage.
     *
     * The spans are grow factors, so what counts is each one against their
     * total, not against 48. They sum to 48 when the editor set them and can
     * stop doing so the moment an author picks fractions by hand - at which
     * point "2/3" on both zones would be two lies. This is the number that
     * cannot lie, and the panel shows it beside the buttons.
     */
    /**
     * Take a zone off its row and put it inside a stack.
     *
     * The other half of "add a zone to a stack": that one makes a new zone,
     * this one moves the zone already laid out - which is the thing an author
     * has no other way to do, short of deleting and rebuilding it and losing
     * what every other language holds for it.
     *
     * The stack is held by reference rather than by index, because the splice
     * that removes the zone shifts every index after it - including, half the
     * time, the stack's own.
     *
     * Content is not touched: it is keyed by zone id, and the id travels.
     *
     * @return {boolean} whether the move happened, so a caller can leave the
     *                   selection alone when it did not.
     */
    function moveZoneIntoStack(fromIndex, stackIndex, atIndex) {
        const list = layout.value.zones;
        const stack = list[stackIndex];
        const moving = list[fromIndex];

        if (
            undefined === stack ||
            undefined === moving ||
            fromIndex === stackIndex
        ) {
            return false;
        }

        // Depth stops at one, so a stack cannot go inside a stack - the
        // normaliser would drop it on the way out, which is a zone silently
        // lost rather than a move refused.
        if ("stack" !== stack.type || "stack" === moving.type) {
            return false;
        }

        if (stack.children.length >= MAX_STACK_CHILDREN) {
            return false;
        }

        list.splice(fromIndex, 1);
        // A row's annotations mean nothing in a column. Cleared here rather
        // than left for the server, so what the canvas draws the moment the
        // zone lands is what a save would produce.
        moving.offset = 0;
        moving.newRow = false;
        stack.children.splice(atIndex, 0, moving);
        shareEvenly(stack.children);

        return true;
    }

    /**
     * Take a zone out of a stack and put it back on the row.
     *
     * The mirror of moving one in, and the reason a stack is not a trap: a zone
     * built inside one could otherwise only leave by being deleted, which drops
     * what every language holds for it.
     *
     * **Its span is reset rather than carried over.** Inside a stack the number
     * was a share of the height; on a row the same number is a width. Keeping
     * it would silently reinterpret 36 from "three quarters of the height" to
     * "three quarters of the row" - a value nobody chose, arrived at by the
     * field meaning two things. Half is what a new zone gets.
     *
     * `column` and `newRow` come from a drop on the empty canvas, which says
     * where on the page as well as where in the order. A drop on a box says
     * only the second, and passes neither.
     *
     * @return {boolean} whether the move happened.
     */
    function moveZoneOutOfStack(
        stackIndex,
        childIndex,
        atIndex,
        column = null,
        newRow = false,
    ) {
        const stack = layout.value.zones[stackIndex];
        const moving = stack?.children?.[childIndex];

        if (undefined === moving) {
            return false;
        }

        stack.children.splice(childIndex, 1);
        shareEvenly(stack.children);

        moving.span.lg = 24;
        moving.offset = 0;
        moving.newRow = Boolean(newRow);
        const at = Math.min(Math.max(0, atIndex), layout.value.zones.length);
        layout.value.zones.splice(at, 0, moving);

        // Dropped on the empty canvas rather than on a box, so the drop said a
        // column as well as a place. The flow first, as everywhere else - the
        // zone only takes an offset when it would not land there anyway.
        if (
            null !== column &&
            placeZones(layout.value.zones)[at].column - 1 !== column
        ) {
            moving.offset = clampOffset(column, moving);
        }

        return true;
    }

    /**
     * Hand the rest of the height back to the other zones of a stack.
     *
     * Shares are grow factors, so only their ratio matters - but an author
     * reading "2/3" means two thirds of the stack, not two parts against
     * whatever the neighbour happens to hold. Keeping the total at 48 is what
     * makes the fraction row honest.
     *
     * Split in proportion to what the others already had, so a stack an author
     * has tuned keeps its shape when one zone changes. Each keeps at least one
     * unit: a zone reduced to nothing would vanish from the page with no
     * control left to bring it back.
     */
    function rebalance(index, changedIndex) {
        const list = childrenOf(index);
        const others = list.filter((_, i) => i !== changedIndex);

        if (0 === others.length) {
            return;
        }

        const left = Math.max(
            others.length,
            COLUMNS - list[changedIndex].span.lg,
        );
        const before = others.reduce((sum, child) => sum + child.span.lg, 0);

        let given = 0;

        others.forEach((child, i) => {
            const share =
                0 === before
                    ? Math.round(left / others.length)
                    : Math.round((child.span.lg / before) * left);

            // The last one takes what rounding left over, so the total is 48
            // exactly rather than 47 or 49.
            child.span.lg =
                i === others.length - 1
                    ? Math.max(1, left - given)
                    : Math.max(1, share);

            given += child.span.lg;
        });
    }

    function childShare(index, childIndex) {
        const list = childrenOf(index);
        const total = list.reduce(
            (sum, child) => sum + (child.span?.lg ?? 0),
            0,
        );

        if (0 === total) {
            return 0;
        }

        return Math.round(((list[childIndex]?.span?.lg ?? 0) / total) * 100);
    }

    function removeZone(index) {
        const [removed] = layout.value.zones.splice(index, 1);

        if (removed) {
            delete content.value.zones[removed.id];
        }
    }

    /**
     * Exchange two zones' places in the order.
     *
     * A swap rather than a move-to-position, because that is the gesture the
     * canvas offers: a zone is dropped **on** another one. Dropping *between*
     * two zones would be the more expressive move and is much harder to aim
     * at - the rows re-flow as the widths shift, so the gap being aimed for
     * moves while it is being aimed at. A box is a target that stays still.
     *
     * Content follows without being touched: it is keyed by zone id, and the
     * ids travel with the zones.
     */
    function swapZones(a, b) {
        const list = layout.value.zones;

        if (a === b || undefined === list[a] || undefined === list[b]) {
            return;
        }

        [list[a], list[b]] = [list[b], list[a]];
    }

    /**
     * Resize a zone by its left edge, leaving its right edge where it is.
     *
     * The mirror of the width handle rather than a second kind of control: both
     * edges resize, and moving a zone is done by taking hold of it in the
     * middle. The left edge used to push the zone instead, which read as a move
     * and was not one - the width stayed put and the zone slid sideways.
     *
     * Two values change together, which is why this lives here rather than
     * being two writes from the panel: the edge that moves sets where the zone
     * starts, and what is left between there and the right edge is its width.
     *
     * **The edge cannot go left of where the order puts the zone.** Dragging it
     * past that would ask the zone to start before its own neighbour ends, and
     * the placement walk answers that by dropping it to the next row - the zone
     * would jump out from under the pointer. The floor is that flow position,
     * and reaching it clears the offset rather than pinning the zone to a column
     * it would have taken anyway.
     */
    function resizeZoneFromLeft(index, column) {
        const list = layout.value.zones;
        const zone = list[index];

        if (undefined === zone) {
            return;
        }

        const start = placeZones(list)[index].column - 1;
        const end = start + largeSpan(zone);

        const flowed = [...list];
        flowed[index] = { ...zone, offset: 0 };
        const floor = placeZones(flowed)[index].column - 1;

        if (end - snap.value < floor) {
            return;
        }

        // Rounded here rather than through clampToSnap, whose floor is the step
        // itself: column zero is a legitimate answer and would be rounded up.
        const asked = Math.round(Number(column) / snap.value) * snap.value;
        const next = Math.max(floor, Math.min(end - snap.value, asked));

        zone.span.lg = end - next;
        zone.offset = next === floor ? 0 : clampOffset(next, zone);
    }

    /**
     * Put a zone where it was dropped, rather than where the order happened to
     * leave it.
     *
     * The gesture the canvas offers alongside the swap, and the one that makes
     * a zone feel movable: drop it in empty space and it goes there - into that
     * place in the order, at that column, on a row of its own if it was dropped
     * between two. Before this, moving a zone rightwards meant dragging a
     * three-pixel handle, which is a resize gesture wearing a move's clothes.
     *
     * Content is not touched: it is keyed by zone id, and the id travels.
     *
     * @return {boolean} whether the move happened.
     */
    function moveZoneTo(index, target, column, newRow) {
        const list = layout.value.zones;
        const plan = planMove(list, index, target, column, newRow);

        if (null === plan) {
            return false;
        }

        const [moving] = list.splice(index, 1);
        moving.offset = plan.offset;
        moving.newRow = plan.newRow;
        list.splice(plan.at, 0, moving);

        return true;
    }

    /**
     * Reordering by one step, which is what the up/down buttons do - and the
     * path that works without a pointer, so it stays whatever the canvas
     * offers.
     */
    function moveZone(index, offset) {
        const target = index + offset;

        if (target < 0 || target >= layout.value.zones.length) {
            return;
        }

        swapZones(index, target);
    }

    /**
     * Two levels and no more, which is what lets a path be two numbers rather
     * than a list to walk. The normaliser refuses a stack inside a stack, so
     * there is no third.
     */
    function zoneAt(index, childIndex = null) {
        return null === childIndex
            ? layout.value.zones[index]
            : layout.value.zones[index]?.children?.[childIndex];
    }

    /**
     * What a zone holds, in whichever language is open. Created on demand: a
     * zone added in one locale reaches the others with no entry of its own
     * until someone types in them.
     *
     * `items` is filled in separately because a translation written before
     * item lists existed has every other key and not that one - reading it as
     * absent would throw on the first entry added.
     *
     * An empty one arrives as `[]` rather than `{}`, PHP having no way to say
     * which it meant, and an array takes `items[id] = …` quietly and loses it
     * on `JSON.stringify`. So the shape is checked, not just the presence:
     * this is where a page's item texts were dropped between the screen they
     * were typed on and the server.
     */
    function heldFor(zone) {
        content.value.zones[zone.id] ??= newZoneContent();

        const held = content.value.zones[zone.id];

        if (!held.items || Array.isArray(held.items)) {
            held.items = {};
        }

        return held;
    }

    // Built once per index and cached: the template calls zoneFields(index) on
    // every render, and handing back fresh computeds each time would throw
    // away their caching for nothing.
    const zoneFieldsCache = new Map();

    function zoneFields(index, childIndex = null) {
        const key = null === childIndex ? `${index}` : `${index}:${childIndex}`;

        if (!zoneFieldsCache.has(key)) {
            const zone = () => zoneAt(index, childIndex);
            const held = () => {
                const target = zone();

                return undefined === target ? {} : heldFor(target);
            };

            // The zone's own background, read only under `custom`. Created on
            // demand like a banner translation's local picture: a zone built
            // before this existed arrives without one.
            const background = () => {
                const target = zone();
                if (undefined === target) return {};

                target.background ??= {
                    type: "none",
                    color: null,
                    gradientFrom: null,
                    gradientTo: null,
                    gradientAngle: 180,
                    mediaId: null,
                    media: null,
                    videoId: null,
                    video: null,
                    overlay: 0,
                };

                return target.background;
            };

            const backgroundField = (key) =>
                writable(
                    () => background()[key],
                    (value) => {
                        background()[key] = value;
                    },
                );

            const shared = (key) =>
                writable(
                    () => zone()?.[key],
                    (value) => {
                        zone()[key] = value;
                    },
                );

            const localised = (key) =>
                writable(
                    () => held()[key] ?? "",
                    (value) => {
                        held()[key] = value;
                    },
                );

            // A setting of one kind of zone, under `options`. Replaced rather
            // than mutated in place, so the whole object is a new value and
            // anything watching the layout sees the change.
            const option = (name) =>
                writable(
                    () => zone()?.options?.[name] ?? defaultZoneOptions()[name],
                    (value) => {
                        zone().options = {
                            ...defaultZoneOptions(),
                            ...(zone().options ?? {}),
                            [name]: value,
                        };
                    },
                );

            zoneFieldsCache.set(key, {
                // Shared - the settings of one kind of zone.
                ...Object.fromEntries(
                    Object.keys(defaultZoneOptions()).map((name) => [
                        name,
                        option(name),
                    ]),
                ),
                // Shared - the arrangement.
                type: shared("type"),
                postId: shared("postId"),
                ratio: shared("ratio"),
                scale: shared("scale"),
                align: shared("align"),
                mediaUrl: shared("mediaUrl"),
                variant: shared("variant"),
                size: shared("size"),
                separatorStyle: shared("separatorStyle"),
                display: shared("display"),
                columns: shared("columns"),
                taxonomyId: shared("taxonomyId"),
                deckId: shared("deckId"),
                postTypeId: shared("postTypeId"),
                termId: shared("termId"),
                limit: shared("limit"),
                cardVariant: shared("cardVariant"),
                formId: shared("formId"),
                language: shared("language"),
                textSize: shared("textSize"),
                lineNumbers: shared("lineNumbers"),
                exclusiveOpen: shared("exclusiveOpen"),
                visibleFrom: shared("visibleFrom"),
                visibleUntil: shared("visibleUntil"),
                audience: shared("audience"),
                anchor: shared("anchor"),
                surface: shared("surface"),
                // Read only when `surface` above is `custom` - see
                // PostGridPanel.vue - but always present, like the other
                // fields here.
                fillType: backgroundField("type"),
                backgroundColor: backgroundField("color"),
                gradientFrom: backgroundField("gradientFrom"),
                gradientTo: backgroundField("gradientTo"),
                gradientAngle: backgroundField("gradientAngle"),
                overlay: backgroundField("overlay"),
                backgroundMedia: writable(
                    () => ({
                        id: background().mediaId ?? null,
                        url: background().media?.url ?? null,
                    }),
                    (picked) => {
                        background().mediaId = picked?.id ?? null;
                        background().media = picked?.id
                            ? { url: picked.url ?? null }
                            : null;
                    },
                ),
                // Muted and looped behind the zone's content - independent
                // of the still picture above, which stays the fallback while
                // it loads or once autoplay is refused.
                backgroundVideo: writable(
                    () => ({
                        id: background().videoId ?? null,
                        url: background().video?.url ?? null,
                    }),
                    (picked) => {
                        background().videoId = picked?.id ?? null;
                        background().video = picked?.id
                            ? { url: picked.url ?? null }
                            : null;
                    },
                ),
                // Mirrors usePostBanner's own three - the panel shows one
                // editor for both, so it needs the same three questions
                // answered per zone rather than once for the whole post.
                isSolidFill: computed(() => "solid" === background().type),
                isGradientFill: computed(
                    () => "gradient" === background().type,
                ),
                fillPreviewStyle: computed(() => {
                    const {
                        type,
                        color,
                        gradientFrom,
                        gradientTo,
                        gradientAngle,
                    } = background();

                    if ("solid" === type && color)
                        return { backgroundColor: color };

                    if ("gradient" === type && gradientFrom && gradientTo) {
                        return {
                            backgroundImage: `linear-gradient(${gradientAngle}deg, ${gradientFrom}, ${gradientTo})`,
                        };
                    }

                    return null;
                }),
                contrast: shared("contrast"),
                reveal: shared("reveal"),
                sticky: shared("sticky"),
                fullBleed: shared("fullBleed"),
                // The width control drives the large-screen span only. Below
                // that a zone stays full width, which is what the stored
                // `base` says and what reads best on a phone.
                // Both are top-level only: inside a stack the axis of flow is
                // vertical, so there is no row to start or to sit at the end
                // of. The panel does not show them there, and the normaliser
                // would zero them anyway.
                offset: writable(
                    () => zone()?.offset ?? 0,
                    (value) => {
                        // Zero is exempt from the snap, which has the step as
                        // its floor: rounding it up would leave no way back to
                        // the flow, and "no gap" is the answer most zones want.
                        zone().offset = clampOffset(
                            0 === Number(value)
                                ? 0
                                : clampToSnap(value, snap.value),
                            zone(),
                        );
                    },
                ),
                newRow: writable(
                    () => zone()?.newRow ?? false,
                    (value) => {
                        zone().newRow = Boolean(value);
                    },
                ),
                width: writable(
                    () => zone()?.span?.lg ?? COLUMNS,
                    (value) => {
                        zone().span.lg = clampToSnap(value, snap.value);

                        // A wider zone leaves less room to its left. Without
                        // this a zone pushed to the right and then widened to
                        // the full row would keep an offset the row cannot
                        // hold - and the server, which clamps on the way in,
                        // would give back a layout the editor never showed.
                        if (null === childIndex) {
                            zone().offset = clampOffset(zone().offset, zone());
                        }

                        // Inside a stack the shares are relative to each other,
                        // so setting one without touching the rest gives an
                        // author who picked "2/3" something else - 24 and 32
                        // are 43% and 57%, not a third and two thirds. Giving
                        // the remainder back to the others is what makes the
                        // button mean what it says.
                        if (null !== childIndex) {
                            rebalance(index, childIndex);
                        }
                    },
                ),
                media: writable(
                    () => ({
                        id: zone()?.mediaId ?? null,
                        url: zone()?.media?.url ?? null,
                    }),
                    (picked) => {
                        zone().mediaId = picked?.id ?? null;
                        // Keep the url the picker handed back so the preview
                        // survives until the next save; the server re-resolves
                        // it from the id on the way out.
                        zone().media = picked?.id
                            ? { url: picked.url ?? null }
                            : null;
                    },
                ),
                // Per language - what fills it.
                blocks: localised("blocks"),
                alt: localised("alt"),
                caption: localised("caption"),
                url: localised("url"),
                label: localised("label"),
                code: localised("code"),
            });
        }

        return zoneFieldsCache.get(key);
    }

    /**
     * A width the author can actually reach with the current step. Changing the
     * step does not rewrite the zones already placed - a layout should not
     * shift because someone went looking for finer control - but every new
     * width lands on it.
     */
    function clampToSnap(value, step) {
        const columns = Math.round(Number(value) / step) * step;

        return Math.max(step, Math.min(COLUMNS, columns));
    }

    /**
     * The entries of an item list, and the four ways to change them.
     *
     * Split the same way the zone is: the arrangement - how many, in what
     * order, which picture - lives on the post, and the words live on the
     * open translation. Adding an entry in French therefore adds it in
     * English too, with nothing written in it yet, which is what a shared
     * arrangement means.
     */
    function zoneItems(index, childIndex = null) {
        const items = zoneAt(index, childIndex)?.items;

        // A zone that is not a list has no entries, and the server says so
        // with `null` rather than with an empty list, every type carrying the
        // one key its own type reads.
        return Array.isArray(items) ? items : [];
    }

    /** Tabs and item lists share the entry list; they do not share its cap. */
    function canAddItem(index, childIndex = null) {
        const cap =
            "tabs" === zoneAt(index, childIndex)?.type ? MAX_TABS : MAX_ITEMS;

        return zoneItems(index, childIndex).length < cap;
    }

    function addItem(index, childIndex = null) {
        if (!canAddItem(index, childIndex)) return;

        const zone = zoneAt(index, childIndex);
        const id = newZoneId();

        // A zone turned into a list arrived as something else, and something
        // else has no entries to push onto.
        if (!Array.isArray(zone.items)) {
            zone.items = [];
        }

        zone.items.push({ id, mediaId: null, media: null, featured: false });
        // Only this language's entry, for the reason usePostBanner gives: the
        // others gain theirs when the server normalises them against the
        // arrangement, and an empty string is what an untranslated entry means.
        heldFor(zone).items[id] = newItemText();
    }

    /**
     * The pictures of a gallery, kept as ids with their previews beside them.
     *
     * `mediaIds` is what is saved and `gallery.items` is what the panel draws,
     * the same split the single media field makes: the server re-resolves the
     * urls from the ids on the way out, and the preview only has to survive
     * until then.
     *
     * A picture already in the list is skipped rather than added twice - the
     * normaliser refuses a duplicate anyway, and letting the editor show one
     * that will not come back is how a panel starts lying.
     */
    function addGalleryImages(index, picked, childIndex = null) {
        const zone = zoneAt(index, childIndex);
        const chosen = Array.isArray(picked) ? picked : [picked];

        if (!Array.isArray(zone.mediaIds)) {
            zone.mediaIds = [];
        }

        if (!zone.gallery || !Array.isArray(zone.gallery.items)) {
            zone.gallery = { items: [] };
        }

        for (const item of chosen) {
            if (!item?.id || zone.mediaIds.includes(item.id)) continue;
            if (zone.mediaIds.length >= MAX_GALLERY_IMAGES) break;

            zone.mediaIds.push(item.id);
            zone.gallery.items.push({ url: item.url ?? null });
        }
    }

    /**
     * One side of a comparison, by position: 0 is before, 1 is after.
     *
     * Writing into a slot rather than pushing, because the two are not a list
     * an author appends to - replacing "after" must leave "before" where it
     * is, and a picture already used on the other side is refused for the
     * reason the gallery refuses a duplicate.
     */
    function setCompareImage(index, slot, picked, childIndex = null) {
        const zone = zoneAt(index, childIndex);

        if (!Array.isArray(zone.mediaIds)) {
            zone.mediaIds = [];
        }

        if (!zone.gallery || !Array.isArray(zone.gallery.items)) {
            zone.gallery = { items: [] };
        }

        const other = slot === 0 ? 1 : 0;
        if (zone.mediaIds[other] === picked.id) return;

        zone.mediaIds[slot] = picked.id;
        zone.gallery.items[slot] = { url: picked.url ?? null };
    }

    function removeGalleryImage(index, at, childIndex = null) {
        const zone = zoneAt(index, childIndex);

        zone.mediaIds?.splice(at, 1);
        zone.gallery?.items?.splice(at, 1);
    }

    /** The two lists move together, or the previews stop matching the ids. */
    function moveGalleryImage(index, at, direction, childIndex = null) {
        const zone = zoneAt(index, childIndex);
        const ids = zone.mediaIds ?? [];
        const target = at + direction;

        if (target < 0 || target >= ids.length) return;

        [ids[at], ids[target]] = [ids[target], ids[at]];

        const items = zone.gallery?.items;
        if (Array.isArray(items) && items.length === ids.length) {
            [items[at], items[target]] = [items[target], items[at]];
        }
    }

    function removeItem(index, itemIndex, childIndex = null) {
        const zone = zoneAt(index, childIndex);
        const [removed] = zone.items.splice(itemIndex, 1);

        if (removed) {
            delete heldFor(zone).items[removed.id];
        }
    }

    function moveItem(index, itemIndex, direction, childIndex = null) {
        const items = zoneAt(index, childIndex)?.items ?? [];
        const target = itemIndex + direction;

        if (target < 0 || target >= items.length) return;

        [items[itemIndex], items[target]] = [items[target], items[itemIndex]];
    }

    /**
     * One entry's fields. Cached like a zone's and for the same reason: the
     * template asks on every render, and a fresh set of computeds each time
     * would throw away their caching for nothing.
     */
    const itemFieldsCache = new Map();

    function itemFields(index, itemIndex, childIndex = null) {
        const key = `${index}:${childIndex}:${itemIndex}`;

        if (!itemFieldsCache.has(key)) {
            const item = () => zoneAt(index, childIndex)?.items?.[itemIndex];

            const words = () => {
                const zone = zoneAt(index, childIndex);
                const id = item()?.id;

                if (!zone || undefined === id) return {};

                const held = heldFor(zone);
                held.items[id] ??= newItemText();

                return held.items[id];
            };

            const localised = (name) =>
                writable(
                    () => words()[name] ?? "",
                    (value) => {
                        words()[name] = value;
                    },
                );

            itemFieldsCache.set(key, {
                title: localised("title"),
                // A panel's body. Only a tabs zone writes it, and the
                // normaliser keeps it for that type alone - an item list that
                // gained one would be carrying a key nothing reads.
                blocks: writable(
                    () => words().blocks ?? [],
                    (value) => {
                        words().blocks = value;
                    },
                ),
                description: localised("description"),
                caption: localised("caption"),
                url: localised("url"),
                // Shared, like the zone's own picture: which plan is
                // recommended is the same recommendation in every language.
                featured: writable(
                    () => item()?.featured ?? false,
                    (value) => {
                        const entry = item();
                        if (!entry) return;
                        entry.featured = Boolean(value);
                    },
                ),
                // Shared, like the zone's own picture: the same face or the
                // same logo in every language.
                media: writable(
                    () => item()?.media ?? null,
                    (value) => {
                        const entry = item();
                        if (!entry) return;
                        entry.media = value;
                        entry.mediaId = value?.id ?? null;
                    },
                ),
            });
        }

        return itemFieldsCache.get(key);
    }

    /** How wide a zone reads, as the fraction an author thinks in. */
    function widthLabel(index) {
        const columns = zoneFields(index).width.value;

        return t("backend.posts.grid.width_label", {
            columns,
            total: COLUMNS,
        });
    }

    return {
        COLUMNS,
        zones,
        canAddZone,
        enabled,
        snap,
        snapOptions,
        reveal,
        revealOptions,
        typeOptions,
        leafTypeOptions,
        widthOptions,
        offsetOptions,
        shareOptions,
        ratioOptions,
        scaleOptions,
        alignOptions,
        childrenOf,
        canAddChild,
        addChild,
        removeChild,
        moveChild,
        moveZoneIntoStack,
        moveZoneOutOfStack,
        childShare,
        addZone,
        addZoneAt,
        removeZone,
        moveZone,
        moveZoneTo,
        resizeZoneFromLeft,
        swapZones,
        zoneChoices,
        zoneFields,
        zoneItems,
        canAddItem,
        addItem,
        removeItem,
        moveItem,
        addGalleryImages,
        removeGalleryImage,
        moveGalleryImage,
        setCompareImage,
        itemFields,
        widthLabel,
    };
}
