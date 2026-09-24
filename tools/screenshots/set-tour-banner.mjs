/**
 * Donne à une page du tour le même bandeau que la page sommaire, avec la
 * capture du module à droite.
 *
 * La page sommaire ouvre sur un dégradé, un titre et un bouton. Les
 * vingt-quatre pages qu'elle liste ouvraient sur rien : leur bandeau existait
 * mais était éteint, et la page commençait par son titre en petit, suivi
 * d'une image. Le module qu'elles décrivent n'apparaissait qu'en défilant.
 *
 * Ce script pose donc, sur une page nommée par son slug :
 *
 * - le même fond dégradé que le sommaire, à l'identique, pour que la famille
 *   se voie ;
 * - à gauche, le titre et le résumé que la publication porte déjà - repris de
 *   sa traduction, jamais réécrits ici : deux endroits qui disent la même
 *   chose finissent par ne plus la dire pareil ;
 * - à droite, une image, celle que la page montrait en premier.
 *
 * **L'image quitte le corps.** Sans ça elle serait deux fois sur la page, une
 * fois dans le bandeau et une fois dessous. Le gabarit sait déjà ne pas
 * répéter le titre quand le bandeau en porte un ; pour l'image, c'est à nous
 * de choisir.
 *
 * Usage :
 *   node tools/screenshots/set-tour-banner.mjs <slug> [--dry-run]
 *
 * L'image retenue est la première zone média de la grille. `--media <id>`
 * force un autre document, et `--keep-in-body` la laisse aussi en dessous.
 */

import { execFile } from "node:child_process";
import { writeFile } from "node:fs/promises";
import { randomBytes } from "node:crypto";
import { tmpdir } from "node:os";
import { resolve } from "node:path";
import { promisify } from "node:util";

const run = promisify(execFile);

const HOST = process.env.TOUR_SSH_HOST ?? "vps";
const REMOTE_DIR = process.env.TOUR_REMOTE_DIR ?? "/var/www/aurora-client";
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

const postId = await sql(`SELECT post_id FROM core_post_translations WHERE slug = '${slug}' LIMIT 1;`);

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

/** Le titre et le résumé que la page porte déjà, langue par langue. */
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
    // Le dégradé du sommaire, copié tel quel : c'est ce qui fait qu'on
    // reconnaît la famille en arrivant sur une page depuis l'autre.
    background: {
        type: "gradient",
        color: null,
        gradientFrom: "#064e3b",
        gradientTo: "#030712",
        gradientAngle: 160,
        mediaId: null,
        overlay: 0,
    },
    items: [
        { ...gabaritItem, id: idTexte, type: "text", span: { base: 48, md: null, lg: 22 } },
        { ...gabaritItem, id: idImage, type: "image", span: { base: 48, md: null, lg: 26 }, titleSize: "md", mediaId: media },
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
