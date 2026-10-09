/**
 * A note cut into slides (09/10/2026), the way Obsidian's Slides and iA
 * Writer read one: a line `---` ends a slide. A note without any is cut at
 * its headings, `#` and `##`, each one starting a slide - so any note can be
 * shown, and one written for it is shown as written.
 *
 * Lines inside a code block never cut: `---` there is code.
 *
 * @returns {string[]} the slides' markdown, empty ones left out
 */
export function splitSlides(markdown) {
    const lines = String(markdown ?? "")
        .replace(/\r\n/g, "\n")
        .split("\n");
    const hasRules = scan(lines).some(
        ({ line, inCode }) => !inCode && /^-{3,}\s*$/.test(line),
    );

    const slides = [[]];
    for (const { line, inCode } of scan(lines)) {
        if (!inCode && hasRules && /^-{3,}\s*$/.test(line)) {
            slides.push([]);
            continue;
        }
        if (
            !inCode &&
            !hasRules &&
            /^#{1,2}\s/.test(line) &&
            slides.at(-1).some((one) => "" !== one.trim())
        ) {
            slides.push([]);
        }
        slides.at(-1).push(line);
    }

    return slides
        .map((slide) => slide.join("\n").trim())
        .filter((slide) => "" !== slide);
}

/** Each line with whether it sits in a fenced code block. */
function scan(lines) {
    let fence = null;

    return lines.map((line) => {
        const opening = /^(`{3,}|~{3,})/.exec(line);
        const inCode = null !== fence;
        if (opening) {
            if (null === fence) fence = opening[1][0];
            else if (opening[1][0] === fence) fence = null;

            return { line, inCode: true };
        }

        return { line, inCode };
    });
}
