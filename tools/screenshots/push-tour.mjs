/**
 * Envoie les captures du tour sur les cartes de la page publique.
 *
 * **Les trois gestes étaient manuels et répétés vingt-quatre fois** : prendre
 * la capture, la copier sur le serveur, appeler `aurora:ged:replace` avec le
 * bon identifiant. Le dernier demandait de connaître cet identifiant, et il ne
 * vivait nulle part dans le dépôt - le scénario de capture affirme que « la
 * carte n'a rien à savoir » parce qu'elle montre un document nommé d'après la
 * prise, sauf que `aurora:ged:replace` change le nom stocké à chaque
 * remplacement. Le nom d'origine ne se retrouve donc plus.
 *
 * La table vit maintenant dans `tour-cards.json`, à côté du scénario.
 *
 * Usage :
 *   node tools/screenshots/capture-tour.mjs        # d'abord, contre la démo locale
 *   node tools/screenshots/push-tour.mjs --dry-run # ce qui partirait
 *   node tools/screenshots/push-tour.mjs           # envoyer
 *   node tools/screenshots/push-tour.mjs tour-contrats tour-espaces-clients
 *
 * Le serveur est nommé par `TOUR_SSH_HOST` (défaut `vps`) et le projet par
 * `TOUR_REMOTE_DIR` (défaut `/var/www/aurora-client`).
 */

import { execFile } from "node:child_process";
import { readFile, access } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";

const run = promisify(execFile);

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, "../..");
const shotsDir = resolve(root, "var/screenshots");

const HOST = process.env.TOUR_SSH_HOST ?? "vps";
const REMOTE_DIR = process.env.TOUR_REMOTE_DIR ?? "/var/www/aurora-client";
const REMOTE_TMP = "/tmp/aurora-tour";

const dryRun = process.argv.includes("--dry-run");
const only = process.argv.slice(2).filter((a) => !a.startsWith("--"));

const { cards } = JSON.parse(await readFile(resolve(here, "tour-cards.json"), "utf8"));

/**
 * Ce qui part : une capture prise, et une carte pour la recevoir.
 *
 * Les deux moitiés se vérifient avant d'envoyer quoi que ce soit, parce qu'un
 * envoi qui s'arrête au milieu laisse la page à moitié refaite, ce qui est
 * pire qu'une page entièrement périmée : on ne sait plus ce qu'on regarde.
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

for (const { name, file, document, slug } of ready) {
    process.stdout.write(`  → ${name} (#${document}) `);

    try {
        // Par l'entrée standard plutôt que par `scp` : un seul canal, et rien
        // à nettoyer si la connexion tombe au milieu.
        await run("sh", ["-c", `ssh ${HOST} 'cat > ${REMOTE_TMP}/${name}.png' < ${JSON.stringify(file)}`]);
        await run("ssh", [
            HOST,
            `cd ${REMOTE_DIR} && php bin/console aurora:ged:replace ${document} ${REMOTE_TMP}/${name}.png`,
        ]);
        console.log(`✅  /fr/aurora/${slug}`);
    } catch (error) {
        failed += 1;
        console.log(`❌  ${String(error.stderr ?? error.message).split("\n")[0]}`);
    }
}

// Le transit est vidé : ce sont des captures d'un jeu de démonstration, mais
// elles n'ont rien à faire dans un `/tmp` de production après l'envoi.
await run("ssh", [HOST, `rm -rf ${REMOTE_TMP}`]).catch(() => {});

console.log(failed === 0 ? "\n✅ Toutes les cartes sont à jour." : `\n❌ ${failed} échec(s).`);
process.exit(failed === 0 ? 0 : 1);
