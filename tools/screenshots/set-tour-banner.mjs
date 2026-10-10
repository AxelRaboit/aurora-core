/**
 * Gives a tour page the same banner as the hub page, with the module's
 * capture on the right.
 *
 * The hub page opens on a gradient, a title and a button. The twenty-four
 * pages it lists opened on nothing: their banner existed but was switched
 * off, and the page started with its title in small type, followed by an
 * image. The module they describe only showed up after scrolling.
 *
 * So this script sets, on a page named by its slug:
 *
 * - the same gradient background as the hub, identical, so the family shows;
 * - on the left, the title and summary the post already carries - taken from
 *   its translation, never rewritten here: two places that say the same
 *   thing end up no longer saying it the same way;
 * - on the right, an image, the one the page showed first.
 *
 * **The image leaves the body.** Otherwise it would be on the page twice, once
 * in the banner and once below. The template already knows not to repeat the
 * title when the banner carries one; for the image, the choice is ours.
 *
 * Usage:
 *   node tools/screenshots/set-tour-banner.mjs <slug> [--dry-run]
 *
 * The image picked is the first media zone of the grid. `--media <id>`
 * forces another document, and `--keep-in-body` leaves it below as well.
 */

import { execFile } from "node:child_process";
import { writeFile } from "node:fs/promises";
import { randomBytes } from "node:crypto";
import { tmpdir } from "node:os";
import { resolve } from "node:path";
import { promisify } from "node:util";
import { remote, tourPostIdQuery } from "./lib/remote.mjs";

const run = promisify(execFile);

const { host: HOST, dir: REMOTE_DIR } = remote();
const REMOTE_TMP = "/tmp/aurora-tour";
const LOCALES = ["fr", "en", "es"];

const args = process.argv.slice(2);
const dryRun = args.includes("--dry-run");
const keepInBody = args.includes("--keep-in-body");
const forced = args.includes("--media") ? Number(args[args.indexOf("--media") + 1]) : null;
const slug = args.find((a) => !a.startsWith("--") && a !== String(forced));

if (undefined === slug) {
    console.error("Usage : node tools/screenshots/set-tour-banner.mjs <slug> [--media <id>] [--keep-in-body] [--dry-run]");
    process.exit(1);
}

async function sql(query) {
    const local = resolve(tmpdir(), `aurora-q-${randomBytes(6).toString("hex")}.sql`);
    await writeFile(local, query);
    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/q.sql' < ${JSON.stringify(local)}`]);
    const { stdout } = await run("ssh", [
        HOST,
        `cd ${REMOTE_DIR} && db=$(grep -oP 'DATABASE_URL=.*/\\K[^?"]+' .env.local | head -1); ` +
        `sudo -u postgres psql -At -d $db -f ${REMOTE_TMP}/q.sql`,
    ]);

    return stdout.trim();
}

await run("ssh", [HOST, `mkdir -p ${REMOTE_TMP}`]);

const postId = await sql(tourPostIdQuery(slug));

if ("" === postId) {
    console.error(`Publication introuvable : ${slug}`);
    process.exit(1);
}

const grid = JSON.parse(await sql(`SELECT grid_layout::text FROM core_posts WHERE id = ${postId};`));
const premiere = grid.zones.find((z) => "media" === z.type && null !== z.mediaId);
const media = forced ?? premiere?.mediaId ?? null;

if (null === media) {
    console.error(`Aucune image à mettre dans le bandeau de ${slug}.`);
    process.exit(1);
}

/** The title and summary the page already carries, locale by locale. */
const textes = {};
for (const locale of LOCALES) {
    const ligne = await sql(
        `SELECT json_build_object('title', title, 'description', coalesce(description, ''))::text ` +
        `FROM core_post_translations WHERE post_id = ${postId} AND locale = '${locale}';`,
    );
    textes[locale] = JSON.parse(ligne);
}

const idTexte = randomBytes(12).toString("hex");
const idImage = randomBytes(12).toString("hex");

const gabaritItem = {
    align: "start",
    mediaId: null,
    titleSize: "xl",
    titleColor: null,
    buttonColor: null,
    buttonTextColor: null,
    descriptionColor: null,
};

const bannerLayout = {
    enabled: true,
    height: "lg",
    width: "full_aligned",
    verticalAlign: "center",
    logoMediaId: null,
    fadeOut: true,
    // The hub's gradient, copied as is: it is what makes the family
    // recognisable when arriving on one page from the other. Night violet
    // since 10/10/2026, Aurora's colour; it was the green of the web
    // development offer before.
    background: {
        type: "gradient",
        color: null,
        gradientFrom: "#2a2050",
        gradientTo: "#130918",
        gradientAngle: 160,
        mediaId: null,
        overlay: 0,
    },
    items: [
        // `md` is the tablet width, from 640px on a banner: without it the text
        // and the capture stack there, and the capture falls below the fold.
        // 26/22 rather than 24/24 so « référencement, » fits its column.
        { ...gabaritItem, id: idTexte, type: "text", span: { base: 48, md: 26, lg: 22 } },
        { ...gabaritItem, id: idImage, type: "image", span: { base: 48, md: 22, lg: 26 }, titleSize: "md", mediaId: media },
    ],
};

const banners = {};
for (const locale of LOCALES) {
    banners[locale] = {
        items: {
            [idTexte]: { alt: "", url: null, label: "", title: textes[locale].title, description: textes[locale].description },
            [idImage]: { alt: textes[locale].title, url: null, label: "", title: "", description: "" },
        },
    };
}

if (!keepInBody && undefined !== premiere && premiere.mediaId === media) {
    grid.zones = grid.zones.filter((z) => z !== premiere);
}

console.log(`\n${slug} (publication ${postId}) — image #${media} dans le bandeau${dryRun ? " — essai à blanc" : ""}`);
console.log(`  titre : ${textes.fr.title}`);
console.log(`  résumé : ${textes.fr.description.slice(0, 90)}`);
console.log(`  corps : ${grid.zones.length} zone(s)${keepInBody ? "" : ", l'image retirée d'en dessous"}`);

if (dryRun) process.exit(0);

const fichiers = { blayout: JSON.stringify(bannerLayout), glayout: JSON.stringify(grid) };
for (const l of LOCALES) fichiers[`b${l}`] = JSON.stringify(banners[l]);

for (const [nom, contenu] of Object.entries(fichiers)) {
    const local = resolve(tmpdir(), `aurora-${nom}-${postId}.json`);
    await writeFile(local, contenu);
    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/${nom}-${postId}.json' < ${JSON.stringify(local)}`]);
}

const envoi = [
    ...Object.keys(fichiers).map((nom) => `\\set ${nom} \`cat ${REMOTE_TMP}/${nom}-${postId}.json\``),
    "BEGIN;",
    `UPDATE core_posts SET banner_layout = :'blayout', grid_layout = :'glayout' WHERE id = ${postId};`,
    ...LOCALES.map((l) => `UPDATE core_post_translations SET banner = :'b${l}' WHERE post_id = ${postId} AND locale = '${l}';`),
    "COMMIT;",
].join("\n");

const script = resolve(tmpdir(), `aurora-banner-${postId}.sql`);
await writeFile(script, envoi);
await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/banner-${postId}.sql' < ${JSON.stringify(script)}`]);
await run("ssh", [
    HOST,
    `cd ${REMOTE_DIR} && db=$(grep -oP 'DATABASE_URL=.*/\\K[^?"]+' .env.local | head -1); ` +
    `sudo -u postgres psql -v ON_ERROR_STOP=1 -d $db -f ${REMOTE_TMP}/banner-${postId}.sql`,
]);
await run("ssh", [HOST, `cd ${REMOTE_DIR} && sudo -u www-data php bin/console cache:pool:clear cache.app --env=prod`]);

console.log("✅ bandeau posé, cache vidé.");
