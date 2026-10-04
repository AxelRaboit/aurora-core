/**
 * What a box of the canvas says about what it holds.
 *
 * A grid of forty zones drawn as forty « Texte 48/48 » is a grid nobody can
 * find their way in: the author knows their document by its titles, not by
 * the type of each box. So each box reads the words it already holds - the
 * first heading, else the first line - and the settings that change how it
 * looks, as small pills. Words only, read from the editor's state: no second
 * renderer beside the Twig one, which stays the authority on the picture.
 *
 * Pure, so it is tested without a component, and given the translator rather
 * than reaching for one.
 */

const MAX_TITLE = 70;

/** Inline markup out, entities decoded, spaces folded. */
export function plainText(html) {
    const text = String(html ?? "")
        .replace(/<br\s*\/?>/gi, " ")
        .replace(/<[^>]*>/g, "")
        .replace(/&nbsp;/g, " ")
        .replace(/&amp;/g, "&")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/\s+/g, " ")
        .trim();

    return text.length > MAX_TITLE
        ? `${text.slice(0, MAX_TITLE - 1).trimEnd()}…`
        : text;
}

/** The words a block puts first, or "". */
function blockText(block) {
    const data = block?.data ?? {};

    switch (block?.type) {
        case "header":
        case "paragraph":
        case "label":
            return plainText(data.text);
        case "callout":
            return plainText(data.title || data.message);
        case "quote":
            return plainText(data.text);
        case "list": {
            const first = (data.items ?? [])[0];

            return plainText(
                "string" === typeof first ? first : first?.content,
            );
        }
        case "socials":
            return plainText(
                (data.items ?? [])
                    .map((item) => item?.handle)
                    .filter(Boolean)
                    .join(" · "),
            );
        default:
            return "";
    }
}

/**
 * A text zone is known by its heading: the first one of any level, and only
 * failing that its first line of words.
 */
function textTitle(blocks) {
    const list = Array.isArray(blocks) ? blocks : [];
    const heading = list.find(
        (block) => "header" === block?.type && plainText(block.data?.text),
    );
    if (heading) return plainText(heading.data.text);

    for (const block of list) {
        const text = blockText(block);
        if (text) return text;
    }

    return "";
}

function itemEntries(held) {
    const items = held?.items;
    if (!items || "object" !== typeof items) return [];

    return Object.values(items).filter(
        (entry) => entry && (entry.title || entry.description),
    );
}

/**
 * `{ title, detail }` for a zone: the words to show, and one quieter line
 * under them. Either may be "" - a box with nothing written yet falls back to
 * its type, which the canvas draws anyway.
 */
export function zoneSummary(zone, held, t) {
    const options = zone?.options ?? {};

    switch (zone?.type) {
        case "text":
            return { title: textTitle(held?.blocks), detail: "" };
        case "items": {
            const entries = itemEntries(held);

            return {
                title: plainText(entries[0]?.title ?? ""),
                detail: entries.length
                    ? t("backend.posts.grid.tile_entries", {
                          count: entries.length,
                      })
                    : "",
            };
        }
        case "chart":
            return {
                title: plainText(held?.label),
                detail: options.chartType
                    ? t(`backend.posts.grid.chart_types.${options.chartType}`)
                    : "",
            };
        case "media":
            return { title: plainText(held?.caption || held?.alt), detail: "" };
        case "button":
            return { title: plainText(held?.label), detail: "" };
        case "contactCard":
            return {
                title: plainText(options.contactName),
                detail: plainText(held?.caption),
            };
        case "tabs":
        case "code":
            return { title: plainText(held?.label), detail: "" };
        default:
            return { title: "", detail: "" };
    }
}

/**
 * The settings that change how a zone looks, as short words: what it sits
 * on, the device around a picture, a tilt, a centred place in its row, a
 * screen it hides from. Only what differs from a plain zone - a pill for
 * every default would bury the few that say something.
 */
export function zoneBadges(zone, t) {
    const options = zone?.options ?? {};
    const badges = [];

    if (zone?.surface && "none" !== zone.surface)
        badges.push(t(`backend.posts.grid.surfaces.${zone.surface}`));
    if (zone?.fullBleed) badges.push(t("backend.posts.grid.tile_full_bleed"));
    if ("media" === zone?.type && options.frame && "none" !== options.frame)
        badges.push(t(`backend.posts.grid.frames.${options.frame}`));
    if (options.tilt && "none" !== options.tilt)
        badges.push(t("backend.posts.grid.tilt"));
    if (options.valign && "stretch" !== options.valign)
        badges.push(t(`backend.posts.grid.valigns.${options.valign}`));
    if (options.hideOn && "none" !== options.hideOn)
        badges.push(t(`backend.posts.grid.hide_on_badges.${options.hideOn}`));

    return badges;
}
