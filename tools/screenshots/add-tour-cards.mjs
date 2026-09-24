/**
 * Ajoute des images à une publication du tour public, d'un seul geste.
 *
 * Poser une image de plus sur une carte demandait cinq gestes à la main :
 * copier la capture sur le serveur, l'importer dans la médiathèque, relever
 * l'identifiant rendu, ajouter une zone au gabarit de grille de la
 * publication, et écrire le texte de remplacement dans les trois langues. Je
 * l'ai fait sept fois pour la carte des notes ; le faire trente fois de plus
 * à la main, c'est se tromper au moins une fois sur un identifiant, et une
 * zone qui pointe vers le mauvais document est invisible jusqu'à ce que
 * quelqu'un regarde la page.
 *
 * Usage :
 *   node tools/screenshots/add-tour-cards.mjs plan.json --dry-run
 *   node tools/screenshots/add-tour-cards.mjs plan.json
 *
 * Le plan, en JSON :
 *   {
 *     "slug": "mediatheque",
 *     "after": "tour-mediatheque-grille",   // facultatif : où insérer
 *     "cards": [
 *       {"name": "tour-mediatheque-fiche", "alt": {"fr": "…", "en": "…", "es": "…"}}
 *     ]
 *   }
 *
 * Ce que le script garantit :
 *
 * - **Il ne touche à rien avant d'avoir tout vérifié.** Les captures doivent
 *   exister, les trois langues doivent être écrites, et la publication doit
 *   être trouvée. Un envoi qui s'arrête au milieu laisse une page à moitié
 *   refaite, ce qui est pire qu'une page inchangée.
 * - **La grille est écrite dans une transaction.** Le gabarit et les trois
 *   traductions partent ensemble ou pas du tout : une zone déclarée sans son
 *   texte de remplacement est une image sans description pour qui lit à
 *   l'oreille.
 * - **Il est rejouable.** Une carte déjà posée est reconnue par son nom dans
 *   `tour-cards.json` et remplacée plutôt que dupliquée.
 */

import { execFile } from "node:child_process";
import { access, readFile, writeFile } from "node:fs/promises";
import { randomBytes } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";

const run = promisify(execFile);

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, "../..");
const shotsDir = resolve(root, "var/screenshots");
const cardsFile = resolve(here, "tour-cards.json");

const HOST = process.env.TOUR_SSH_HOST ?? "vps";
const REMOTE_DIR = process.env.TOUR_REMOTE_DIR ?? "/var/www/aurora-client";
const REMOTE_TMP = "/tmp/aurora-tour";
const LOCALES = ["fr", "en", "es"];

const dryRun = process.argv.includes("--dry-run");
const planPath = process.argv.slice(2).find((a) => !a.startsWith("--"));

if (undefined === planPath) {
    console.error("Usage : node tools/screenshots/add-tour-cards.mjs <plan.json> [--dry-run]");
    process.exit(1);
}

const plan = JSON.parse(await readFile(resolve(planPath), "utf8"));
const registry = JSON.parse(await readFile(cardsFile, "utf8"));

/** Une requête SQL sur la base de production, rendue en texte brut. */
async function sql(query) {
    const { stdout } = await run("ssh", [
        HOST,
        `cd ${REMOTE_DIR} && db=$(grep -oP 'DATABASE_URL=.*/\\K[^?"]+' .env.local | head -1); ` +
        `sudo -u postgres psql -At -d $db -c ${JSON.stringify(query)}`,
    ]);

    return stdout.trim();
}

// ---- vérifications, toutes avant le premier octet envoyé -------------------

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

const postId = await sql(
    `SELECT post_id FROM core_post_translations WHERE slug = '${plan.slug}' LIMIT 1`,
);

if ("" === postId) problemes.push(`publication introuvable : ${plan.slug}`);

if (0 !== problemes.length) {
    console.error("Rien n'a été envoyé :\n  - " + problemes.join("\n  - "));
    process.exit(1);
}

console.log(`\n${plan.cards.length} image(s) pour /fr/aurora/${plan.slug} (publication ${postId})${dryRun ? " — essai à blanc" : ""}\n`);

if (dryRun) {
    for (const c of plan.cards) console.log(`  → ${c.name}\n      ${c.alt.fr}`);
    process.exit(0);
}

// ---- l'import dans la médiathèque -----------------------------------------

await run("ssh", [HOST, `mkdir -p ${REMOTE_TMP}`]);

const documents = {};

for (const carte of plan.cards) {
    const connue = registry.cards[carte.name]?.document ?? null;
    const file = resolve(shotsDir, `${carte.name}.png`);

    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/${carte.name}.png' < ${JSON.stringify(file)}`]);

    if (null !== connue) {
        // Déjà posée une fois : on remplace le fichier, la zone reste.
        await run("ssh", [HOST, `cd ${REMOTE_DIR} && php bin/console aurora:ged:replace ${connue} ${REMOTE_TMP}/${carte.name}.png`]);
        documents[carte.name] = connue;
        console.log(`  ↻ ${carte.name} → document #${connue}`);

        continue;
    }

    const { stdout } = await run("ssh", [
        HOST,
        `cd ${REMOTE_DIR} && sudo -u www-data php bin/console aurora:ged:import --env=prod ${REMOTE_TMP}/${carte.name}.png`,
    ]);

    const id = /#(\d+)/.exec(stdout)?.[1];

    if (undefined === id) {
        console.error(`  ❌ ${carte.name} : l'import n'a pas rendu d'identifiant`);
        process.exit(1);
    }

    documents[carte.name] = Number(id);
    console.log(`  + ${carte.name} → document #${id}`);
}

// ---- la grille -------------------------------------------------------------

const layout = JSON.parse(await sql(`SELECT grid_layout::text FROM core_posts WHERE id = ${postId}`));
const modele = layout.zones.find((z) => "media" === z.type);

if (undefined === modele) {
    console.error("Cette publication n'a aucune zone média : il en faut une comme modèle.");
    process.exit(1);
}

const deja = new Set(layout.zones.filter((z) => "media" === z.type).map((z) => z.mediaId));
const nouvelles = [];

for (const carte of plan.cards) {
    const media = documents[carte.name];

    if (deja.has(media)) continue;

    const zone = { ...structuredClone(modele), id: randomBytes(12).toString("hex"), mediaId: media };
    nouvelles.push({ zone, alt: carte.alt });
}

// Insérées après la zone nommée par `after`, ou à la fin.
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

// Le JSON voyage par fichiers, jamais dans la ligne de commande ni dans un
// `\\set` littéral : psql lit la valeur d'un `\\set` comme sa propre syntaxe,
// et le premier guillemet d'un objet JSON y devient une commande inconnue.
// `\\set x \`cat fichier\`` la lui donne telle quelle.
const fichiers = { layout: JSON.stringify(layout) };
for (const l of LOCALES) fichiers[`g${l}`] = JSON.stringify(grids[l]);

// Écrits localement d'abord, puis versés par redirection : `execFile` ne
// parle pas à l'entrée standard du processus qu'il lance, et un `input`
// passé en option y est ignoré sans un mot.
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

// Par un fichier déposé sur le serveur, et non par l'entrée standard :
// `execFile` n'écrit pas dans le processus qu'il lance, et un `input` passé
// là est ignoré en silence - la transaction ne serait jamais jouée et le
// script se féliciterait quand même.
const script = `${REMOTE_TMP}/grid-${postId}.sql`;
const scriptLocal = resolve(tmpdir(), `aurora-grid-${postId}.sql`);
await writeFile(scriptLocal, envoi);
await run("sh", ["-c", `ssh ${HOST} 'cat > ${script}' < ${JSON.stringify(scriptLocal)}`]);
await run("ssh", [
    HOST,
    `cd ${REMOTE_DIR} && db=$(grep -oP 'DATABASE_URL=.*/\\K[^?"]+' .env.local | head -1); ` +
    `sudo -u postgres psql -v ON_ERROR_STOP=1 -d $db -f ${script} && rm -f ${script}`,
]);

// ---- la table des cartes, pour que la prochaine campagne remplace ---------

for (const carte of plan.cards) {
    registry.cards[carte.name] = { document: documents[carte.name], slug: plan.slug };
}

await writeFile(cardsFile, JSON.stringify(registry, null, 4) + "\n");

await run("ssh", [HOST, `cd ${REMOTE_DIR} && sudo -u www-data php bin/console cache:pool:clear cache.app --env=prod`]);

console.log(`\n✅ ${nouvelles.length} zone(s) ajoutée(s), ${plan.cards.length - nouvelles.length} remplacée(s). Cache vidé.`);
