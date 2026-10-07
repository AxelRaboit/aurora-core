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

/**
 * Where the tour's pictures live on the server: its own disk by default.
 *
 * Decided on 07/10/2026: the screenshots of the public tour are kept on the
 * server rather than on the object storage. An import or a replacement writes
 * through the site's active disk, whatever it is, so each script brings what
 * it has just sent back here with `aurora:ged:relocate`. `TOUR_DISK=r2` keeps
 * them on the object storage instead.
 */
export function tourDisk() {
    const disk = process.env.TOUR_DISK ?? "local";

    if (!["local", "r2"].includes(disk)) {
        console.error(`TOUR_DISK doit valoir « local » ou « r2 », reçu « ${disk} ».`);
        process.exit(1);
    }

    return disk;
}

/**
 * The console command that moves the given documents to the tour's disk, run
 * as the web server's user so the files it writes are the site's to read.
 */
export function relocateCommand(dir, ids) {
    const options = ids.map((id) => `--id=${Number(id)}`).join(" ");

    return `cd ${dir} && sudo -u www-data php bin/console aurora:ged:relocate ${tourDisk()} --env=prod ${options}`;
}
