/**
 * Sends the tour screenshots to the cards of the public page.
 *
 * **The three steps were manual and repeated twenty-four times**: take the
 * screenshot, copy it to the server, call `aurora:ged:replace` with the
 * right identifier. The last one required knowing that identifier, and it
 * lived nowhere in the repository - the capture script claims that "the
 * card has nothing to know" because it shows a document named after the
 * shot, except that `aurora:ged:replace` changes the stored name on every
 * replacement. The original name can therefore no longer be found.
 *
 * The table now lives in `tour-cards.json`, next to the capture script.
 *
 * Usage:
 *   node tools/screenshots/capture-tour.mjs        # first, against the local demo
 *   node tools/screenshots/push-tour.mjs --dry-run # what would be sent
 *   node tools/screenshots/push-tour.mjs           # send
 *   node tools/screenshots/push-tour.mjs tour-contrats tour-espaces-clients
 *
 * The server is named by `TOUR_SSH_HOST` and the project by `TOUR_REMOTE_DIR`,
 * with no default value: see `lib/remote.mjs`.
 */

import { execFile } from "node:child_process";
import { readFile, access } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { relocateCommand, remote } from "./lib/remote.mjs";

const run = promisify(execFile);

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, "../..");
const shotsDir = resolve(root, "var/screenshots");

const { host: HOST, dir: REMOTE_DIR } = remote();
const REMOTE_TMP = "/tmp/aurora-tour";

const dryRun = process.argv.includes("--dry-run");
const only = process.argv.slice(2).filter((a) => !a.startsWith("--"));

const { cards } = JSON.parse(await readFile(resolve(here, "tour-cards.json"), "utf8"));

/**
 * What gets sent: a screenshot taken, and a card to receive it.
 *
 * Both halves are checked before sending anything, because a send that
 * stops halfway leaves the page half redone, which is worse than a page
 * that is entirely out of date: you no longer know what you are looking at.
 */
const wanted = Object.entries(cards).filter(([name]) => 0 === only.length || only.includes(name));

if (0 === wanted.length) {
    console.error(`Aucune prise ne correspond. Noms connus : ${Object.keys(cards).join(", ")}`);
    process.exit(1);
}

const ready = [];
const missingFile = [];
const noCard = [];

for (const [name, card] of wanted) {
    if (null === card.document) {
        noCard.push(name);
        continue;
    }

    const file = resolve(shotsDir, `${name}.png`);

    try {
        await access(file);
        ready.push({ name, file, ...card });
    } catch {
        missingFile.push(name);
    }
}

for (const name of noCard) {
    console.log(`  ·  ${name} — aucune carte ne la montre`);
}

if (0 !== missingFile.length) {
    console.error(
        `\nCaptures absentes de var/screenshots/ : ${missingFile.join(", ")}.` +
        `\nLancez d'abord : node tools/screenshots/capture-tour.mjs`,
    );
    process.exit(1);
}

console.log(`\n${ready.length} carte(s) à remplacer sur ${HOST}${dryRun ? " (essai à blanc)" : ""}\n`);

if (dryRun) {
    for (const { name, document, slug } of ready) {
        console.log(`  → ${name}  document #${document}  /fr/aurora/${slug}`);
    }
    process.exit(0);
}

await run("ssh", [HOST, `mkdir -p ${REMOTE_TMP}`]);

let failed = 0;
const replaced = [];

for (const { name, file, document, slug } of ready) {
    process.stdout.write(`  → ${name} (#${document}) `);

    try {
        // Through standard input rather than `scp`: a single channel, and nothing
        // to clean up if the connection drops halfway.
        await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/${name}.png' < ${JSON.stringify(file)}`]);
        await run("ssh", [
            HOST,
            `cd ${REMOTE_DIR} && php bin/console aurora:ged:replace ${document} ${REMOTE_TMP}/${name}.png`,
        ]);
        replaced.push(document);
        console.log(`✅  /fr/aurora/${slug}`);
    } catch (error) {
        failed += 1;
        console.log(`❌  ${String(error.stderr ?? error.message).split("\n")[0]}`);
    }
}

// A replacement writes through the site's active disk: the pictures come
// back to the tour's own (see `tourDisk()`).
if (0 !== replaced.length) {
    try {
        await run("ssh", [HOST, relocateCommand(REMOTE_DIR, replaced)]);
        console.log(`\n  ⇢ ${replaced.length} image(s) rangée(s) sur le disque du tour`);
    } catch (error) {
        failed += 1;
        console.log(`\n❌ rangement : ${String(error.stderr ?? error.message).split("\n")[0]}`);
    }
}

// The transit directory is emptied: these are screenshots of a demo data
// set, but they have no business in a production `/tmp` after the send.
await run("ssh", [HOST, `rm -rf ${REMOTE_TMP}`]).catch(() => {});

console.log(failed === 0 ? "\n✅ Toutes les cartes sont à jour." : `\n❌ ${failed} échec(s).`);
process.exit(failed === 0 ? 0 : 1);
