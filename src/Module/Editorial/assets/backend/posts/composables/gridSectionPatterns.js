/**
 * Sections ready to drop into a grid: the arrangements a report is made of,
 * each a handful of zones already laid out and filled with [blanks].
 *
 * The audit a coach builds in a slide tool is a dozen of these, over and
 * over: a title, a card beside a phone, four cards in a square, three
 * labelled columns. Composing each from empty zones is the forty clicks the
 * library saves. Every word is a blank between brackets, so the grid's
 * counter says what is left to write.
 *
 * Each pattern names its rows as widths (for the picture in the picker) and
 * builds `{zones, content}` in the shape `insertZones` takes - partial zones,
 * completed with every default on the way in.
 */

const FULL = 48;

let sequence = 0;

/** An id local to one build; `insertZones` gives the real ones. */
function key(prefix) {
    sequence += 1;

    return `${prefix}${sequence}`;
}

function zone(type, width, extra = {}) {
    return {
        id: key("z"),
        type,
        span: { base: FULL, md: width, lg: width },
        ...extra,
    };
}

const h = (text, level = 2) => ({ type: "header", data: { text, level } });
const p = (text) => ({ type: "paragraph", data: { text } });
const ul = (...items) => ({
    type: "list",
    data: {
        style: "unordered",
        meta: {},
        items: items.map((content) => ({ content, meta: {}, items: [] })),
    },
});
const pill = (text, tone = "dark", tilt = true) => ({
    type: "label",
    data: { text, tone, tilt },
});

/** Collects the zones of a pattern and what each holds. */
function sheet() {
    const zones = [];
    const content = {};

    return {
        add(layoutZone, held = {}) {
            zones.push(layoutZone);
            content[layoutZone.id] = held;

            return layoutZone;
        },
        result: () => ({ zones, content }),
    };
}

const words = (t, name) => t(`backend.posts.grid.sections.words.${name}`);

export const SECTION_PATTERNS = [
    {
        key: "title",
        rows: [[48]],
        build(t) {
            const s = sheet();
            s.add(zone("text", FULL, { newRow: true }), {
                blocks: [h(words(t, "title")), p(words(t, "intro"))],
            });

            return s.result();
        },
    },
    {
        key: "card_phone",
        rows: [[28, 20]],
        build(t) {
            const s = sheet();
            s.add(
                zone("text", 28, {
                    newRow: true,
                    surface: "raised",
                    options: { valign: "center" },
                }),
                {
                    blocks: [
                        h(`${words(t, "heading")} 👀`, 3),
                        ul(
                            words(t, "point"),
                            words(t, "point"),
                            words(t, "point"),
                        ),
                    ],
                },
            );
            s.add(
                zone("media", 20, {
                    options: {
                        frame: "phone",
                        tilt: "right",
                        showCredit: false,
                    },
                }),
                {},
            );

            return s.result();
        },
    },
    {
        key: "two_cards",
        rows: [[20, 28]],
        build(t) {
            const s = sheet();
            s.add(zone("text", 20, { newRow: true, surface: "raised" }), {
                blocks: [
                    h(words(t, "heading"), 3),
                    p(words(t, "text")),
                    ul(words(t, "point"), words(t, "point")),
                ],
            });
            s.add(zone("text", 28, { surface: "raised" }), {
                blocks: [
                    h(words(t, "heading"), 3),
                    p(words(t, "text")),
                    ul(words(t, "point"), words(t, "point")),
                ],
            });

            return s.result();
        },
    },
    {
        key: "square",
        rows: [
            [24, 24],
            [24, 24],
        ],
        build(t) {
            const s = sheet();
            ["strengths", "weaknesses", "opportunities", "threats"].forEach(
                (name, index) => {
                    s.add(
                        zone("text", 24, {
                            newRow: 0 === index % 2,
                            surface: "raised",
                        }),
                        { blocks: [h(words(t, name), 3), p(words(t, "text"))] },
                    );
                },
            );

            return s.result();
        },
    },
    {
        key: "three_columns",
        rows: [[16, 16, 16]],
        build(t) {
            const s = sheet();
            [0, 1, 2].forEach((index) => {
                s.add(zone("text", 16, { newRow: 0 === index }), {
                    blocks: [pill(words(t, "label")), p(words(t, "text"))],
                });
            });

            return s.result();
        },
    },
    {
        key: "three_tags",
        rows: [[16, 16, 16]],
        build(t) {
            const s = sheet();
            ["rose", "indigo", "lime"].forEach((tone, index) => {
                s.add(zone("text", 16, { newRow: 0 === index }), {
                    blocks: [
                        pill(`${words(t, "competitor")} 👀`, tone),
                        p(`<b>${words(t, "lead")}</b> : ${words(t, "point")}`),
                        p(`<b>${words(t, "lead")}</b> : ${words(t, "point")}`),
                        p(`<b>${words(t, "lead")}</b> : ${words(t, "point")}`),
                    ],
                });
            });

            return s.result();
        },
    },
    {
        key: "keep_avoid",
        rows: [[24, 24]],
        build(t) {
            const s = sheet();
            s.add(zone("text", 24, { newRow: true, surface: "raised" }), {
                blocks: [
                    h(words(t, "keep"), 3),
                    ul(words(t, "point"), words(t, "point"), words(t, "point")),
                ],
            });
            s.add(zone("text", 24, { surface: "raised" }), {
                blocks: [
                    h(words(t, "avoid"), 3),
                    ul(words(t, "point"), words(t, "point"), words(t, "point")),
                ],
            });

            return s.result();
        },
    },
    {
        key: "showcase_figure",
        rows: [[32, 16]],
        build(t) {
            const s = sheet();
            const entry = () => ({
                title: words(t, "post"),
                caption: words(t, "post_when"),
                description: words(t, "post_figures"),
                url: "",
            });
            s.add(
                zone("items", 32, {
                    newRow: true,
                    display: "showcase",
                    columns: 3,
                    items: [
                        { id: "e1", mediaId: null, featured: false },
                        { id: "e2", mediaId: null, featured: false },
                        { id: "e3", mediaId: null, featured: false },
                    ],
                }),
                { items: { e1: entry(), e2: entry(), e3: entry() } },
            );
            s.add(
                zone("text", 16, {
                    surface: "raised",
                    options: { valign: "center" },
                }),
                {
                    blocks: [
                        h(`${words(t, "figure")} 🔥`, 3),
                        p(`<b>${words(t, "figure_label")}</b>`),
                        p(words(t, "text")),
                    ],
                },
            );

            return s.result();
        },
    },
    {
        key: "text_chart",
        rows: [[26, 22]],
        build(t) {
            const s = sheet();
            s.add(zone("text", 26, { newRow: true, surface: "raised" }), {
                blocks: [
                    h(words(t, "heading"), 3),
                    p(words(t, "text")),
                    h(words(t, "heading"), 3),
                    p(words(t, "text")),
                ],
            });
            s.add(
                zone("chart", 22, {
                    options: {
                        chartType: "pie",
                        chartUnit: "%",
                        valign: "center",
                    },
                }),
                {
                    label: words(t, "chart"),
                    code: `${words(t, "slice")} 1 ; [60] ; #2f1bea\n${words(t, "slice")} 2 ; [25] ; #111111\n${words(t, "slice")} 3 ; [15] ; #e4f76b`,
                },
            );

            return s.result();
        },
    },
    {
        key: "networks",
        rows: [[16, 32]],
        build(t) {
            const s = sheet();
            s.add(zone("text", 16, { newRow: true }), {
                blocks: [
                    pill(`${words(t, "networks")} 👀`),
                    {
                        type: "socials",
                        data: {
                            items: [
                                {
                                    network: "instagram",
                                    handle: words(t, "handle"),
                                    url: "",
                                },
                                {
                                    network: "facebook",
                                    handle: words(t, "page"),
                                    url: "",
                                },
                                {
                                    network: "linkedin",
                                    handle: words(t, "page"),
                                    url: "",
                                },
                            ],
                        },
                    },
                ],
            });
            s.add(zone("text", 32), {
                blocks: [pill(words(t, "label")), p(words(t, "text"))],
            });

            return s.result();
        },
    },
];
