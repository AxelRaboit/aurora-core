export function parseJsonLd(raw) {
    const trimmed = (raw ?? "").trim();
    if (trimmed === "") return { value: null, error: null, empty: true };
    try {
        const parsed = JSON.parse(trimmed);
        if (
            typeof parsed !== "object" ||
            parsed === null ||
            Array.isArray(parsed)
        ) {
            return { value: null, error: "not-object", empty: false };
        }
        return { value: parsed, error: null, empty: false };
    } catch (error) {
        return { value: null, error: error.message, empty: false };
    }
}

export function buildArticleJsonLd({
    title,
    description,
    imageUrl,
    datePublished,
} = {}) {
    const template = {
        "@context": "https://schema.org",
        "@type": "Article",
        headline: title || "",
        description: description || "",
        image: imageUrl ? [imageUrl] : undefined,
        datePublished: datePublished ?? undefined,
    };
    for (const key of Object.keys(template)) {
        if (template[key] === undefined) delete template[key];
    }
    return template;
}
