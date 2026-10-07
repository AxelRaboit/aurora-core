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

const heading = (text, level = 2) => ({
    type: "header",
    data: { text, level },
});
const paragraph = (text) => ({ type: "paragraph", data: { text } });
const bulletList = (...items) => ({
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

const words = (t, name) => t(`suite.posts.grid.sections.words.${name}`);

export const SECTION_PATTERNS = [
    {
        key: "title",
        rows: [[48]],
        build(t) {
            const section = sheet();
            section.add(zone("text", FULL, { newRow: true }), {
                blocks: [
                    heading(words(t, "title")),
                    paragraph(words(t, "intro")),
                ],
            });

            return section.result();
        },
    },
    // A picture and its text side by side, the two ways round. Inserted one
    // after the other, they alternate; on a phone each row still reads
    // picture then text, which GridNormalizer::readingOrder takes care of.
    // The widths and columns are the ones the service pages settled on: 20
    // for the picture, 26 for the text, two columns of air between them.
    {
        key: "picture_text",
        rows: [[20, 26]],
        build(t) {
            const section = sheet();
            section.add(zone("media", 20, { newRow: true, offset: 0 }), {});
            section.add(zone("text", 26, { offset: 22 }), {
                blocks: [
                    heading(words(t, "heading"), 3),
                    paragraph(words(t, "text")),
                ],
            });

            return section.result();
        },
    },
    {
        key: "text_picture",
        rows: [[26, 20]],
        build(t) {
            const section = sheet();
            section.add(zone("text", 26, { newRow: true, offset: 0 }), {
                blocks: [
                    heading(words(t, "heading"), 3),
                    paragraph(words(t, "text")),
                ],
            });
            section.add(zone("media", 20, { offset: 28 }), {});

            return section.result();
        },
    },
    {
        key: "card_phone",
        rows: [[28, 20]],
        build(t) {
            const section = sheet();
            section.add(
                zone("text", 28, {
                    newRow: true,
                    surface: "raised",
                    options: { valign: "center" },
                }),
                {
                    blocks: [
                        heading(`${words(t, "heading")} 👀`, 3),
                        bulletList(
                            words(t, "point"),
                            words(t, "point"),
                            words(t, "point"),
                        ),
                    ],
                },
            );
            section.add(
                zone("media", 20, {
                    options: {
                        frame: "phone",
                        tilt: "right",
                        showCredit: false,
                    },
                }),
                {},
            );

            return section.result();
        },
    },
    {
        key: "two_cards",
        rows: [[20, 28]],
        build(t) {
            const section = sheet();
            section.add(zone("text", 20, { newRow: true, surface: "raised" }), {
                blocks: [
                    heading(words(t, "heading"), 3),
                    paragraph(words(t, "text")),
                    bulletList(words(t, "point"), words(t, "point")),
                ],
            });
            section.add(zone("text", 28, { surface: "raised" }), {
                blocks: [
                    heading(words(t, "heading"), 3),
                    paragraph(words(t, "text")),
                    bulletList(words(t, "point"), words(t, "point")),
                ],
            });

            return section.result();
        },
    },
    {
        key: "square",
        rows: [
            [24, 24],
            [24, 24],
        ],
        build(t) {
            const section = sheet();
            ["strengths", "weaknesses", "opportunities", "threats"].forEach(
                (name, index) => {
                    section.add(
                        zone("text", 24, {
                            newRow: 0 === index % 2,
                            surface: "raised",
                        }),
                        {
                            blocks: [
                                heading(words(t, name), 3),
                                paragraph(words(t, "text")),
                            ],
                        },
                    );
                },
            );

            return section.result();
        },
    },
    {
        key: "three_columns",
        rows: [[16, 16, 16]],
        build(t) {
            const section = sheet();
            [0, 1, 2].forEach((index) => {
                section.add(zone("text", 16, { newRow: 0 === index }), {
                    blocks: [
                        pill(words(t, "label")),
                        paragraph(words(t, "text")),
                    ],
                });
            });

            return section.result();
        },
    },
    {
        key: "three_tags",
        rows: [[16, 16, 16]],
        build(t) {
            const section = sheet();
            ["rose", "indigo", "lime"].forEach((tone, index) => {
                section.add(zone("text", 16, { newRow: 0 === index }), {
                    blocks: [
                        pill(`${words(t, "competitor")} 👀`, tone),
                        paragraph(
                            `<b>${words(t, "lead")}</b> : ${words(t, "point")}`,
                        ),
                        paragraph(
                            `<b>${words(t, "lead")}</b> : ${words(t, "point")}`,
                        ),
                        paragraph(
                            `<b>${words(t, "lead")}</b> : ${words(t, "point")}`,
                        ),
                    ],
                });
            });

            return section.result();
        },
    },
    {
        key: "keep_avoid",
        rows: [[24, 24]],
        build(t) {
            const section = sheet();
            section.add(zone("text", 24, { newRow: true, surface: "raised" }), {
                blocks: [
                    heading(words(t, "keep"), 3),
                    bulletList(
                        words(t, "point"),
                        words(t, "point"),
                        words(t, "point"),
                    ),
                ],
            });
            section.add(zone("text", 24, { surface: "raised" }), {
                blocks: [
                    heading(words(t, "avoid"), 3),
                    bulletList(
                        words(t, "point"),
                        words(t, "point"),
                        words(t, "point"),
                    ),
                ],
            });

            return section.result();
        },
    },
    {
        key: "showcase_figure",
        rows: [[32, 16]],
        build(t) {
            const section = sheet();
            const entry = () => ({
                title: words(t, "post"),
                caption: words(t, "post_when"),
                description: words(t, "post_figures"),
                url: "",
            });
            section.add(
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
            section.add(
                zone("text", 16, {
                    surface: "raised",
                    options: { valign: "center" },
                }),
                {
                    blocks: [
                        heading(`${words(t, "figure")} 🔥`, 3),
                        paragraph(`<b>${words(t, "figure_label")}</b>`),
                        paragraph(words(t, "text")),
                    ],
                },
            );

            return section.result();
        },
    },
    {
        key: "text_chart",
        rows: [[26, 22]],
        build(t) {
            const section = sheet();
            section.add(zone("text", 26, { newRow: true, surface: "raised" }), {
                blocks: [
                    heading(words(t, "heading"), 3),
                    paragraph(words(t, "text")),
                    heading(words(t, "heading"), 3),
                    paragraph(words(t, "text")),
                ],
            });
            section.add(
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

            return section.result();
        },
    },
    {
        key: "networks",
        rows: [[16, 32]],
        build(t) {
            const section = sheet();
            section.add(zone("text", 16, { newRow: true }), {
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
            section.add(zone("text", 32), {
                blocks: [pill(words(t, "label")), paragraph(words(t, "text"))],
            });

            return section.result();
        },
    },
];
