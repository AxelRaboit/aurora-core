/**
 * Add an image to a page's banner, without touching the rest.
 *
 * `set-tour-banner.mjs` **rewrites** a banner from the page's title and
 * summary: that is what the tour pages need, since they all look alike. The
 * index page, though, has a hand-written banner, with its pitch and its
 * button, and rebuilding it would lose them.
 *
 * Hence this tool: it reads the elements in place, inserts one more, and
 * puts everything back. A banner's elements are spread over the same 48
 * columns as the rest, so the chosen width also decides what stays on the
 * row.
 *
 * `--background` targets the banner's other image slot: the background,
 * behind everything else, which is not an element of the list. That is where
 * a header's visual lives; `logoMediaId` renders a 40 pixel mark at the top
 * left and is not what we want.
 *
 * Usage:
 *   node tools/screenshots/add-banner-image.mjs <postId> <capture> [--span 20] [--after <n>] [--dry-run]
 *   node tools/screenshots/add-banner-image.mjs <postId> <capture> --background
 *
 * `--after` is the zero-based index of the element to slip in behind; by
 * default the image follows the first element.
 */
import { execFile } from "node:child_process";
import { writeFile } from "node:fs/promises";
import { randomBytes } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { relocateCommand, remote } from "./lib/remote.mjs";

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

const { host: HOST, dir: REMOTE_DIR } = remote();
const REMOTE_TMP = "/tmp/aurora-tour";

const args = process.argv.slice(2);
const dryRun = args.includes("--dry-run");
const span = args.includes("--span") ? Number(args[args.indexOf("--span") + 1]) : 20;
const after = args.includes("--after") ? Number(args[args.indexOf("--after") + 1]) : 0;
const remove = args.includes("--remove") ? Number(args[args.indexOf("--remove") + 1]) : null;
const asBackground = args.includes("--background");
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
 * One query, per file.
 *
 * Never inline: psql reads the value of a `\set` as its own syntax, and
 * `execFile` silently ignores a standard input passed as an option.
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

// **Removal, because an addition is judged once it is in place.** The one on
// the index page went onto a page whose banner already carried screenshots
// in the background: the small image on top cluttered instead of adding, and
// it had to be undoable without rewriting the banner by hand.
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

// The image goes to the server, then into the media library, before being
// referenced: a banner pointing to a missing document would show nothing,
// and fixing it would take a second database write.
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

    // Imported through the site's active disk: back to the tour's own.
    await run("ssh", [HOST, relocateCommand(REMOTE_DIR, [mediaId])]);
}

if (asBackground) {
    const before = layout.background?.mediaId ?? null;

    console.log(`Fond : #${before ?? "aucun"} → ${dryRun ? "(import non joué en simulation)" : `#${mediaId}`}`);

    // **`--dry-run` does not get through here without this guard.** The
    // first version wrote anyway, and since the import is not run in
    // simulation the `mediaId` was null: a simulation erased the header's
    // background in production. A simulation that writes is not a
    // simulation.
    if (dryRun) {
        console.log("Rien n'a été écrit.");
        process.exit(0);
    }

    layout.background = { ...(layout.background ?? {}), mediaId };
    console.log("  l'ancien document reste en médiathèque, le retour tient en une commande.");

    await write(layout);
    process.exit(0);
}

// The model is an existing element: its keys are the ones the normalizer
// writes, and copying them avoids inventing a wrong one.
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

    // A database write does not go through the application cache: the page
    // would serve its old HTML for an hour.
    await run("ssh", [HOST, `cd ${REMOTE_DIR} && sudo -u www-data php bin/console cache:pool:clear cache.app --env=prod`]);

    console.log("✅ bandeau mis à jour, cache vidé.");
}
