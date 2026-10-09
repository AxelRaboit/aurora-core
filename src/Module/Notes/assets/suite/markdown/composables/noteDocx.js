import { Lexer } from "marked";
import { withoutLeadingTitle } from "./noteBody.js";

/**
 * A note as a Word document (09/10/2026), for whoever works in Word or must
 * send one: headings, paragraphs, bold and italic, lists, tasks, quotes,
 * code, tables and links come through as Word's own styles, so the file is
 * edited like any other.
 *
 * Read from marked's tokens, the renderer's own reading of the text. What
 * Word has no equivalent for - a diagram, a formula, an included note - goes
 * in as its source, in the code style: nothing written is lost.
 *
 * The `docx` library is loaded on the click: a hundred kilobytes nobody else
 * needs.
 */

const MENTION = /@\[([^\]\n]{1,80})\]\(user:\d{1,10}\)/g;
const WIKI = /!?\[\[([^\]|#]*)(?:#([^\]|]*))?(?:\|([^\]]*))?\]\]/g;
const MARKS = /==(?:\{[a-z]+\})?(.+?)==/g;
const COLORED = /\{[a-z]+\}(.+?)\{\/\}/g;

/** The note's own syntax, as words Word can show. */
export function plainSyntax(text) {
    return String(text ?? "")
        .replace(MENTION, "@$1")
        .replace(
            WIKI,
            (_, title, heading, alias) =>
                alias || [title, heading].filter(Boolean).join(" > "),
        )
        .replace(MARKS, "$1")
        .replace(COLORED, "$1")
        .replace(/ \^[A-Za-z0-9-]+$/gm, "");
}

/**
 * The document's blocks, as plain data: what to write, before any Word
 * object. Kept apart so it can be read in a test without the library.
 *
 * @returns {Array<object>}
 */
export function docxBlocks(markdown) {
    const tokens = new Lexer({ gfm: true }).lex(plainSyntax(markdown));
    const blocks = [];

    function inline(tokenList, style = {}) {
        const runs = [];
        for (const token of tokenList ?? []) {
            if ("strong" === token.type)
                runs.push(...inline(token.tokens, { ...style, bold: true }));
            else if ("em" === token.type)
                runs.push(...inline(token.tokens, { ...style, italics: true }));
            else if ("del" === token.type)
                runs.push(...inline(token.tokens, { ...style, strike: true }));
            else if ("codespan" === token.type)
                runs.push({ ...style, text: decode(token.text), code: true });
            else if ("link" === token.type)
                runs.push({
                    ...style,
                    text: plainText(token.tokens) || token.href,
                    link: token.href,
                });
            else if ("image" === token.type)
                runs.push({
                    ...style,
                    text: `[${token.text || token.href}]`,
                    italics: true,
                });
            else if ("br" === token.type)
                runs.push({ ...style, text: "", lineBreak: true });
            else if (token.tokens) runs.push(...inline(token.tokens, style));
            else
                runs.push({
                    ...style,
                    text: decode(token.text ?? token.raw ?? ""),
                });
        }

        return runs;
    }

    function addList(token, level) {
        for (const item of token.items) {
            // marked 18 puts the box itself first, as a `checkbox` token: the prefix says it.
            const [first, ...rest] = (item.tokens ?? []).filter(
                (child) => "checkbox" !== child.type,
            );
            const prefix = item.task ? (item.checked ? "☑ " : "☐ ") : "";
            const runs =
                first && ("text" === first.type || "paragraph" === first.type)
                    ? inline(
                          first.tokens ?? [{ type: "text", text: first.text }],
                      )
                    : [];
            if (prefix) runs.unshift({ text: prefix });
            blocks.push({ type: "item", ordered: token.ordered, level, runs });
            for (const child of rest) {
                if ("list" === child.type) addList(child, level + 1);
                else walk([child]);
            }
        }
    }

    function walk(blockTokens) {
        for (const token of blockTokens) {
            switch (token.type) {
                case "heading":
                    blocks.push({
                        type: "heading",
                        depth: token.depth,
                        runs: inline(token.tokens),
                    });
                    break;
                case "paragraph":
                case "text":
                    blocks.push({
                        type: "paragraph",
                        runs: inline(
                            token.tokens ?? [
                                { type: "text", text: token.text },
                            ],
                        ),
                    });
                    break;
                case "list":
                    addList(token, 0);
                    break;
                case "blockquote":
                    for (const child of token.tokens ?? []) {
                        if ("paragraph" === child.type)
                            blocks.push({
                                type: "quote",
                                runs: inline(child.tokens),
                            });
                        else walk([child]);
                    }
                    break;
                case "code":
                    blocks.push({ type: "code", text: token.text });
                    break;
                case "table":
                    blocks.push({
                        type: "table",
                        header: token.header.map((cell) => inline(cell.tokens)),
                        rows: token.rows.map((row) =>
                            row.map((cell) => inline(cell.tokens)),
                        ),
                    });
                    break;
                case "hr":
                    blocks.push({ type: "rule" });
                    break;
                case "html":
                    if (token.text.trim())
                        blocks.push({
                            type: "paragraph",
                            runs: [
                                {
                                    text: token.text
                                        .replace(/<[^>]+>/g, "")
                                        .trim(),
                                },
                            ],
                        });
                    break;
                default:
                    break;
            }
        }
    }

    walk(tokens);

    return blocks;
}

function plainText(tokens) {
    return (tokens ?? [])
        .map((token) =>
            token.tokens ? plainText(token.tokens) : decode(token.text ?? ""),
        )
        .join("");
}

function decode(text) {
    return String(text)
        .replace(/&amp;/g, "&")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'");
}

/**
 * The `.docx` file of a note.
 *
 * @returns {Promise<Blob>}
 */
export async function noteDocx({ title, content }) {
    const docx = await import("docx");
    const {
        Document,
        Packer,
        Paragraph,
        TextRun,
        HeadingLevel,
        ExternalHyperlink,
        Table,
        TableRow,
        TableCell,
        WidthType,
        BorderStyle,
        LevelFormat,
        AlignmentType,
    } = docx;

    const HEADINGS = [
        HeadingLevel.HEADING_1,
        HeadingLevel.HEADING_2,
        HeadingLevel.HEADING_3,
        HeadingLevel.HEADING_4,
        HeadingLevel.HEADING_5,
        HeadingLevel.HEADING_6,
    ];

    function runs(runList) {
        return runList.map((run) => {
            const text = new TextRun({
                text: run.text,
                bold: run.bold,
                italics: run.italics,
                strike: run.strike,
                break: run.lineBreak ? 1 : undefined,
                font: run.code ? "Consolas" : undefined,
                style: run.link ? "Hyperlink" : undefined,
            });

            return run.link
                ? new ExternalHyperlink({ link: run.link, children: [text] })
                : text;
        });
    }

    const children = [
        new Paragraph({
            heading: HeadingLevel.TITLE,
            children: [new TextRun(String(title ?? "").trim())],
        }),
    ];
    // The title is the document's: the note's own `# Title` would say it twice.
    for (const block of docxBlocks(withoutLeadingTitle(content, title))) {
        if ("heading" === block.type)
            children.push(
                new Paragraph({
                    heading: HEADINGS[block.depth - 1],
                    children: runs(block.runs),
                }),
            );
        else if ("paragraph" === block.type)
            children.push(new Paragraph({ children: runs(block.runs) }));
        else if ("quote" === block.type)
            children.push(
                new Paragraph({
                    style: "Quote",
                    indent: { left: 567 },
                    children: runs(block.runs),
                }),
            );
        else if ("item" === block.type) {
            children.push(
                new Paragraph({
                    children: runs(block.runs),
                    ...(block.ordered
                        ? {
                              numbering: {
                                  reference: "note-numbers",
                                  level: Math.min(block.level, 8),
                              },
                          }
                        : { bullet: { level: Math.min(block.level, 8) } }),
                }),
            );
        } else if ("code" === block.type) {
            for (const line of String(block.text).split("\n")) {
                children.push(
                    new Paragraph({
                        shading: { fill: "F2F2F2" },
                        children: [
                            new TextRun({
                                text: line,
                                font: "Consolas",
                                size: 20,
                            }),
                        ],
                    }),
                );
            }
        } else if ("rule" === block.type) {
            children.push(
                new Paragraph({
                    border: {
                        bottom: {
                            style: BorderStyle.SINGLE,
                            size: 6,
                            color: "BBBBBB",
                            space: 1,
                        },
                    },
                    children: [],
                }),
            );
        } else if ("table" === block.type) {
            const cell = (cellRuns, header) =>
                new TableCell({
                    children: [
                        new Paragraph({
                            children: runs(
                                cellRuns.map((run) => ({
                                    ...run,
                                    bold: header || run.bold,
                                })),
                            ),
                        }),
                    ],
                });
            children.push(
                new Table({
                    width: { size: 100, type: WidthType.PERCENTAGE },
                    rows: [
                        new TableRow({
                            tableHeader: true,
                            children: block.header.map((cellRuns) =>
                                cell(cellRuns, true),
                            ),
                        }),
                        ...block.rows.map(
                            (row) =>
                                new TableRow({
                                    children: row.map((cellRuns) =>
                                        cell(cellRuns, false),
                                    ),
                                }),
                        ),
                    ],
                }),
            );
            children.push(new Paragraph({ children: [] }));
        }
    }

    const wordDocument = new Document({
        creator: "Aurora",
        title: String(title ?? ""),
        numbering: {
            config: [
                {
                    reference: "note-numbers",
                    levels: Array.from({ length: 9 }, (_, level) => ({
                        level,
                        format: LevelFormat.DECIMAL,
                        text: `%${level + 1}.`,
                        alignment: AlignmentType.START,
                        style: {
                            paragraph: {
                                indent: {
                                    left: 720 * (level + 1),
                                    hanging: 360,
                                },
                            },
                        },
                    })),
                },
            ],
        },
        sections: [{ children }],
    });

    return Packer.toBlob(wordDocument);
}
