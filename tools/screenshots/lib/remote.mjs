/**
 * Where the tour scripts write, and how they name a tour page.
 *
 * **No default server.** This repository is public: a default host or
 * deployment path written here would describe one real machine to anyone who
 * reads it. The server comes from the environment, set by whoever runs the
 * scripts, and a script refuses to start without it rather than guess.
 *
 *   TOUR_SSH_HOST=<ssh alias> TOUR_REMOTE_DIR=<project directory> node tools/screenshots/push-tour.mjs
 */

export function remote() {
    const host = process.env.TOUR_SSH_HOST ?? "";
    const dir = process.env.TOUR_REMOTE_DIR ?? "";

    if ("" === host || "" === dir) {
        console.error("TOUR_SSH_HOST et TOUR_REMOTE_DIR doivent nommer le serveur et le dossier du projet.");
        process.exit(1);
    }

    return { host, dir };
}

/**
 * The id of a tour page, by its French slug, as SQL.
 *
 * Restricted to the `aurora` post type: slugs are not unique across types
 * (two publications share one on a real site), and `LIMIT 1` on its own
 * would rewrite whichever came first. The slug is checked before it reaches
 * the query, so a typo cannot become SQL.
 */
export function tourPostIdQuery(slug) {
    if (!/^[a-z0-9-]+$/.test(slug)) {
        console.error(`Slug refusé : ${slug}`);
        process.exit(1);
    }

    return (
        "SELECT t.post_id FROM core_post_translations t " +
        "JOIN core_posts p ON p.id = t.post_id " +
        "JOIN core_post_types pt ON pt.id = p.post_type_id " +
        `WHERE pt.slug = 'aurora' AND t.locale = 'fr' AND t.slug = '${slug}';`
    );
}
