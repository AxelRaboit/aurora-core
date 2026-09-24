/**
 * Ajouter une image au bandeau d'une page, sans toucher au reste.
 *
 * `set-tour-banner.mjs` **réécrit** un bandeau à partir du titre et du résumé
 * de la page : c'est ce qu'il faut pour les pages du tour, qui se ressemblent
 * toutes. Le sommaire, lui, a un bandeau écrit à la main, avec son argumentaire
 * et son bouton, et le refabriquer le perdrait.
 *
 * D'où cet outil-ci : il lit les éléments en place, en insère un de plus, et
 * repose le tout. Les éléments d'un bandeau se répartissent sur les mêmes 48
 * colonnes que le reste, donc la largeur choisie décide aussi de ce qui reste
 * sur la ligne.
 *
 * Usage :
 *   node tools/screenshots/add-banner-image.mjs <postId> <capture> [--span 20] [--after <n>] [--dry-run]
 *
 * `--after` est l'indice, à partir de zéro, de l'élément derrière lequel se
 * glisser ; par défaut l'image suit le premier élément.
 */
import { execFile } from "node:child_process";
import { writeFile } from "node:fs/promises";
import { randomBytes } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const run = promisify(execFile);

function promisify(fn) {
    return (...args) =>
        new Promise((ok, ko) =>
            fn(...args, (err, stdout, stderr) =>
                err ? ko(Object.assign(err, { stdout, stderr })) : ok({ stdout, stderr }),
            ),
        );
}

const here = dirname(fileURLToPath(import.meta.url));
const shotsDir = resolve(here, "../../var/screenshots");

const HOST = process.env.TOUR_SSH_HOST ?? "vps";
const REMOTE_DIR = process.env.TOUR_REMOTE_DIR ?? "/var/www/aurora-client";
const REMOTE_TMP = "/tmp/aurora-tour";

const args = process.argv.slice(2);
const dryRun = args.includes("--dry-run");
const span = args.includes("--span") ? Number(args[args.indexOf("--span") + 1]) : 20;
const after = args.includes("--after") ? Number(args[args.indexOf("--after") + 1]) : 0;
const remove = args.includes("--remove") ? Number(args[args.indexOf("--remove") + 1]) : null;
const plain = args.filter((a) => !a.startsWith("--"));
const postId = Number(plain[0]);
const capture = plain[1];

if (!Number.isInteger(postId) || (undefined === capture && null === remove)) {
    console.error(
        "Usage : node tools/screenshots/add-banner-image.mjs <postId> <capture> [--span 20] [--after <n>] [--dry-run]",
    );
    process.exit(1);
}

/**
 * Une requête, par fichier.
 *
 * Jamais en ligne : psql lit la valeur d'un `\set` comme sa propre syntaxe,
 * et `execFile` ignore en silence une entrée standard passée en option.
 */
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

const layout = JSON.parse(await sql(`SELECT banner_layout FROM core_posts WHERE id = ${postId};`));
const items = Array.isArray(layout.items) ? layout.items : [];

if (0 === items.length) {
    console.error(`❌ la page ${postId} n'a pas de bandeau à compléter.`);
    process.exit(1);
}

console.log(`Bandeau de la page ${postId} : ${items.map((i) => `${i.type}/${i.span?.lg ?? "?"}`).join(", ")}`);

// **Le retrait, parce qu'un ajout se juge une fois posé.** Celui du sommaire
// est parti sur une page dont le bandeau portait déjà des captures en fond :
// la petite image par-dessus encombrait au lieu d'ajouter, et il fallait
// pouvoir défaire sans réécrire le bandeau à la main.
if (null !== remove) {
    const kept = items.filter((i) => Number(i.mediaId) !== remove);

    if (kept.length === items.length) {
        console.error(`❌ aucun élément ne pointe le document #${remove}.`);
        process.exit(1);
    }

    layout.items = kept;
    console.log(`Après : ${kept.map((i) => `${i.type}/${i.span?.lg ?? "?"}`).join(", ")}`);

    if (!dryRun) await write(layout);

    process.exit(0);
}

// L'image part sur le serveur, puis dans la médiathèque, avant d'être
// référencée : un bandeau qui pointerait un document inexistant s'afficherait
// sans rien, et le corriger demanderait une seconde écriture en base.
const source = resolve(shotsDir, `${capture}.png`);
let mediaId = null;

if (!dryRun) {
    await run("sh", ["-c", `scp -q ${JSON.stringify(source)} ${HOST}:${REMOTE_TMP}/${capture}.png`]);

    const { stdout } = await run("ssh", [
        HOST,
        `cd ${REMOTE_DIR} && sudo -u www-data php bin/console aurora:ged:import --env=prod ${REMOTE_TMP}/${capture}.png`,
    ]);

    mediaId = Number(/#(\d+)/.exec(stdout)?.[1]);

    if (!Number.isInteger(mediaId)) {
        console.error("❌ l'import n'a pas rendu d'identifiant de document.");
        process.exit(1);
    }

    console.log(`  ${capture} → document #${mediaId}`);
}

// Le modèle est un élément existant : ses clés sont celles que le
// normaliseur écrit, et les recopier évite d'en inventer une de travers.
const model = items[0];

const image = {
    ...model,
    id: randomBytes(12).toString("hex"),
    type: "image",
    span: { base: 48, md: null, lg: span },
    mediaId,
};

const next = [...items.slice(0, after + 1), image, ...items.slice(after + 1)];
layout.items = next;

console.log(`Après : ${next.map((i) => `${i.type}/${i.span?.lg ?? "?"}`).join(", ")}`);

if (dryRun) {
    console.log("Rien n'a été écrit.");
    process.exit(0);
}

await write(layout);

async function write(payload) {
    const local = resolve(tmpdir(), `aurora-blayout-${postId}.json`);
    await writeFile(local, JSON.stringify(payload));
    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/blayout-${postId}.json' < ${JSON.stringify(local)}`]);

    const script = resolve(tmpdir(), `aurora-addimg-${postId}.sql`);
    await writeFile(
        script,
        [
            `\\set blayout \`cat ${REMOTE_TMP}/blayout-${postId}.json\``,
            "BEGIN;",
            `UPDATE core_posts SET banner_layout = :'blayout' WHERE id = ${postId};`,
            "COMMIT;",
        ].join("\n"),
    );
    await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/addimg-${postId}.sql' < ${JSON.stringify(script)}`]);
    await run("ssh", [
        HOST,
        `cd ${REMOTE_DIR} && db=$(grep -oP 'DATABASE_URL=.*/\\K[^?"]+' .env.local | head -1); ` +
            `sudo -u postgres psql -v ON_ERROR_STOP=1 -d $db -f ${REMOTE_TMP}/addimg-${postId}.sql`,
    ]);

    // Une écriture en base ne traverse pas le cache applicatif : la page
    // servirait son ancien HTML pendant une heure.
    await run("ssh", [HOST, `cd ${REMOTE_DIR} && sudo -u www-data php bin/console cache:pool:clear cache.app --env=prod`]);

    console.log("✅ bandeau mis à jour, cache vidé.");
}
