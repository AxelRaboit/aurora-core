/**
 * Adds images to a post of the public tour, in one go.
 *
 * Putting one more image on a card took five steps by hand: copy the
 * screenshot to the server, import it into the media library, note the
 * returned identifier, add a zone to the post's grid layout, and write the
 * alternative text in the three languages. I did it seven times for the notes
 * card; doing it thirty more times by hand means getting an identifier wrong
 * at least once, and a zone pointing at the wrong document is invisible until
 * somebody looks at the page.
 *
 * Usage:
 *   node tools/screenshots/add-tour-cards.mjs plan.json --dry-run
 *   node tools/screenshots/add-tour-cards.mjs plan.json
 *
 * The plan, in JSON:
 *   {
 *     "slug": "mediatheque",
 *     "after": "tour-mediatheque-grille",   // optional: where to insert
 *     "cards": [
 *       {"name": "tour-mediatheque-fiche", "alt": {"fr": "…", "en": "…", "es": "…"}}
 *     ]
 *   }
 *
 * The media library category to file the images in is given by
 * `TOUR_CATEGORY_ID` (like the server, it is not written in this public
 * repository). Without it, the images arrive with no category and the script
 * says so: they had to be filed by hand afterwards, and that got forgotten.
 *
 * What the script guarantees:
 *
 * - **It touches nothing before checking everything.** The screenshots must
 *   exist, the three languages must be written, and the post must be found.
 *   An upload that stops halfway leaves a half-redone page, which is worse
 *   than an unchanged page.
 * - **The grid is written in a transaction.** The layout and the three
 *   translations go together or not at all: a zone declared without its
 *   alternative text is an image without a description for whoever reads by
 *   ear.
 * - **It can be replayed.** A card already placed is recognised by its name
 *   in `tour-cards.json` and replaced rather than duplicated.
 */

import { execFile } from "node:child_process";
import { access, readFile, writeFile } from "node:fs/promises";
import { randomBytes } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { remote, tourPostIdQuery } from "./lib/remote.mjs";

const run = promisify(execFile);

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, "../..");
const shotsDir = resolve(root, "var/screenshots");
const cardsFile = resolve(here, "tour-cards.json");

const { host: HOST, dir: REMOTE_DIR } = remote();
const REMOTE_TMP = "/tmp/aurora-tour";
const LOCALES = ["fr", "en", "es"];

const dryRun = process.argv.includes("--dry-run");

const CATEGORY = process.env.TOUR_CATEGORY_ID ?? "";

if ("" !== CATEGORY && !/^\d+$/.test(CATEGORY)) {
    console.error(`TOUR_CATEGORY_ID doit être un identifiant de catégorie, reçu « ${CATEGORY} ».`);
    process.exit(1);
}
const planPath = process.argv.slice(2).find((a) => !a.startsWith("--"));

if (undefined === planPath) {
    console.error("Usage : node tools/screenshots/add-tour-cards.mjs <plan.json> [--dry-run]");
    process.exit(1);
}

const plan = JSON.parse(await readFile(resolve(planPath), "utf8"));
const registry = JSON.parse(await readFile(cardsFile, "utf8"));

/**
 * An SQL query on the production database, returned as plain text.
 *
 * The query travels by file, as in the other scripts: passed as an argument,
 * it went through the remote shell inside double quotes, where `$(…)` and
 * backticks get executed.
 */
async function sql(query) {
    const local = resolve(tmpdir(), `tour-q-${randomBytes(4).toString("hex")}.sql`);
    await writeFile(local, query);
    await run("ssh", [HOST, `mkdir -p ${REMOTE_TMP}`]);
    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/q.sql' < ${JSON.stringify(local)}`]);
    const { stdout } = await run("ssh", [
        HOST,
        `cd ${REMOTE_DIR} && db=$(grep -oP 'DATABASE_URL=.*/\\K[^?"]+' .env.local | head -1); ` +
        `sudo -u postgres psql -At -d $db -f ${REMOTE_TMP}/q.sql`,
    ]);

    return stdout.trim();
}

// ---- checks, all before the first byte is sent ----------------------------

const problemes = [];

for (const carte of plan.cards) {
    try {
        await access(resolve(shotsDir, `${carte.name}.png`));
    } catch {
        problemes.push(`capture absente : var/screenshots/${carte.name}.png`);
    }

    for (const locale of LOCALES) {
        if (!carte.alt?.[locale]) problemes.push(`${carte.name} : texte de remplacement manquant en ${locale}`);
    }
}

const postId = await sql(tourPostIdQuery(plan.slug));

if ("" === postId) problemes.push(`publication introuvable : ${plan.slug}`);

if (0 !== problemes.length) {
    console.error("Rien n'a été envoyé :\n  - " + problemes.join("\n  - "));
    process.exit(1);
}

console.log(`\n${plan.cards.length} image(s) pour /fr/aurora/${plan.slug} (publication ${postId})${dryRun ? " — essai à blanc" : ""}\n`);

if ("" === CATEGORY) {
    console.warn("⚠️  TOUR_CATEGORY_ID absent : les nouvelles images arriveront sans catégorie.\n");
}

if (dryRun) {
    for (const c of plan.cards) console.log(`  → ${c.name}\n      ${c.alt.fr}`);
    process.exit(0);
}

// ---- the import into the media library ------------------------------------

await run("ssh", [HOST, `mkdir -p ${REMOTE_TMP}`]);

const documents = {};

for (const carte of plan.cards) {
    const connue = registry.cards[carte.name]?.document ?? null;
    const file = resolve(shotsDir, `${carte.name}.png`);

    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/${carte.name}.png' < ${JSON.stringify(file)}`]);

    if (null !== connue) {
        // Already placed once: the file is replaced, the zone stays.
        await run("ssh", [HOST, `cd ${REMOTE_DIR} && php bin/console aurora:ged:replace ${connue} ${REMOTE_TMP}/${carte.name}.png`]);
        documents[carte.name] = connue;
        console.log(`  ↻ ${carte.name} → document #${connue}`);

        continue;
    }

    const { stdout } = await run("ssh", [
        HOST,
        `cd ${REMOTE_DIR} && sudo -u www-data php bin/console aurora:ged:import --env=prod${"" !== CATEGORY ? ` --category=${CATEGORY}` : ""} ${REMOTE_TMP}/${carte.name}.png`,
    ]);

    const id = /#(\d+)/.exec(stdout)?.[1];

    if (undefined === id) {
        console.error(`  ❌ ${carte.name} : l'import n'a pas rendu d'identifiant`);
        process.exit(1);
    }

    documents[carte.name] = Number(id);
    console.log(`  + ${carte.name} → document #${id}`);
}

// ---- the grid --------------------------------------------------------------

const layout = JSON.parse(await sql(`SELECT grid_layout::text FROM core_posts WHERE id = ${postId}`));
/**
 * The shape of a media zone, as the normaliser expects it.
 *
 * Taken from the page if it already has one, written here otherwise. The
 * "otherwise" case became the common one the day the banner was put on the
 * twenty-four pages: it takes the first image into the header, and the body
 * is left without a single media zone to copy from.
 */
const MODELE_MEDIA = {
    anchor: "",
    type: "media",
    span: { base: 48, md: null, lg: 48 },
    offset: 0,
    newRow: false,
    ratio: "natural",
    scale: 100,
    align: "center",
    mediaId: null,
    mediaUrl: null,
    postId: null,
    variant: "solid",
    size: "md",
    separatorStyle: "line",
    display: "steps",
    columns: 3,
    items: [],
    postTypeId: null,
    termId: null,
    limit: 3,
    cardVariant: "full",
    formId: null,
    language: null,
    textSize: "normal",
    lineNumbers: false,
    surface: "card",
    fullBleed: false,
    children: [],
};

const modele = layout.zones.find((z) => "media" === z.type) ?? MODELE_MEDIA;

const deja = new Set(layout.zones.filter((z) => "media" === z.type).map((z) => z.mediaId));
const nouvelles = [];

for (const carte of plan.cards) {
    const media = documents[carte.name];

    if (deja.has(media)) continue;

    const zone = { ...structuredClone(modele), id: randomBytes(12).toString("hex"), mediaId: media };
    nouvelles.push({ zone, alt: carte.alt });
}

// Inserted after the zone named by `after`, or at the end.
const ancre = plan.after ? layout.zones.findIndex((z) => z.mediaId === registry.cards[plan.after]?.document) : -1;
const position = -1 === ancre ? layout.zones.length : ancre + 1;
layout.zones.splice(position, 0, ...nouvelles.map((n) => n.zone));

const grids = {};

for (const locale of LOCALES) {
    const grid = JSON.parse(await sql(`SELECT grid::text FROM core_post_translations WHERE post_id = ${postId} AND locale = '${locale}'`));
    const gabarit = Object.values(grid.zones)[0] ?? {};

    for (const { zone, alt } of nouvelles) {
        const vide = Object.fromEntries(
            Object.entries(gabarit).map(([k, v]) => [k, Array.isArray(v) ? [] : ("string" === typeof v ? "" : null)]),
        );
        grid.zones[zone.id] = { ...vide, alt: alt[locale] };
    }

    grids[locale] = grid;
}

// The JSON travels by files, never on the command line nor in a literal
// `\\set`: psql reads the value of a `\\set` as its own syntax, and the
// first quote of a JSON object becomes an unknown command there.
// `\\set x \`cat file\`` hands it over as it is.
const fichiers = { layout: JSON.stringify(layout) };
for (const l of LOCALES) fichiers[`g${l}`] = JSON.stringify(grids[l]);

// Written locally first, then poured in by redirection: `execFile` does not
// talk to the standard input of the process it starts, and an `input` passed
// as an option is ignored there without a word.
for (const [nom, contenu] of Object.entries(fichiers)) {
    const local = resolve(tmpdir(), `aurora-${nom}-${postId}.json`);
    await writeFile(local, contenu);
    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/${nom}-${postId}.json' < ${JSON.stringify(local)}`]);
}

const envoi = [
    ...Object.keys(fichiers).map((nom) => `\\set ${nom} \`cat ${REMOTE_TMP}/${nom}-${postId}.json\``),
    "BEGIN;",
    `UPDATE core_posts SET grid_layout = :'layout' WHERE id = ${postId};`,
    ...LOCALES.map((l) => `UPDATE core_post_translations SET grid = :'g${l}' WHERE post_id = ${postId} AND locale = '${l}';`),
    "COMMIT;",
].join("\n");

// Through a file put on the server, and not through standard input:
// `execFile` does not write into the process it starts, and an `input`
// passed there is silently ignored - the transaction would never run and the
// script would congratulate itself anyway.
const script = `${REMOTE_TMP}/grid-${postId}.sql`;
const scriptLocal = resolve(tmpdir(), `aurora-grid-${postId}.sql`);
await writeFile(scriptLocal, envoi);
await run("sh", ["-c", `ssh ${HOST} 'cat > ${script}' < ${JSON.stringify(scriptLocal)}`]);
await run("ssh", [
    HOST,
    `cd ${REMOTE_DIR} && db=$(grep -oP 'DATABASE_URL=.*/\\K[^?"]+' .env.local | head -1); ` +
    `sudo -u postgres psql -v ON_ERROR_STOP=1 -d $db -f ${script} && rm -f ${script}`,
]);

// ---- the card table, so the next campaign replaces ------------------------

for (const carte of plan.cards) {
    registry.cards[carte.name] = { document: documents[carte.name], slug: plan.slug };
}

await writeFile(cardsFile, JSON.stringify(registry, null, 4) + "\n");

await run("ssh", [HOST, `cd ${REMOTE_DIR} && sudo -u www-data php bin/console cache:pool:clear cache.app --env=prod`]);

console.log(`\n✅ ${nouvelles.length} zone(s) ajoutée(s), ${plan.cards.length - nouvelles.length} remplacée(s). Cache vidé.`);
