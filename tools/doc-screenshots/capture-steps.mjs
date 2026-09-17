/**
 * Captures the steps of a task, not the screen it happens on.
 *
 * A page of the documentation shows what to click, what opens, what is typed
 * and what comes back - so each step needs its own picture, taken while the
 * screen is in that state. Which is why this drives the product rather than
 * visiting an address: the forms are panels, and a panel has no URL.
 *
 * Local only, demo data only.
 *
 * Usage: node var/doc-screenshots/capture-steps.mjs [flux...]
 * Output: var/doc-screenshots/out/<flux>-NN-<nom>.png
 */
import { chromium } from "@playwright/test";
import { copyFile, mkdir, readdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const outDir = resolve(here, "../../var/doc-screenshots/out");
const BASE = process.env.DOC_BASE_URL ?? "http://127.0.0.1:8000";
const VIEWPORT = { width: 1600, height: 1000 };

let page;
let flowName = "";
let step = 0;

const wait = (ms = 1200) => page.waitForTimeout(ms);

async function hideChrome() {
  await page.addStyleTag({
    content: `
      .sf-toolbar, .sf-minitoolbar, #sfToolbarMainContent, #sfToolbarClearer { display: none !important; }
      *, *::before, *::after { caret-color: transparent !important; }
      :focus-visible { outline: none !important; }
    `,
  }).catch(() => {});
}

/** One picture, numbered in the order the reader will meet it. */
async function shot(name) {
  step += 1;
  await hideChrome();
  const file = `${flowName}-${String(step).padStart(2, "0")}-${name}.png`;
  await page.screenshot({ path: resolve(outDir, file) });
  console.log(`  + ${file}`);
}

/**
 * Une photo cadrée sur un élément, avec une marge autour.
 *
 * Une capture pleine page pour montrer une ligne de liste demande au lecteur
 * de chercher : le détail est là, mais il fait 40 pixels sur 1600. Le cadre
 * dit où regarder, ce qu'une flèche ferait si la documentation en avait.
 */
async function shotOf(locator, name, padX = 16, padY = 0) {
  const box = await locator.boundingBox();

  if (!box) {
    throw new Error(`élément introuvable pour la photo « ${name} »`);
  }

  // Marge horizontale seulement par défaut : les éléments d'une liste se
  // touchent, et quelques pixels au-dessus font entrer la moitié de la ligne
  // précédente dans le cadre. Une ligne coupée en deux en haut d'une image se
  // lit comme une capture bâclée, ce qu'elle est.
  const x = Math.max(0, box.x - padX);
  const y = Math.max(0, box.y - padY);

  step += 1;
  await hideChrome();
  const file = `${flowName}-${String(step).padStart(2, "0")}-${name}.png`;
  await page.screenshot({
    path: resolve(outDir, file),
    clip: {
      x,
      y,
      width: Math.min(VIEWPORT.width - x, box.width + padX * 2),
      height: Math.min(VIEWPORT.height - y, box.height + padY * 2),
    },
  });
  console.log(`  + ${file}`);
}

/**
 * Un verbe de page, lu dans la feuille plutôt que sur la barre.
 *
 * Depuis la 0.9.172 un en-tête de liste ou d'éditeur n'a plus qu'un bouton
 * « Actions » : le verbe est une ligne de la modale qu'il ouvre. Les parcours
 * qui visaient le bouton directement passent par ici, ce qui garde le nom du
 * verbe dans le scénario et ne met le chemin qu'à un seul endroit.
 *
 * `exact` sur le déclencheur : les menus de ligne s'annoncent « Actions pour
 * Untel » et se feraient viser en premier sans lui.
 */
async function pageAction(name) {
  await page.getByRole("button", { name: "Actions", exact: true }).first().click();
  await wait(900);

  const entry = page.getByRole("button", { name }).first();

  if (await entry.count() > 0) {
    await entry.click();
  } else {
    // Une action qui navigue reste un lien, pour rester ouvrable dans un
    // nouvel onglet.
    await page.getByRole("link", { name }).first().click();
  }

  await wait(1500);
}

/**
 * Ouvre le tableau du premier espace de la démo, sans photo.
 *
 * Par la liste plutôt que par une adresse : les identifiants changent à chaque
 * rechargement des fixtures, et un parcours qui code un `/workspace/8` en dur
 * photographie une page d'erreur le lendemain.
 */
/**
 * Ferme la fenêtre ouverte par son bouton.
 *
 * Pas `Escape` : ces modales sont `closeable: false`, donc la touche ne fait
 * rien et le parcours attendait vingt secondes un écran qui n'avait pas bougé.
 * Le bouton est de toute façon le chemin qu'emprunte un lecteur.
 */
async function closeDialog() {
  await page.getByRole("button", { name: "Annuler" }).first().click();
  await wait(900);
}

async function selectView(label) {
  await page.getByRole("button", { name: label, exact: true }).first().click();
  await wait(2000);
}

/**
 * La forme de l'onglet Contenus : kanban ou liste.
 *
 * Deux boutons d'icône titrés, à droite du sélecteur de vues. Il faut être sur
 * l'onglet Contenus pour qu'ils existent, d'où le passage par selectView.
 */
async function selectShape(label) {
  await selectView("Contenus");
  await page.getByRole("button", { name: label, exact: true }).first().click();
  await wait(1500);
}

async function openFirstSpace() {
  await page.goto(`${BASE}/backend/studio/spaces`, { waitUntil: "domcontentloaded" });
  await wait(2500);
  await page.getByRole("link", { name: /Réseaux sociaux/ }).first().click();
  await wait(3000);

  // Toujours sur Contenus, quel que soit le flux précédent : l'onglet ouvert
  // est retenu d'une visite à l'autre, donc un flux qui finit sur Calendrier
  // décide de ce que le suivant trouve en arrivant. Un clic de plus vaut mieux
  // qu'un ordre de flux à ne pas casser.
  await page.getByRole("button", { name: "Contenus", exact: true }).first().click();
  await wait(1500);
}

/**
 * Un brouillon de contrat, sans photo, pour les parcours qui commencent après.
 *
 * Créé par l'écran plutôt que posé en base : un parcours qui part d'une
 * donnée fabriquée à côté ne prouve pas que l'écran sait la produire.
 */
async function createDraft() {
  await page.goto(`${BASE}/backend/studio/contracts`, { waitUntil: "domcontentloaded" });
  await wait(2500);
  await pageAction("Préparer un contrat");
  await wait(300);

  const selects = page.locator("select");
  await selects.nth(1).selectOption({ index: 1 });
  await wait(700);
  const body = await selects.nth(2).locator("option").filter({ hasText: /prestation mensuelle/i }).first().getAttribute("value");
  await selects.nth(2).selectOption(body);
  await wait(700);
  await selects.nth(3).selectOption({ index: 1 });
  await wait(700);
  await page.getByPlaceholder("850").first().fill("690");
  await page.getByPlaceholder("2026-10-01").first().click();
  await wait(800);
  await page.getByText("15", { exact: true }).first().click();
  await wait(800);

  const extras = page.getByPlaceholder(/^Par exemple/);

  if (await extras.count() > 0) {
    await extras.nth(0).fill("12 mois");
    await extras.nth(1).fill("Formule Suivi");
  }

  await page.getByRole("button", { name: "Enregistrer" }).first().click();
  await wait(3000);
}

/**
 * Le code de confirmation, lu dans la boîte de réception locale.
 *
 * Mailpit reçoit ce que le produit envoie, donc le parcours photographié est
 * le vrai : le code n'est ni deviné ni réécrit en base, il est lu là où le
 * client le lirait.
 */
async function lastCodeFromMailbox() {
  const inbox = await fetch("http://127.0.0.1:8025/api/v1/messages?limit=1").then((r) => r.json());
  const id = inbox?.messages?.[0]?.ID;

  if (!id) {
    throw new Error("aucun message dans Mailpit");
  }

  const mail = await fetch(`http://127.0.0.1:8025/api/v1/message/${id}`).then((r) => r.json());
  const body = `${mail.Text ?? ""} ${(mail.HTML ?? "").replace(/<[^>]+>/g, " ")}`;
  const code = body.match(/\b(\d{6})\b/);

  if (!code) {
    throw new Error("aucun code à six chiffres dans le dernier message");
  }

  return code[1];
}

/**
 * Ouvre une note et attend qu'elle soit vraiment à l'écran.
 *
 * L'adresse charge la page, mais l'éditeur se remplit après : une capture
 * prise trop tôt montre la note précédente avec le panneau de la nouvelle,
 * ce qui s'est produit une fois et n'a été vu qu'en regardant l'image. Le
 * titre attendu est la seule preuve que le bon carnet est ouvert.
 */
async function openNote(id, title) {
  // `waitUntil: "load"` et non `domcontentloaded` : l'éditeur se remplit
  // depuis des données posées par le gabarit, et partir trop tôt laissait le
  // champ sur la note précédente pendant plus de trente secondes - assez
  // longtemps pour que la garde ci-dessous conclue à tort que la page n'avait
  // pas changé.
  await page.goto(`${BASE}/backend/notes/markdown/${id}`, { waitUntil: "load" });

  const field = page.getByPlaceholder("Titre de la note…").first();
  await field.waitFor({ timeout: 15000 });

  // Vérifié, pas espéré : l'éditeur se remplit après le chargement, et une
  // capture prise trop tôt a déjà montré la note précédente à côté du
  // panneau de la nouvelle. Une image fausse et plausible est le pire cas,
  // parce qu'elle passe la relecture.
  for (let tries = 0; tries < 75; tries += 1) {
    if (await field.inputValue() === title) {
      await wait(1200);

      return;
    }

    // Une fois, et une seule : il arrive que la navigation n'aboutisse pas -
    // une garde « modifications non enregistrées » posée par l'éditeur y
    // suffit. Recharger règle le cas sans masquer le vrai échec, puisque la
    // boucle continue de vérifier ensuite.
    if (10 === tries) {
      await page.reload({ waitUntil: "load" });
      await wait(1500);
    }

    await page.waitForTimeout(400);
  }

  throw new Error(`la note « ${title} » ne s'est pas ouverte (vu : « ${await field.inputValue()} », adresse ${page.url()})`);
}

/**
 * Une image à déposer, prise dans les fichiers que la démo a déjà écrits.
 *
 * Le dépôt se photographie avec un vrai fichier, et `test_files/` n'est pas
 * livré avec le dépôt : les uploads de la démo sont la seule source présente
 * sur toutes les machines.
 */
async function sampleUpload() {
  const uploads = resolve(here, "../../var/uploads/ged");
  const months = await readdir(uploads, { recursive: true, withFileTypes: true });
  const picture = months.find((entry) => entry.isFile() && /\.(jpg|jpeg|png)$/i.test(entry.name));

  if (!picture) {
    throw new Error("aucune image dans var/uploads/ged : lancer `make demo` d'abord");
  }

  const copy = resolve(outDir, "..", "affiche-salon-2026.jpg");
  await copyFile(resolve(picture.parentPath ?? picture.path, picture.name), copy);

  return copy;
}

/**
 * Ouvre le détail du document que la démo fait vivre en trois versions.
 *
 * Vérifié plutôt qu'espéré : la recherche filtre la liste en différé, et une
 * capture prise trop tôt montrait le détail d'un autre document.
 */
async function openCampaignDocument() {
  const title = "Visuel de campagne - Automne 2025";

  await page.goto(`${BASE}/backend/ged/documents`, { waitUntil: "domcontentloaded" });
  await wait(2500);
  await page.getByPlaceholder("Rechercher un document…").first().fill("Visuel de campagne");
  await wait(2500);

  await page.getByRole("button", { name: `Aperçu : ${title}` }).first().click();
  await wait(2500);

  const heading = page.getByRole("heading", { name: title });
  await heading.waitFor({ timeout: 15000 });
  await wait(800);
}

/**
 * Supprime un document depuis son menu d'actions.
 *
 * Par l'interface plutôt qu'en base : c'est le chemin que le produit
 * garantit, et il nettoie aussi ce que la suppression nettoie.
 */
async function removeDocument(title) {
  await page.getByPlaceholder("Rechercher un document…").first().fill(title);
  await wait(2500);

  const actions = page.getByRole("button", { name: `Actions pour ${title}` });

  if (await actions.count() === 0) {
    return;
  }

  await actions.first().click();
  await wait(900);

  // Cadré sur la fenêtre ouverte : l'arbre des dossiers porte lui aussi des
  // boutons « Supprimer », invisibles tant qu'on ne survole pas leur ligne,
  // et c'est sur l'un d'eux que le clic attendait sa disparition.
  //
  // L'entrée du menu porte son libellé et sa description d'un seul tenant
  // (« SupprimerSupprime le document et son fichier… »), donc le nom exact
  // ne désigne que le bouton de confirmation.
  await page.locator('[role="dialog"]').last().getByRole("button", { name: /^Supprimer/ }).first().click();
  await wait(1500);
  await page.locator('[role="dialog"]').last().getByRole("button", { name: "Supprimer", exact: true }).click();
  await wait(2000);
}

/**
 * Ouvre le calendrier sur un mois qui contient les événements de la démo.
 */
async function openCalendar() {
  await page.goto(`${BASE}/backend/planning/calendar`, { waitUntil: "domcontentloaded" });
  await page.getByRole("button", { name: "Mois", exact: true }).first().waitFor({ timeout: 20000 });
  await wait(2500);
}

/**
 * Le plus petit ancêtre d'un élément qui soit au moins aussi large que dit.
 *
 * Une ligne de tableau n'a pas de sélecteur à elle : cadrer un rappel donne
 * une vignette de quarante pixels, et cadrer son conteneur nommé donne la
 * page entière. Remonter jusqu'à une largeur attendue trouve la ligne sans
 * dépendre d'une classe de mise en page.
 */
async function wideAncestor(locator, minWidth) {
  for (let up = 0; up < 8; up += 1) {
    const candidate = 0 === up ? locator : locator.locator(`xpath=ancestor::*[${up}]`);
    const box = await candidate.boundingBox();

    if (box && box.width >= minWidth) {
      return candidate;
    }
  }

  throw new Error(`aucun ancêtre d'au moins ${minWidth} pixels de large`);
}

const FLOWS = {
  /**
   * L'espace client : la liste, sa création, son équipe.
   *
   * Le premier espace de la démo sert de sujet plutôt qu'un espace créé ici :
   * il a un tableau rempli et une équipe, donc les photos montrent un écran
   * qui a vécu. La fenêtre de création, elle, se photographie vide - c'est
   * l'état dans lequel le lecteur la rencontrera.
   */
  "un-espace-client": async () => {
    await page.goto(`${BASE}/backend/studio/spaces`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-liste");

    await pageAction("Ajouter un espace");
    await wait(600);
    await shot("la-fenetre");

    // Le bloc de l'équipe est en bas de la fenêtre, donc hors cadre tant que
    // la modale n'a pas défilé.
    const dialog = page.getByRole("dialog").first();
    await dialog.getByRole("textbox").first().fill("Boulangerie Martin - Réseaux sociaux");
    await wait(300);
    await page.mouse.wheel(0, 400);
    await wait(700);
    await shot("l-equipe");

    await closeDialog();

    await page.getByRole("button", { name: /personnes?$/ }).first().click();
    await wait(1200);
    await shot("la-modale-d-equipe");
  },

  /**
   * Le tableau : ses étapes, une carte, la poignée qui la déplace.
   */
  "le-tableau": async () => {
    await openFirstSpace();
    await shot("le-tableau");

    // Visé par son libellé plutôt que par sa place : l'en-tête d'une étape porte
    // deux boutons voisins, et « le premier » est le genre de repère qui
    // photographie la corbeille le jour où l'ordre change.
    await page.getByRole("button", { name: "Renommer l'étape" }).first().click();
    await wait(1000);
    await shot("une-etape");

    await closeDialog();

    await page.getByRole("button", { name: "Ajouter un contenu" }).first().click();
    await wait(1000);
    await shot("un-contenu");

    await closeDialog();

    // La poignée n'existe qu'au survol : une photo prise sans survoler montre
    // une carte sans l'affordance dont la page parle.
    const card = page.locator("article").first();
    await card.hover();
    await wait(500);
    await shotOf(card, "la-poignee", 12, 8);
  },

  /**
   * Le calendrier : le mois, et la colonne de ce qui n'a pas de date.
   */
  /**
   * Les fichiers d'un espace : où on les dépose, et les deux façons de les lire.
   *
   * Le dépôt se photographie sur une fiche ouverte parce que c'est là qu'il
   * vit : un fichier se pose sur un contenu, jamais sur l'espace. La vue
   * Fichiers vient ensuite, dans ses deux formes, puis le dossier que l'espace
   * s'est ouvert dans la médiathèque - qui est la partie que personne ne pense
   * à regarder et que la page existe pour montrer.
   */
  "les-fichiers": async () => {
    await openFirstSpace();

    // Une fiche qui porte déjà des pièces jointes : la zone de dépôt seule ne
    // montre pas ce qu'elle produit.
    await page.getByRole("button", { name: /Modifier/ }).first().click().catch(async () => {
      await page.locator("article").first().click();
    });
    await wait(1500);

    const panel = page.getByRole("dialog").first();
    await shot("la-fiche-et-ses-fichiers");

    const drop = panel.locator("text=/Glissez|Ajouter un fichier/i").first();
    await shotOf(drop, "la-zone-de-depot", 24, 24).catch(() => {});

    await closeDialog().catch(() => {});

    await selectView("Fichiers");
    await shot("la-vue-fichiers");

    // Le bouton n'a pas de texte : deux icônes dans un même cadre, et c'est
    // celle de gauche. Visée par son libellé accessible plutôt que par sa
    // position, qui est ce qui casse quand une troisième forme arrive.
    await page.getByRole("button", { name: "Afficher en cartes" }).first().click();
    await wait(1500);
    await shot("en-cartes");

    // Le panneau : ouvrir un fichier le montre sur place. Repassé en liste
    // d'abord, parce que c'est là que le bouton porte le mot « Ouvrir » - en
    // cartes, c'est la vignette qu'on clique.
    await page.getByRole("button", { name: "Afficher en liste" }).first().click();
    await wait(1200);
    await page.getByRole("button", { name: "Ouvrir", exact: true }).first().click();
    await wait(1500);
    await shot("le-panneau");
    await closeDialog().catch(() => {});

    // Pas de photo du dossier que l'espace s'ouvre dans la médiathèque, et
    // c'est une absence choisie : la démo rattache des documents existants au
    // lieu d'en déposer, donc aucun espace n'a de dossier et la photo
    // montrerait une médiathèque qui contredit la page. Elle reviendra le jour
    // où les fixtures déposent un fichier par un espace.
  },

  /**
   * La discussion d'un espace, côté studio.
   *
   * Le jeu de démonstration porte un échange sur deux jours, ce qui est la
   * seule façon de photographier la ligne de séparation : une conversation
   * écrite dans la même après-midi n'en montre aucune.
   */
  "la-discussion": async () => {
    await openFirstSpace();
    await selectView("Discussion");
    await wait(1500);
    await shot("la-discussion");

    // La zone de saisie et sa phrase d'avertissement : c'est elle qui dit que
    // le client lit ce qui est écrit là, et c'est le seul endroit où elle
    // apparaît.
    await shotOf(page.locator("textarea").first(), "ce-que-le-client-lit", 20, 40).catch(() => {});
  },

  /**
   * Les notes d'un espace : le mur, la liste, l'éditeur.
   *
   * Le jeu de démonstration en pose trois, dont une épinglée et deux colorées,
   * parce qu'un mur d'une seule note ne montre ni l'ordre ni la couleur.
   */
  "les-notes": async () => {
    await openFirstSpace();
    await selectView("Notes");
    await wait(1500);
    await shot("le-mur");

    // L'autre onglet, et le retour : le choix est retenu d'une visite à
    // l'autre, donc une prise de vue qui le laisse sur « Personnelles »
    // décide de ce que photographieront les flux suivants.
    await page.getByRole("button", { name: /^Personnelles/ }).first().click();
    await wait(1200);
    await shot("les-personnelles");
    await page.getByRole("button", { name: /^Partagées/ }).first().click();
    await wait(1000);

    await page.getByRole("button", { name: "Liste", exact: true }).last().click();
    await wait(1200);
    await shot("en-liste");

    await page.getByRole("button", { name: "Mur", exact: true }).first().click();
    await wait(800);

    await page.getByRole("button", { name: "Nouvelle note" }).first().click();
    await wait(1500);
    await shot("l-editeur");
    await page.getByRole("button", { name: "Annuler" }).first().click().catch(() => {});
    await wait(600);
  },

  "le-calendrier": async () => {
    await openFirstSpace();
    await selectView("Calendrier");
    await shot("le-mois");

    await shotOf(page.locator("aside").first(), "sans-date", 16, 12);
  },

  /**
   * L'accès client : créer un lien, l'adresse une seule fois, ce que le
   * client voit, ce qu'il répond, et le fil.
   *
   * Le lien est créé par l'écran puis suivi pour de bon, dans un onglet sans
   * session : c'est la seule façon de photographier ce que le client voit,
   * et une capture prise en étant connecté ne prouverait rien.
   */
  "l-acces-client": async () => {
    await openFirstSpace();
    await page.getByRole("link", { name: "Accès client" }).first().click();
    await wait(2500);
    await shot("l-onglet");

    await page.getByRole("button", { name: "Créer un lien" }).first().click();
    await wait(900);

    const dialog = page.getByRole("dialog").first();
    await dialog.locator("input[type='email']").first().fill("camille@boulangerie-martin.fr");
    await dialog.getByRole("textbox").nth(1).fill("Camille, gérante");
    await wait(400);
    await shot("la-fenetre");

    await dialog.getByRole("button", { name: "Créer un lien" }).first().click();
    await wait(2500);
    await shot("l-adresse");

    const url = (await page.locator("code").first().innerText()).trim();

    // Un contexte neuf, sans cookie : le client n'a pas de compte, et c'est
    // le sujet de la page.
    const guest = await browser.newContext({
      viewport: VIEWPORT, deviceScaleFactor: 1, locale: "fr-FR",
      timezoneId: "Europe/Paris", colorScheme: "dark",
    });
    const studio = page;
    page = await guest.newPage();

    await page.goto(url, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-page-client");

    await page.locator("[data-day] >> text=/^\\d{2}:\\d{2}/").first().click().catch(async () => {
      await page.locator("[data-day]").locator("button, [role='button']").first().click();
    });
    await wait(1500);
    await shot("la-reponse");

    await guest.close();
    page = studio;

    // Le fil, côté studio, une fois que le client a répondu : c'est l'écran que
    // la page décrit, et il n'existe qu'après l'aller-retour.
    await openFirstSpace();
    // Par la forme liste : un titre s'y ouvre d'un clic, là où une carte du
    // kanban demande de passer par son menu.
    await selectShape("Liste");
    await page.getByRole("button", { name: "Offre de rentrée" }).first().click();
    await wait(1400);
    await page.mouse.wheel(0, 600);
    await wait(800);
    await shot("les-echanges");

    await closeDialog();

    await page.getByRole("link", { name: "Accès client" }).first().click();
    await wait(2500);
    await shot("la-liste-des-liens");
  },

  /**
   * Les deux réglages du collage d'images dans une note.
   */
  "images-dans-une-note": async () => {
    await page.goto(`${BASE}/backend/configuration/settings/notes`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("les-deux-reglages");
  },

  /**
   * Les cinq écrans de la rubrique Général, repris ensemble.
   */
  "tableau-de-bord": async () => {
    await page.goto(`${BASE}/backend`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("le-tableau-de-bord");
  },

  /**
   * La recherche globale : ouvrir, taper, lire les groupes.
   */
  "recherche-globale": async () => {
    await page.goto(`${BASE}/backend`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    await page.getByRole("button", { name: /Rechercher/ }).first().click();
    await wait(1500);
    await shot("la-palette-vide");

    await page.keyboard.type("contrat", { delay: 60 });
    await wait(2500);
    await shot("les-resultats-groupes");

    await page.keyboard.press("ArrowDown");
    await page.keyboard.press("ArrowDown");
    await wait(900);
    await shot("se-deplacer-au-clavier");
  },

  /**
   * La cloche, et ce qu'elle contient.
   */
  "notifications": async () => {
    await page.goto(`${BASE}/backend`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    await page.getByRole("button", { name: /Notifications/ }).first().click();
    await wait(2000);
    await shot("la-cloche-ouverte");
  },

  /**
   * Mon profil.
   */
  "mon-profil": async () => {
    await page.goto(`${BASE}/backend/general/profile`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("mon-profil");
  },

  /**
   * Régler son menu latéral, compte par compte.
   */
  "menu-lateral-personnel": async () => {
    await page.goto(`${BASE}/backend/general/profile/sidemenu`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("l-ecran-de-reglage");
  },

  /**
   * Le fond d'une zone, dans le panneau de la zone choisie.
   *
   * La page qui l'explique montrait la grille et promettait « ses réglages de
   * surface » : ils n'étaient pas dans le cadre.
   */
  "fond-de-zone": async () => {
    const post = process.env.DOC_POST_ID ?? "1";

    await page.goto(`${BASE}/backend/editorial/posts/${post}/edit`, { waitUntil: "domcontentloaded" });
    await wait(4000);
    await page.getByRole("tab", { name: /^Contenu$/ }).first().click()
      .catch(async () => { await page.getByRole("button", { name: /^Contenu$/ }).first().click(); });
    await wait(2500);

    // Sur la vignette de la zone : le sélecteur générique ne visait rien, et
    // le panneau restait sur « Cliquez une zone ci-dessus ».
    await page.getByText("Vidéo", { exact: true }).first().click();
    await wait(2000);

    const control = page.getByText("Fond", { exact: true }).first()
      .locator('xpath=ancestor::div[contains(@class,"space-y-1.5")][1]');
    await control.scrollIntoViewIfNeeded();
    await wait(900);
    await shotOf(control, "le-fond-d-une-zone", 16, 12);
  },

  /**
   * L'onglet anti-robots, dans les réglages.
   */
  "captcha-reglages": async () => {
    await page.goto(`${BASE}/backend/configuration/settings/captcha`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("l-onglet-anti-robots");
  },

  /**
   * Les champs personnalisés d'un type de contenu.
   */
  "champs-personnalises": async () => {
    await page.goto(`${BASE}/backend/editorial/post-types`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-liste-des-types");

    // Les champs vivent dans l'écran du type, pas derrière un menu d'actions.
    const block = page.locator("div")
      .filter({ hasText: "Champs personnalisés" })
      .filter({ has: page.getByText("Mettre en avant") })
      .last();
    await block.scrollIntoViewIfNeeded();
    await wait(800);
    await shotOf(block, "les-champs-d-un-type", 16, 12);

    await page.getByRole("button", { name: "Ajouter un champ" }).first().click();
    await wait(2000);
    await shot("ajouter-un-champ");
  },

  /**
   * Une entrée de menu : sa cible, et sa visibilité.
   */
  "entree-de-menu": async () => {
    await page.goto(`${BASE}/backend/editorial/menus`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    // Le menu de la navigation publique plutôt que celui des liens de compte :
    // l'écran s'ouvre sur le premier, qui n'a que deux entrées spéciales et
    // ne montre aucune des cibles que la page explique.
    await page.getByRole("link", { name: /Navigation principale/ }).first().click()
      .catch(async () => { await page.getByText("Navigation principale").first().click(); });
    await wait(2500);
    await shot("un-menu-et-ses-entrees");

    await page.getByRole("button", { name: "Ajouter une entrée" }).first().click();
    await wait(2500);
    await shot("la-cible-d-une-entree");
  },

  /**
   * Un onglet de réglages par page qui en parle.
   *
   * Refaites toutes ensemble : les six captures dataient d'avant plusieurs
   * changements d'écran, et une capture de réglages périmée montre un champ
   * que le lecteur ne trouvera pas.
   */
  /**
   * L'onglet de stockage, vierge.
   *
   * Les deux premiers champs sont vidés avant la prise, et ce n'est pas
   * cosmétique. L'écran affiche la configuration réellement en vigueur, y
   * compris celle qui vient de l'environnement du serveur : sur la machine
   * d'un développeur qui a branché un vrai compartiment, la capture
   * emporterait son identifiant de compte et le nom de son compartiment vers
   * un dépôt public et une page que n'importe qui peut ouvrir.
   *
   * Vidé côté navigateur seulement : rien n'est enregistré, la configuration
   * de la machine n'est pas touchée.
   */
  "stockage-des-fichiers": async () => {
    await page.goto(`${BASE}/backend/configuration/settings/storage`, { waitUntil: "domcontentloaded" });
    await wait(1500);

    for (const label of ["Adresse du compte", "Compartiment"]) {
      const field = page.locator(`label:has-text("${label}")`).locator("xpath=following::input[1]");
      if (await field.count()) {
        await field.first().fill("");
      }
    }

    await wait(300);
    await shot("onglet");
  },

  "onglets-de-reglages": async () => {
    const tabs = [
      ["general", "general"],
      ["localization", "localisation"],
      ["reading", "lecture"],
      ["branding", "branding"],
      ["seo", "seo"],
      ["email", "emails"],
      ["system", "systeme"],
    ];

    for (const [group, name] of tabs) {
      await page.goto(`${BASE}/backend/configuration/settings/${group}`, { waitUntil: "domcontentloaded" });
      await wait(3000);
      await shot(name);
    }
  },

  /**
   * Les thèmes : la liste, puis l'éditeur d'un thème.
   */
  "themes": async () => {
    await page.goto(`${BASE}/backend/configuration/themes`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-liste-des-themes");

    // « Activer » garde son bouton, le reste est dans la feuille de la carte.
    await page.getByRole("button", { name: /^Actions pour/ }).first().click();
    await wait(1200);
    await page.getByRole("button", { name: /^Modifier/ }).first().click();
    await wait(2500);
    await shot("l-editeur-d-un-theme");
  },

  /**
   * Renommer et réordonner le menu latéral.
   */
  "menu-lateral": async () => {
    await page.goto(`${BASE}/backend/configuration/settings/navigation`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("l-onglet-navigation");
  },

  /**
   * La palette du sélecteur de couleur.
   */
  "palette-du-selecteur": async () => {
    await page.goto(`${BASE}/backend/configuration/settings/appearance`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("l-onglet-apparence");
  },

  /**
   * La page que reçoit le client, sans compte.
   *
   * L'adresse est passée en paramètre : le jeton n'existe en clair que dans
   * l'e-mail, et la base n'en garde qu'une empreinte. `mint-link.php` en
   * refrappe un en local pour cette prise.
   */
  "signature-cote-client": async () => {
    const path = process.env.DOC_CONTRACT_PATH;

    if (!path) {
      throw new Error("DOC_CONTRACT_PATH manquant : refrapper un lien avec mint-link.php");
    }

    // Un contexte neuf, sans session : c'est tout l'intérêt de la page.
    const guest = await page.context().browser().newContext({
      viewport: VIEWPORT, locale: "fr-FR", timezoneId: "Europe/Paris", colorScheme: "dark",
    });
    const visitor = await guest.newPage();
    const held = page;
    page = visitor;

    await page.goto(`${BASE}${path}`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-page-recue-par-le-client");

    await page.mouse.wheel(0, 2200);
    await wait(900);
    await shot("le-document-et-la-mention-d-information");

    await page.mouse.wheel(0, 6000);
    await wait(900);
    await shot("le-formulaire-de-signature");

    // Le bouton reste éteint tant que l'identité n'est pas là et que le cadre
    // n'est pas signé : c'est la garde du produit, et la photo doit la
    // montrer remplie plutôt que contournée.
    await page.getByPlaceholder("Camille", { exact: true }).first().fill("Marie");
    await page.getByPlaceholder("Durand", { exact: true }).first().fill("Dupont");
    await page.getByPlaceholder("camille@societe.fr").first().fill("marie.dupont@atelier-dupont.test");
    await page.getByPlaceholder("Lyon").first().fill("Pont-de-Chéruy");
    await wait(500);

    // Un trait dans le cadre de signature, à la souris.
    const pad = page.locator("canvas").first();
    const box = await pad.boundingBox();

    if (box) {
      await page.mouse.move(box.x + 60, box.y + box.height * 0.65);
      await page.mouse.down();
      await page.mouse.move(box.x + 140, box.y + box.height * 0.3, { steps: 12 });
      await page.mouse.move(box.x + 220, box.y + box.height * 0.7, { steps: 12 });
      await page.mouse.move(box.x + 300, box.y + box.height * 0.35, { steps: 12 });
      await page.mouse.up();
      await wait(700);
    }

    await shot("l-identite-et-la-signature");

    await page.getByRole("button", { name: /Recevoir le code/i }).first().click();
    await wait(3000);
    await shot("le-code-demande");

    const code = await lastCodeFromMailbox();
    await page.getByPlaceholder("123456").first().fill(code);
    await page.getByRole("checkbox").first().check();
    // Le bouton reste éteint tant que le contrat n'a pas été déroulé jusqu'en
    // bas : la page pose une sentinelle après le document et attend qu'elle
    // entre dans la vue. La molette ne suffit pas, elle s'arrête au premier
    // conteneur qui défile ; on amène la sentinelle elle-même à l'écran.
    await page.evaluate((selector) => {
      const article = document.querySelector(selector);
      article?.nextElementSibling?.scrollIntoView({ block: "center" });
    }, ".contract-document");
    await wait(1200);
    await shot("pret-a-signer");

    await page.getByRole("button", { name: /Signer le contrat/i }).first().click();
    await wait(4000);
    await shot("le-contrat-signe");

    page = held;
    await guest.close();
  },

  /**
   * Envoyer, signer, contresigner : une seule séquence, trois pages.
   *
   * Les trois se suivent sur le même contrat et ne se rejouent pas séparément
   * - on ne signe pas un contrat qu'on n'a pas envoyé. Les photos portent le
   * nom de la page à laquelle elles vont.
   */
  "le-parcours-de-signature": async () => {
    await createDraft();
    await page.getByRole("button", { name: /^Actions pour/ }).first().click();
    await wait(1200);
    await page.getByText("Sceller", { exact: true }).first().click();
    await wait(1500);
    await page.getByRole("button", { name: /^(Sceller|Confirmer)/ }).last().click();
    await wait(3500);

    // envoyer-le-lien
    await page.goto(`${BASE}/backend/studio/contracts`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    const reference = await page.locator("text=/CTR-\\d{4}-\\d{4}/").first().innerText();
    flowName = "envoyer-le-lien";
    step = 0;
    await page.getByRole("button", { name: new RegExp(`^Actions pour ${reference}`) }).first().click();
    await wait(1200);
    await shot("le-menu-d-un-contrat-scelle");

    await page.getByText("Envoyer au client", { exact: true }).first().click();
    await wait(1800);
    await shot("la-confirmation-d-envoi");

    await page.getByRole("button", { name: /^(Envoyer|Confirmer)/ }).last().click();
    await wait(3500);
    await shot("le-lien-actif-dans-la-liste");

    console.log(`  (contrat ${reference})`);
  },

  /**
   * Avenant, résiliation, PDF : ce qui reste possible sur un contrat conclu.
   *
   * Les trois boutons n'apparaissent que là, et seulement sur un contrat qui
   * n'est pas lui-même un avenant et qui n'est pas déjà résilié : ce sont les
   * deux seules choses qui peuvent encore lui arriver.
   */
  "apres-la-conclusion": async () => {
    const id = process.env.DOC_CONTRACT_ID;

    if (!id) {
      throw new Error("DOC_CONTRACT_ID manquant");
    }

    await page.goto(`${BASE}/backend/studio/contracts/${id}`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    flowName = "pdf-signe";
    step = 0;
    await shot("le-bloc-de-preuve-d-un-contrat-conclu");

    flowName = "avenants";
    step = 0;
    await shot("les-deux-actions-d-un-contrat-conclu");

    await page.getByRole("link", { name: /Créer un avenant|Avenant/ }).first().click();
    await page.waitForURL(/amends=/, { timeout: 20000 });
    await wait(3000);
    await shot("la-preparation-d-un-avenant");

    flowName = "resiliation";
    step = 0;
    await page.goto(`${BASE}/backend/studio/contracts/${id}`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("les-actions-d-un-contrat-conclu");

    await page.getByRole("button", { name: /^Résilier$/ }).first().click();
    // La fenêtre s'ouvre en fondu : photographiée trop tôt, la page paraît
    // n'avoir rien fait.
    await page.getByRole("heading", { name: /Résilier/ }).last().waitFor({ state: "visible", timeout: 15000 });
    await wait(1500);
    await shot("la-fenetre-de-resiliation");
  },

  /** Les écrans de structure : types de contenu, taxonomies, menus. */
  "structure-editoriale": async () => {
    flowName = "types-de-contenu";
    step = 0;
    await page.goto(`${BASE}/backend/editorial/post-types`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-liste-des-types");

    await page.mouse.wheel(0, 900);
    await wait(1000);
    await shot("les-reglages-d-un-type");

    flowName = "champs-personnalises";
    step = 0;
    await page.mouse.wheel(0, 1200);
    await wait(1000);
    await shot("les-champs-d-un-type");

    flowName = "taxonomies";
    step = 0;
    await page.goto(`${BASE}/backend/editorial/taxonomies`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-liste-des-taxonomies");

    await page.mouse.wheel(0, 900);
    await wait(1000);
    await shot("les-termes-d-une-taxonomie");

    flowName = "menus";
    step = 0;
    await page.goto(`${BASE}/backend/editorial/menus`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("un-menu-et-ses-entrees");

    await page.mouse.wheel(0, 900);
    await wait(1000);
    await shot("le-bas-de-l-ecran-des-menus");

    flowName = "moderation-des-commentaires";
    step = 0;
    await page.goto(`${BASE}/backend/editorial/comments`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("les-commentaires-a-moderer");

    flowName = "captcha";
    step = 0;
    await page.goto(`${BASE}/backend/editorial/captcha/settings`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("les-reglages-du-captcha");
  },

  /**
   * Les cinq autres onglets de l'éditeur, deux photos chacun.
   *
   * Un onglet se lit en deux temps : ce qu'on voit en arrivant, et ce qui est
   * plus bas. Les deux méritent leur image, parce que le bas d'un onglet est
   * précisément ce qu'on ne découvre jamais.
   */
  "onglets-de-l-editeur": async () => {
    const post = process.env.DOC_POST_ID ?? "1";
    const onglets = [
      ["onglet-parametrage", /^Paramétrage$/],
      ["onglet-en-tete", /^En-tête$/],
      ["onglet-apparence", /^Apparence$/],
      ["onglet-galerie", /^Galerie$/],
      ["onglet-moteurs-de-recherche", /^Moteurs de recherche$/],
    ];

    for (const [name, label] of onglets) {
      flowName = name;
      step = 0;

      await page.goto(`${BASE}/backend/editorial/posts/${post}/edit`, { waitUntil: "domcontentloaded" });
      await wait(3500);
      await page.getByRole("tab", { name: label }).first().click()
        .catch(async () => { await page.getByRole("button", { name: label }).first().click(); });
      await wait(2500);
      await shot("le-haut-de-l-onglet");

      await page.mouse.wheel(0, 1400);
      await wait(1200);
      await shot("le-bas-de-l-onglet");
    }
  },

  /** La liste des publications : filtres, recherche, actions en masse, corbeille. */
  "liste-des-publications": async () => {
    await page.goto(`${BASE}/backend/editorial/posts`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-liste-et-ses-filtres");

    await page.getByPlaceholder(/Chercher un titre/).first().fill("premier");
    await wait(1800);
    await shot("la-recherche");

    await page.getByPlaceholder(/Chercher un titre/).first().fill("");
    await wait(1500);

    // Cocher une ligne fait apparaître la barre d'actions en masse. Les verbes
    // sont derrière son bouton « Actions » : la feuille est ouverte pour la
    // photo, sans quoi l'image d'une page qui parle d'actions en masse ne
    // montrerait qu'un bouton fermé.
    await page.getByRole("row").nth(1).getByRole("checkbox").first().check();
    await wait(1200);
    await page.getByRole("button", { name: "Actions", exact: true }).first().click();
    await wait(1000);
    await shot("les-actions-en-masse");

    // Attendue détachée, pas seulement demandée : la modale garde le défilement
    // de la page le temps de sa transition, et le clic suivant visait le menu
    // latéral pendant que le verrou courait encore.
    await page.keyboard.press("Escape");
    await page.locator("[role='dialog']").first().waitFor({ state: "detached" }).catch(() => {});
    await wait(800);
    await page.getByRole("row").nth(1).getByRole("checkbox").first().uncheck();
    await wait(800);
    await page.getByRole("button", { name: /^Corbeille$/ }).first().click();
    await wait(1800);
    await shot("la-corbeille");
  },

  /** L'onglet Contenu : la grille, une zone choisie, la palette de zones. */
  "onglet-contenu": async () => {
    const post = process.env.DOC_POST_ID ?? "1";

    await page.goto(`${BASE}/backend/editorial/posts/${post}/edit`, { waitUntil: "domcontentloaded" });
    await wait(4000);
    // Les onglets de l'éditeur ne portent pas tous le rôle « tab » : on
    // retombe sur le bouton, comme le helper `tab()` plus bas.
    await page.getByRole("tab", { name: /^Contenu$/ }).first().click()
      .catch(async () => { await page.getByRole("button", { name: /^Contenu$/ }).first().click(); });
    await wait(2500);
    await shot("la-grille-de-contenu");

    // Une zone choisie : ses réglages s'ouvrent sous la grille.
    await page.locator("[data-grid-zone], .grid-zone").first().click().catch(() => {});
    await wait(1500);
    await shot("une-zone-choisie");

    await page.mouse.wheel(0, 1200);
    await wait(1000);
    await shot("la-palette-de-zones");
  },

  /** L'identité du prestataire : les douze réglages que provider.* lit. */
  "identite-du-prestataire": async () => {
    await page.goto(`${BASE}/backend/configuration/settings/studio`, { waitUntil: "domcontentloaded" });
    await wait(2800);
    await shot("l-onglet-studio-des-reglages");

    await page.mouse.wheel(0, 900);
    await wait(900);
    await shot("l-identite-et-les-coordonnees");

    await page.mouse.wheel(0, 1400);
    await wait(900);
    await shot("les-coordonnees-bancaires-et-les-relances");
  },

  /** Les relances : le réglage, le délai, le plafond. */
  "relances": async () => {
    await page.goto(`${BASE}/backend/configuration/settings/studio`, { waitUntil: "domcontentloaded" });
    await wait(2800);
    await page.mouse.wheel(0, 2400);
    await wait(1000);
    await shot("les-reglages-de-relance");

    await page.goto(`${BASE}/backend/studio/contracts`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    const row = page.getByRole("row").filter({ hasText: /Envoyé/ }).first();
    await row.getByRole("button", { name: /^Actions pour/ }).click();
    await wait(1200);
    await page.getByText("Lire le document", { exact: true }).first().click();
    await page.waitForURL(/\/contracts\/\d+$/, { timeout: 20000 });
    await wait(2500);
    await shot("le-compteur-de-relances");
  },

  /** Les trames : la liste, le filtre par type, les actions, les versions. */
  "trames-de-contrat": async () => {
    await page.goto(`${BASE}/backend/studio/contract-templates`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-liste");

    await page.getByRole("button", { name: /^Corps de contrat/ }).first().click();
    await wait(1200);
    await shot("le-filtre-par-type");

    await page.getByRole("button", { name: /^Tous/ }).first().click();
    await wait(1000);
    await page.getByRole("button", { name: /^Actions pour/ }).first().click();
    await wait(1200);
    await shot("les-actions-d-une-trame");

    await page.keyboard.press("Escape");
    await wait(800);
    await pageAction("Ajouter une trame");
    await shot("ajouter-une-trame");
  },

  /**
   * Écrire une version : l'éditeur, les jetons, publier ou abandonner.
   *
   * L'éditeur s'ouvre par son adresse, comme celui des publications : la
   * pastille du brouillon dans la liste n'est pas un lien, et l'action
   * « ouvrir un brouillon » crée la version puis rend la main à la liste.
   * Les identifiants viennent des fixtures.
   */
  "ecrire-une-version": async () => {
    const template = process.env.DOC_TEMPLATE_ID ?? "3";
    const version = process.env.DOC_VERSION_ID ?? "5";

    await page.goto(`${BASE}/backend/studio/contract-templates/${template}/versions/${version}`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shot("l-editeur-de-version");

    await page.mouse.wheel(0, 700);
    await wait(1000);
    await shot("le-texte-et-ses-jetons");

    await page.mouse.wheel(0, 2500);
    await wait(1000);
    await shot("le-bas-de-l-editeur");
  },

  /** Contresigner : ce qui conclut le contrat, une fois le client passé. */
  "contresigner": async () => {
    await page.goto(`${BASE}/backend/studio/contracts`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("le-contrat-signe-par-le-client");

    // La contresignature n'est pas dans le menu de la liste, qui n'offre plus
    // que la lecture et la révocation du lien : elle est sur la page du
    // document, là où on relit ce qu'on va conclure.
    const row = page.getByRole("row").filter({ hasText: /par le client/ }).first();
    await row.getByRole("button", { name: /^Actions pour/ }).click();
    await wait(1200);
    await shot("le-menu-de-la-liste");

    await page.getByText("Lire le document", { exact: true }).first().click();
    await page.waitForURL(/\/contracts\/\d+$/, { timeout: 20000 });
    await wait(2500);
    await shot("la-page-du-document");

    await page.getByRole("button", { name: /^Contresigner/ }).first().click();
    await wait(1800);
    await shot("la-fenetre-de-contresignature");

    // Le prestataire signe comme le client : même identité, même cadre. Le
    // bouton reste éteint tant que les deux ne sont pas là.
    await page.getByPlaceholder("Camille", { exact: true }).first().fill("Camille");
    await page.getByPlaceholder("Durand", { exact: true }).first().fill("Vasseur");
    await page.getByPlaceholder("camille@societe.fr").first().fill("contact@studio-aurora.test");
    await page.getByPlaceholder("Lyon").first().fill("Lyon");
    await wait(500);

    const pad = page.locator("canvas").first();
    const box = await pad.boundingBox();

    if (box) {
      await page.mouse.move(box.x + 60, box.y + box.height * 0.6);
      await page.mouse.down();
      await page.mouse.move(box.x + 150, box.y + box.height * 0.3, { steps: 12 });
      await page.mouse.move(box.x + 240, box.y + box.height * 0.7, { steps: 12 });
      await page.mouse.up();
      await wait(700);
    }

    await shot("la-signature-du-prestataire");

    await page.getByRole("button", { name: /^Contresigner$/ }).last().click();
    await wait(4500);
    await shot("le-contrat-conclu");
  },

  /** Sceller : le menu d'actions, la confirmation, la référence frappée. */
  "sceller-un-contrat": async () => {
    await createDraft();
    await shot("le-brouillon-a-sceller");

    await page.getByRole("button", { name: /^Actions pour/ }).first().click();
    await wait(1200);
    await shot("le-menu-d-actions");

    await page.getByText("Sceller", { exact: true }).first().click();
    await wait(1500);
    await shot("la-confirmation");

    await page.getByRole("button", { name: /^(Sceller|Confirmer)/ }).last().click();
    await wait(3500);
    await shot("le-contrat-scelle");
  },

  /** Créer un client, jusqu'au refus du SIRET puis à l'enregistrement. */
  /**
   * La liste des présentations : ouvrir, créer, dupliquer.
   */
  "presentations": async () => {
    await page.goto(`${BASE}/backend/studio/decks`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-liste");

    await pageAction(/Créer une présentation/);
    await page.getByPlaceholder("Audit du site, octobre").first().fill("Revue trimestrielle");
    await page.getByPlaceholder("Une ligne, lue dans la liste.").first().fill("Ce qui a avancé, ce qui reste.");
    await wait(600);
    await shot("la-fenetre-de-creation");

    await page.getByRole("button", { name: /^Annuler/ }).last().click();
    await wait(1200);

    // Le menu d'une ligne, sur la présentation des fixtures : c'est là que se
    // trouve « Dupliquer », qui est le geste que la page explique.
    const row = page.getByRole("row").filter({ hasText: /Audit du site/ }).first();
    await row.getByRole("button", { name: /^Actions pour/ }).click();
    await wait(1200);
    await shot("le-menu-d-une-ligne");
  },

  /**
   * Le bloc commun à tous les gabarits : sur-titre, fond, voile.
   */
  "fond-et-sur-titre": async () => {
    await page.goto(`${BASE}/backend/studio/decks`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await page.getByRole("link", { name: /Audit du site/ }).first().click();
    await wait(3500);

    const block = page.locator("section .border-t.border-line").first();
    await block.scrollIntoViewIfNeeded();
    await wait(800);
    await shotOf(block, "le-bloc-commun", 16, 12);
  },

  /**
   * L'éditeur : la page, les gabarits, le formulaire, les notes, l'ordre.
   */
  "composer-les-slides": async () => {
    await page.goto(`${BASE}/backend/studio/decks`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await page.getByRole("link", { name: /Audit du site/ }).first().click();
    await wait(3500);
    await shot("la-page");

    // `aside:not(#sidemenu)` : le menu latéral est un `aside` lui aussi, et
    // `.first()` comme `.last()` tombent dessus une fois sur deux.
    const column = page.locator("aside:not(#sidemenu)").first();

    const adder = column.locator("div.grid").first();
    await adder.scrollIntoViewIfNeeded();
    await wait(600);
    await shotOf(adder, "ajouter-une-slide", 16, 26);

    // La troisième slide est celle à puces, c'est-à-dire le gabarit que la
    // page décrit en exemple. La deuxième est une intercalaire, qui ne porte
    // qu'un titre et ne montrerait pas le formulaire dont il est question.
    await page.getByRole("button", { name: /3\./ }).first().click();
    await wait(1800);

    // Les notes remplies avant la photo du formulaire, pas après : sinon
    // l'image montre un champ vide au premier passage et rempli au second,
    // selon ce qu'une campagne précédente a laissé en base.
    const notes = page.getByPlaceholder("Ce que vous direz pendant que cette slide est à l'écran.").first();
    await notes.fill("Marquer un temps avant la troisième puce.");
    await wait(800);

    const form = page.locator("section .rounded-xl.border").first();
    await form.scrollIntoViewIfNeeded();
    await wait(800);
    await shotOf(form, "le-formulaire", 16, 12);

    // Les flèches n'apparaissent qu'au survol de la vignette. Marge verticale
    // courte : les vignettes se touchent, et quelques pixels de plus font
    // entrer le haut de la suivante dans le cadre.
    const thumb = column.locator("div.group").first();
    await thumb.scrollIntoViewIfNeeded();
    await thumb.hover();
    await wait(900);
    await shotOf(thumb, "les-actions-d-une-vignette", 24, 6);
  },

  /**
   * La modale d'import, avec l'éditeur de blocs dedans.
   */
  "partir-d-un-document": async () => {
    await page.goto(`${BASE}/backend/studio/decks`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await pageAction("Importer un document");
    await wait(500);

    // Quelques mots dans l'éditeur : une modale vide ne montre pas qu'on y
    // écrit, et c'est la seule chose que cette image a à dire.
    await page.locator(".codex-editor__redactor [contenteditable='true']").first().click();
    await page.keyboard.type("Ce qui bloque aujourd'hui");
    await wait(900);
    await shot("la-modale");
  },

  /**
   * Le panneau d'apparence : la grille des thèmes, et l'aperçu qui suit.
   */
  "l-apparence": async () => {
    await page.goto(`${BASE}/backend/studio/decks`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await page.getByRole("link", { name: /Audit du site/ }).first().click();
    await wait(3500);

    await pageAction("Apparence");
    await shot("le-panneau");

    // Un thème clair sur une capture faite dans un back-office sombre : c'est
    // exactement ce que le panneau sert à montrer, et ce qu'une liste de noms
    // ne dirait pas.
    await page.getByRole("button", { name: /^Papier$/ }).first().click();
    await wait(1200);
    await shot("un-theme-clair");
  },

  /**
   * Le plein écran, la vue présentateur, puis la page d'impression.
   */
  "presenter-un-deck": async () => {
    await page.goto(`${BASE}/backend/studio/decks`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    const href = await page.getByRole("link", { name: /Audit du site/ }).first().getAttribute("href");

    await page.goto(`${BASE}${href}`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await page.getByRole("button", { name: /^Présenter/ }).first().click();
    await wait(2000);
    await shot("le-plein-ecran");

    // La quatrième slide est l'image pleine page. Photographiée en
    // présentation et non dans l'éditeur : c'est le seul endroit où ce
    // gabarit occupe l'écran, ce qui est tout ce qu'il fait.
    await page.keyboard.press("ArrowRight");
    await page.keyboard.press("ArrowRight");
    await page.keyboard.press("ArrowRight");
    await wait(1800);
    await shot("une-image-pleine-page");

    await page.keyboard.press("Escape");
    await wait(1200);

    // La page d'impression sans `?print=1` : le dialogue d'impression du
    // navigateur n'est pas photographiable, et la page l'est.
    await page.goto(`${BASE}${href}/print`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-page-d-impression");
  },

  /**
   * Le partage : la fenêtre, un lien créé, la page vue par le destinataire.
   */
  "partager-un-deck": async () => {
    await page.goto(`${BASE}/backend/studio/decks`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await page.getByRole("link", { name: /Audit du site/ }).first().click();
    await wait(3000);

    await pageAction("Partager");
    await page.getByPlaceholder("Envoyé à Marie, le 12 septembre").first().fill("Envoyé à Marie Dupont");
    await wait(600);
    await shot("la-fenetre");

    await page.getByRole("button", { name: /^Créer le lien/ }).first().click();
    await wait(2500);
    await shotOf(page.locator("[role='dialog'] li").first(), "un-lien-dans-la-liste", 20, 12);

    const url = await page.locator("[role='dialog'] .font-mono").first().innerText();

    await page.goto(url.trim(), { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-page-publique");
  },

  "fiche-client": async () => {
    await page.goto(`${BASE}/backend/studio/customers`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-liste");

    await pageAction("Ajouter un client");
    await shot("la-fenetre-vide");

    // `getByPlaceholder` et pas un sélecteur CSS : plusieurs de ces textes
    // portent une apostrophe, qui casse le sélecteur.
    const byPlaceholder = (ph) => page.getByPlaceholder(ph).first();

    await byPlaceholder("Nom de la société").fill("Menuiserie Lambert");
    await byPlaceholder("SARL, SAS, EI, association…").fill("SARL");
    await byPlaceholder("Restauration, immobilier, artisanat…").fill("Artisanat du bois");
    await byPlaceholder("10000").fill("15000");
    await byPlaceholder("Adresse complète, telle qu'elle apparaîtra au contrat").fill("14 rue des Ateliers, 69003 Lyon");
    await wait(700);
    await shot("l-identite-legale");

    // Une clé de Luhn fausse, exprès : le refus est ce qu'un lecteur vient
    // chercher, et il ne se photographie pas en décrivant un écran valide.
    await byPlaceholder("123 456 789 00012").fill("123 456 789 00013");
    await byPlaceholder("Lyon B 123 456 789").fill("Lyon B 123 456 789");
    await byPlaceholder("FR12345678901").fill("FR69123456789");
    await wait(600);
    await shot("les-identifiants");

    await byPlaceholder("Camille").fill("Claire");
    await byPlaceholder("Durand").fill("Lambert");
    await byPlaceholder("Gérant, Président, Directrice…").fill("Gérante");
    await byPlaceholder("contact@societe.fr").fill("contact@menuiserie-lambert.test");
    await byPlaceholder("06 12 34 56 78").fill("04 78 00 00 00");
    await wait(700);
    await shot("le-representant");

    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(2200);
    await shot("le-siret-refuse");

    await byPlaceholder("123 456 789 00012").fill("123 456 789 00012");
    await wait(600);
    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(3000);
    await shot("le-client-dans-la-liste");
  },

  /** Préparer un contrat, de la liste au brouillon enregistré. */
  "preparer-un-contrat": async () => {
    await page.goto(`${BASE}/backend/studio/contracts`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-liste");

    await pageAction("Préparer un contrat");
    await shot("la-fenetre-vide");

    const selects = page.locator("select");
    // L'ordre des listes dans la fenêtre : avenant, client, corps, annexe,
    // devise, langue. Visé par rang plutôt que par libellé, parce que les
    // libellés portent leur astérisque d'obligation.
    await selects.nth(1).selectOption({ index: 1 });
    await wait(900);
    await shot("le-client");

    // Le corps par son libellé, pas par son rang : la liste des trames
    // grandit, et le rang 1 est devenu « Avenant » le jour où la trame
    // d'avenant a été publiée. Une capture qui montre un avenant sur la page
    // « préparer un contrat » enseigne le contraire de ce qu'elle dit.
    const bodyOption = await selects.nth(2).locator("option").filter({ hasText: /prestation mensuelle/i }).first().getAttribute("value");
    await selects.nth(2).selectOption(bodyOption);
    await wait(900);
    await selects.nth(3).selectOption({ index: 1 });
    await wait(900);
    await shot("le-corps-et-l-annexe");

    await page.locator("input[placeholder='850']").fill("690");
    await wait(600);
    await shot("le-montant");

    // Le calendrier ouvert, plutôt que la date déjà saisie : c'est le geste
    // que fait le lecteur, et la photo d'un champ rempli ne le montre pas.
    await page.locator("input[placeholder='2026-10-01']").click();
    await wait(900);
    await shot("le-calendrier");

    // La date se choisit dans le calendrier, pas au clavier : le champ est
    // piloté par le composant, une saisie tapée est reprise à la fermeture et
    // le contrat partait sans date de prise d'effet.
    await page.getByText("15", { exact: true }).first().click();
    await wait(900);

    // Les blancs que la trame réclame. Ils n'existent que si la trame en
    // déclare, d'où la garde : une capture d'une section absente serait une
    // capture de rien.
    const extras = page.locator("input[placeholder^='Par exemple']");

    if (await extras.count() > 0) {
        await extras.nth(0).fill("12 mois");
        await extras.nth(1).fill("Formule Suivi");
        await extras.nth(0).scrollIntoViewIfNeeded();
        await wait(700);
        await shot("les-complements-de-la-trame");
    }

    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(3000);
    await shot("en-preparation");
  },

  /**
   * Construire un formulaire, du bouton au formulaire vide.
   *
   * Le formulaire est vraiment créé, puis supprimé à la fin : une fenêtre
   * photographiée avant d'être annulée ne montre pas ce que donne
   * « Enregistrer », et c'est l'écran suivant que le lecteur cherche.
   */
  "construire-un-formulaire": async () => {
    await page.goto(`${BASE}/backend/editorial/forms`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("un-formulaire-et-ses-champs");

    await pageAction("Nouveau formulaire");
    await shot("la-fenetre-de-creation");

    // Les trois langues sont empilées dans la même fenêtre : le premier
    // « Un titre court » est le français.
    await page.locator("input[placeholder='Un titre court']").first().fill("Rappel téléphonique");
    await page.locator("textarea[placeholder='Une phrase pour situer.']").first()
      .fill("Laissez un numéro, nous rappelons dans la journée.");
    await wait(700);
    await shot("le-titre-et-la-description");

    await page.locator("input[placeholder='prenom.nom@exemple.fr']").first().fill("contact@exemple.fr");
    await wait(600);
    await shot("l-adresse-qui-recoit-les-reponses");

    // Les étapes sont en bas de la fenêtre, après les trois langues.
    const addStep = page.getByRole("button", { name: "Ajouter une étape" }).first();
    await addStep.click();
    await wait(500);
    await addStep.click();
    await wait(600);
    const stepNames = page.locator("input[placeholder=\"Nom de l'étape\"]");
    await stepNames.nth(0).fill("Votre numéro");
    await stepNames.nth(1).fill("Quand vous joindre");
    await stepNames.nth(1).scrollIntoViewIfNeeded();
    await wait(700);
    await shot("les-etapes-du-formulaire");

    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(3000);
    await shot("un-formulaire-neuf-sans-champ");

    // Ménage : la démo repart avec un seul formulaire, celui des fixtures.
    // Supprimer est dans la feuille de la carte, avec Modifier.
    await page.getByRole("button", { name: /^Actions pour/ }).first().click();
    await wait(1200);
    await page.getByRole("button", { name: "Supprimer" }).first().click();
    await wait(1400);
    await page.getByRole("button", { name: /^(Supprimer|Confirmer)$/ }).last().click();
    await wait(2500);
  },

  /**
   * Les neuf types de champ, dans le constructeur et sur le site.
   *
   * Deux moitiés qui ne se remplacent pas : la liste dit ce qu'on choisit,
   * la page publique dit ce que ça donne.
   */
  "types-de-champ": async () => {
    await page.goto(`${BASE}/backend/editorial/forms/1`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    // Cadré sur le bloc « Champs » : la page parle des types, et le type est
    // la deuxième ligne de chaque champ. Le reste de l'écran est ailleurs.
    await shotOf(page.getByRole("heading", { name: "Champs" })
      .locator("xpath=ancestor::div[contains(@class,'rounded-xl')][1]"), "le-type-de-chaque-champ", 10, 10);

    await page.getByRole("button", { name: "Ajouter un champ" }).first().click();
    await wait(1600);
    await shot("la-fenetre-d-un-champ");

    // Le choix du type change la fenêtre : une liste déroulante réclame ses
    // options, et le bloc n'existe pas avant.
    const type = page.locator("[role='dialog'] select").first();
    await type.selectOption({ label: "Liste déroulante" });
    await wait(900);
    await shot("un-champ-a-choix-reclame-ses-options");

    await page.getByRole("button", { name: "Annuler" }).first().click();
    await wait(1200);

    await page.goto(`${BASE}/fr/forms/demande-de-devis`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("les-champs-vus-par-le-visiteur");

    await page.getByPlaceholder("Camille Durand").first().fill("Camille Durand");
    await page.getByPlaceholder("camille@exemple.fr").first().fill("camille.durand@exemple.fr");
    await wait(700);
    await page.getByRole("button", { name: "Suivant" }).first().click();
    await wait(1500);
    // Cadré sur le formulaire seul : ce qui compte ici est la forme des
    // champs, pas l'en-tête du site autour.
    await shotOf(page.locator("form").first(), "la-suite-du-formulaire", 24, 20);
  },

  /**
   * Un champ qui n'apparaît que si la réponse l'appelle.
   *
   * Le parcours va jusqu'au bout côté visiteur : la condition ne se voit pas
   * dans le constructeur, elle se voit quand le champ arrive.
   */
  "champs-conditionnels": async () => {
    await page.goto(`${BASE}/backend/editorial/forms/1`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    // Cadré sur la ligne : la mention « Conditions d'affichage » est ce qui
    // distingue ce champ des huit autres, et elle tient en trois mots.
    const conditioned = page.getByText("Nombre de références au catalogue").first();
    await conditioned.scrollIntoViewIfNeeded();
    await wait(800);
    await shotOf(conditioned.locator("xpath=ancestor::div[contains(@class,'justify-between')][1]"),
      "le-champ-porte-une-mention", 22, 2);

    // Le menu de la ligne, puis « Modifier » : la condition se lit dans la
    // fenêtre du champ, nulle part ailleurs.
    await page.getByRole("button", { name: /Actions pour/ }).nth(5).click();
    await wait(1000);
    await shot("le-menu-de-la-ligne");

    await page.getByRole("button", { name: /^Modifier/ }).last().click();
    await wait(1600);
    // Le bouton, pas le texte « Conditions d'affichage » : ce texte est aussi
    // la mention portée par la ligne du champ, derrière la fenêtre, et
    // `.first()` tombait dessus - déjà visible, donc aucun défilement, et une
    // photo du haut de la fenêtre légendée « la condition ».
    await page.getByRole("button", { name: "Ajouter une condition" }).scrollIntoViewIfNeeded();
    await wait(900);
    await shot("la-condition-du-champ");

    await page.getByRole("button", { name: "Annuler" }).first().click();
    await wait(1200);

    await page.goto(`${BASE}/fr/forms/demande-de-devis`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await page.getByPlaceholder("Camille Durand").first().fill("Camille Durand");
    await page.getByPlaceholder("camille@exemple.fr").first().fill("camille.durand@exemple.fr");
    await page.getByRole("button", { name: "Suivant" }).first().click();
    await wait(1500);
    await shot("sans-la-reponse-le-champ-est-absent");

    await page.locator("form select").first().selectOption({ label: "Boutique en ligne" });
    await wait(1200);
    await shot("la-reponse-fait-apparaitre-le-champ");
  },

  /**
   * Ce que devient une demande une fois envoyée.
   *
   * Trois endroits, dans l'ordre où ils comptent : la page publique qui
   * confirme, la liste des soumissions, et l'e-mail reçu.
   */
  "demandes-recues": async () => {
    await page.goto(`${BASE}/backend/editorial/forms/1`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    await page.mouse.wheel(0, 800);
    await wait(1000);
    await shot("les-demandes-recues");

    // Cadré sur une demande : c'est la forme d'une réponse reçue, pas la
    // page qui la contient.
    await shotOf(page.getByText("SUB-000001").first()
      .locator("xpath=ancestor::article[1]"), "le-detail-d-une-demande", 20, 2);

    // Les deux e-mails que le produit a réellement envoyés, lus dans la
    // boîte locale et cadrés sur le message : ce que reçoit le lecteur de la
    // documentation, pas la boîte de réception qui sert à les relire.
    await page.goto("http://127.0.0.1:8025/", { waitUntil: "domcontentloaded" });
    await wait(2500);

    await page.getByText(/Nouvelle soumission/).first().click();
    await wait(2200);
    await shotOf(page.locator("#preview-html"), "l-e-mail-recu-par-le-site", 0);

    await page.goto("http://127.0.0.1:8025/", { waitUntil: "domcontentloaded" });
    await wait(2000);
    await page.getByText(/Votre message/).first().click();
    await wait(2200);
    await shotOf(page.locator("#preview-html"), "l-accuse-de-reception-du-visiteur", 0);
  },

  /**
   * Les cinq statuts, et les deux dates qui les font changer tout seuls.
   *
   * Le brouillon de la démo sert de sujet : on lui fait traverser les états
   * plutôt que d'en photographier la liste, parce que ce qui compte est ce
   * qui apparaît à chaque choix.
   */
  "cycle-de-vie": async () => {
    const post = process.env.DOC_DRAFT_ID ?? "4";

    await page.goto(`${BASE}/backend/editorial/posts`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    // Cadré sur le tableau : la colonne Statut est ce que la page raconte, et
    // la démo porte quatre états différents d'un coup.
    await shotOf(page.locator("table").first(), "les-statuts-dans-la-liste", 8, 8);

    await page.goto(`${BASE}/backend/editorial/posts/${post}/edit`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    // La barre du haut de l'éditeur : le fil de retour à gauche, la pastille
    // de statut et les deux boutons à droite. `getByRole("button")` remonte
    // à un conteneur trop large, d'où l'ancêtre nommé.
    await shotOf(page.getByText("Retour à la liste").first()
      .locator("xpath=ancestor::div[contains(@class,'justify-between')][1]"),
      "la-pastille-de-statut", 8, 8);

    const status = page.locator("select").filter({ hasText: "En attente de revue" }).first();
    await status.scrollIntoViewIfNeeded();
    await wait(800);
    await shot("le-choix-du-statut");

    await status.selectOption({ label: "Programmée" });
    await wait(1200);
    await shot("une-date-de-mise-en-ligne");

    // La date de retrait ne dépend d'aucun statut : elle vaut pour une page
    // déjà en ligne, et c'est ce que dit son texte d'aide.
    await status.selectOption({ label: "Publiée" });
    await wait(1200);
    await shot("une-date-de-retrait");

    // Remis comme on l'a trouvé : la démo doit rester lisible pour la photo
    // suivante, et un brouillon publié par une capture n'est plus un brouillon.
    await status.selectOption({ label: "Brouillon" });
    await wait(800);
  },

  /**
   * Le lien de prévisualisation, jusqu'à la page qu'il ouvre.
   *
   * Le bouton enregistre puis ouvre un onglet : le parcours suit l'onglet, et
   * finit par l'adresse publique du brouillon, qui elle ne répond pas.
   */
  "lien-de-previsualisation": async () => {
    const post = process.env.DOC_DRAFT_ID ?? "4";

    await page.goto(`${BASE}/backend/editorial/posts/${post}/edit`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    await shotOf(page.getByText("Retour à la liste").first()
      .locator("xpath=ancestor::div[contains(@class,'justify-between')][1]"),
      "le-bouton-previsualiser", 8, 8);

    // L'onglet s'ouvre avant la requête, côté produit : on l'attend donc dès
    // le clic, pas après.
    const opened = page.context().waitForEvent("page");
    // Attrapée sans être attendue : si le clic échoue, le flux part en erreur
    // et personne ne consomme plus cette promesse. Sans ce garde-fou, son
    // rejet vingt secondes plus tard tuait tout le script, et les parcours
    // suivants n'étaient jamais photographiés.
    opened.catch(() => {});
    await pageAction("Prévisualiser");
    const preview = await opened;
    await preview.waitForLoadState("domcontentloaded");
    await preview.waitForTimeout(3000);

    const held = page;
    page = preview;
    await shot("la-page-telle-qu-elle-sera");
    await preview.close();
    page = held;

    // Le contraste qui fait comprendre à quoi sert le lien : la même page,
    // à son adresse publique, n'existe pas encore.
    const guest = await page.context().browser().newContext({
      viewport: VIEWPORT, locale: "fr-FR", timezoneId: "Europe/Paris", colorScheme: "dark",
    });
    const visitor = await guest.newPage();
    const kept = page;
    page = visitor;
    // La page d'erreur du site, pas celle du profileur : en dev, Symfony
    // intercepte le 404 et affiche sa trace, qui n'est pas ce que voit le
    // visiteur. `_error/404` rend le gabarit réel, celui que l'adresse
    // publique d'un brouillon renvoie en production.
    await page.goto(`${BASE}/_error/404`, { waitUntil: "domcontentloaded" });
    await wait(1800);
    await shot("l-adresse-publique-ne-repond-pas");
    await guest.close();
    page = kept;
  },

  /**
   * Mettre à la corbeille depuis un écran, ressortir depuis l'autre.
   *
   * La publication est vraiment jetée et vraiment restaurée : une corbeille
   * photographiée vide n'apprend rien, et c'est l'état dans lequel la démo
   * se trouve tant que personne n'a rien supprimé.
   *
   * Deux écrans, parce que le geste en traverse deux depuis que la corbeille
   * est un endroit : on supprime là où l'on travaille, on restaure dans
   * Général > Corbeille.
   */
  "corbeille": async () => {
    // Reprise après un parcours interrompu : la publication est peut-être
    // restée dans la corbeille, et le flux commencerait alors par chercher
    // une ligne qui n'est plus dans la liste.
    await page.goto(`${BASE}/backend/trash`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    const stranded = page.getByText("Ce qui arrive ensuite").first();

    if (await stranded.count() > 0) {
      await page.getByRole("button", { name: /^Restaurer/ }).first().click();
      await wait(2500);
    }

    await page.goto(`${BASE}/backend/editorial/posts`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    await page.getByRole("button", { name: /Actions pour Ce qui arrive ensuite/ }).first().click();
    await wait(1200);
    await shot("le-menu-d-une-publication");

    await page.getByRole("button", { name: /^Supprimer/ }).last().click();
    await wait(1400);
    await shot("la-confirmation");

    // Le bouton de la fenêtre, pas celui du menu de ligne resté dans le DOM :
    // les deux portent le même mot.
    await page.locator("[role='dialog']").getByRole("button", { name: /^Supprimer$/ }).first().click();
    await wait(2500);

    await page.goto(`${BASE}/backend/trash`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("l-ecran");

    // L'onglet des publications, qui n'est pas forcément le premier : les
    // corbeilles les plus pleines passent devant.
    await page.getByRole("button", { name: /Publications/ }).first().click();
    await wait(1500);

    const row = page.locator("div").filter({ hasText: /^Ce qui arrive ensuite/ }).last();
    await shotOf(row, "restaurer-ou-effacer", 18, 10);

    await page.getByRole("button", { name: /^Restaurer/ }).first().click();
    await wait(2500);
  },

  /**
   * Renommer l'adresse d'une page sans casser ce qui pointe dessus.
   *
   * L'adresse est vraiment changée, et l'ancienne vraiment ouverte ensuite :
   * la redirection est le sujet de la page, et elle ne se voit qu'en allant
   * à l'ancienne adresse.
   */
  "anciennes-adresses": async () => {
    await page.goto(`${BASE}/backend/editorial/posts/3/edit`, { waitUntil: "domcontentloaded" });
    await wait(3500);

    // Le champ se désigne par son texte indicatif : les champs de l'éditeur
    // n'ont pas d'attribut `name`, et le libellé « SLUG » est un élément à
    // côté de l'entrée, pas son `label`.
    const slugField = page.locator("input[placeholder='titre-de-la-page']").first();
    await slugField.scrollIntoViewIfNeeded();
    await wait(800);
    await shot("le-champ-slug");

    await slugField.fill("composer-une-page-avec-des-zones");
    await wait(700);
    await shot("la-nouvelle-adresse");

    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(3000);

    const guest = await page.context().browser().newContext({
      viewport: VIEWPORT, locale: "fr-FR", timezoneId: "Europe/Paris", colorScheme: "dark",
    });
    const visitor = await guest.newPage();
    const kept = page;
    page = visitor;

    // L'ancienne adresse, telle qu'elle a pu être partagée avant le
    // renommage. Elle répond, et c'est la page renommée qui s'affiche.
    await page.goto(`${BASE}/fr/article/composer-avec-les-blocs`, { waitUntil: "domcontentloaded" });
    await wait(2200);
    await shot("l-ancienne-adresse-repond-toujours");
    await guest.close();
    page = kept;

    // Remis comme on l'a trouvé.
    await page.goto(`${BASE}/backend/editorial/posts/3/edit`, { waitUntil: "domcontentloaded" });
    await wait(3500);
    const back = page.locator("input[placeholder='titre-de-la-page']").first();
    await back.fill("composer-avec-les-blocs");
    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(2500);
  },

  /**
   * L'historique des versions, jusqu'à la restauration.
   *
   * Le parcours réécrit vraiment le résumé d'une publication, puis revient
   * en arrière : une comparaison entre deux versions identiques ne montre
   * pas ce que la page raconte.
   */
  "revisions": async () => {
    const post = process.env.DOC_POST_ID ?? "3";

    await page.goto(`${BASE}/backend/editorial/posts/${post}/edit`, { waitUntil: "domcontentloaded" });
    await wait(3500);

    const summary = page.locator("textarea").first();
    const original = await summary.inputValue();

    await summary.fill("Un résumé réécrit trop vite, qu'on va vouloir annuler.");
    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(3000);

    await shotOf(page.getByText("Retour à la liste").first()
      .locator("xpath=ancestor::div[contains(@class,'justify-between')][1]"),
      "le-bouton-historique", 8, 8);

    await pageAction(/^Historique$/);
    await wait(1000);
    await shot("la-liste-des-versions");

    // La deuxième entrée : l'état d'avant la réécriture.
    await page.locator("[role='dialog'] ol button").nth(1).click();
    await wait(1800);
    await shot("comparer-deux-versions");

    await page.getByRole("button", { name: /^Restaurer cette version$/ }).click();
    await wait(1000);
    await shot("confirmer-la-restauration");

    await page.getByRole("button", { name: /^Confirmer la restauration$/ }).click();
    await wait(4000);
    await shot("la-version-restauree");

    // Remis comme on l'a trouvé, quoi qu'il arrive plus haut.
    const back = page.locator("textarea").first();
    if (await back.inputValue() !== original) {
      await back.fill(original);
      await page.getByRole("button", { name: "Enregistrer" }).first().click();
      await wait(2500);
    }
  },

  /** La file de modération : les trois états, le fil, les actions. */
  "moderation-des-commentaires": async () => {
    await page.goto(`${BASE}/backend/editorial/comments`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-file-de-moderation");

    // Les compteurs par état, cadrés : c'est la première chose qu'on lit en
    // arrivant, et c'est trois nombres au milieu d'un écran large.
    await shotOf(page.getByRole("button", { name: /^En attente/ })
      .locator("xpath=ancestor::div[1]"), "les-trois-etats", 12, 8);

    await page.getByRole("button", { name: /Actions pour Sofia/ }).first().click();
    await wait(1100);
    await shot("les-actions-d-un-commentaire");
    await page.keyboard.press("Escape").catch(() => {});
    await page.getByRole("button", { name: /^Fermer$/ }).first().click().catch(() => {});
    await wait(900);

    await page.getByPlaceholder(/Chercher un auteur/).first().fill("zones");
    await wait(1800);
    await shot("la-recherche");
    await page.getByPlaceholder(/Chercher un auteur/).first().fill("");
    await wait(1500);

    await page.getByRole("button", { name: /^Indésirable/ }).first().click();
    await wait(2000);
    await shot("l-onglet-des-indesirables");
  },

  /** Les réactions, côté visiteur puis côté modération. */
  "reactions": async () => {
    await page.goto(`${BASE}/fr/article/ecrire-premier-article`, { waitUntil: "domcontentloaded" });
    await wait(2500);

    const bar = page.getByText("Commentaires").first();
    await bar.scrollIntoViewIfNeeded();
    await wait(1000);
    await shot("les-commentaires-sur-le-site");

    // La barre de réactions d'un commentaire, cadrée : six émojis et deux
    // compteurs, sur une page entière.
    await shotOf(page.getByText("Camille Durand").first()
      .locator("xpath=ancestor::article[1]"), "la-barre-de-reactions", 16, 4)
      .catch(async () => { await shot("la-barre-de-reactions"); });

    // Réagir pour de vrai : le compteur bouge, et c'est le geste que la page
    // décrit.
    // Les boutons portent le nom de la réaction, pas son émoji.
    await page.getByRole("button", { name: "Waouh" }).first().click();
    await wait(1600);
    await shot("une-reaction-de-plus");

    await page.goto(`${BASE}/backend/editorial/comments`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("les-reactions-vues-en-moderation");
  },

  /**
   * Le carnet de notes, en cinq flux qui se suivent.
   *
   * Une note s'ouvre par son adresse : l'arborescence est dans le menu
   * latéral, et cliquer une note du menu déplace le menu autant que
   * l'éditeur.
   */
  "notes": async () => {
    const NOTE = { clients: 1, lumen: 2, contrat: 4, seance: 5 };

    flowName = "ecrire-une-note";
    step = 0;
    await openNote(NOTE.clients, "Clients");
    await shot("la-source-et-le-rendu");

    // Le carnet dans le menu : l'arborescence est une note dans une note,
    // et c'est là qu'elle se voit.
    //
    // Sans repli, ce cadrage échouait et retombait sur une capture pleine
    // page : la légende annonçait l'arborescence et l'image montrait la même
    // chose que la précédente, au pixel près.
    // Le plus petit conteneur qui porte la première note et la dernière :
    // remonter par la largeur ne donnait qu'une ligne, puisqu'une ligne est
    // déjà aussi large que la colonne.
    const tree = page.locator("div")
      .filter({ has: page.getByText("Clients", { exact: true }) })
      .filter({ has: page.getByText("Idées d'articles", { exact: true }) })
      .last();
    await tree.scrollIntoViewIfNeeded();
    await wait(600);
    await shotOf(tree, "l-arborescence", 10, 10);

    await page.getByRole("button", { name: /^Aperçu seul$/ }).first().click();
    await wait(1200);
    await shot("le-rendu-seul");

    await page.getByRole("button", { name: /^Édition \+ aperçu$/ }).first().click();
    await wait(1000);

    flowName = "relier-deux-notes";
    step = 0;
    await shotOf(page.locator("textarea").first(), "les-doubles-crochets", 10, 10);

    await openNote(NOTE.contrat, "Contrat type");
    await shot("la-note-visee");

    flowName = "notes-qui-pointent-ici";
    step = 0;
    await page.getByRole("button", { name: /Afficher les liens entrants/ }).first().click();
    await wait(2000);
    await shot("le-panneau-des-liens");

    // Même remarque : le repli produisait une seconde capture identique à la
    // première. Le panneau des liens porte deux listes, et c'est la seconde -
    // ce qui cite cette note - que la page explique.
    const backlinks = page.locator("div")
      .filter({ has: page.getByText("Liens entrants", { exact: true }) })
      .filter({ has: page.getByText("Cabinet Verrier", { exact: true }) })
      .last();
    await backlinks.scrollIntoViewIfNeeded();
    await wait(600);
    await shotOf(backlinks, "qui-cite-cette-note", 12, 12);

    flowName = "mentions-non-liees";
    step = 0;
    await openNote(NOTE.lumen, "Studio Lumen");
    await page.getByRole("button", { name: /Afficher les liens entrants/ }).first().click();
    await wait(1800);
    await page.getByRole("button", { name: /^Mentions$/ }).first().click();
    await wait(1800);
    await shot("le-titre-cite-sans-crochets");

    flowName = "graphe-des-liens";
    step = 0;
    await page.getByRole("button", { name: /Ouvrir le graphe/ }).first().click();
    await wait(3000);
    await shot("le-reseau-des-notes");
  },

  /** Étiquettes, recherche, et le partage d'une note par lien. */
  "notes-suite": async () => {
    flowName = "etiqueter-et-chercher";
    step = 0;
    await openNote(2, "Studio Lumen");
    await shotOf(page.getByPlaceholder(/Ajouter un tag/).first()
      .locator("xpath=ancestor::div[1]"), "les-etiquettes-d-une-note", 10, 8);

    await page.getByRole("button", { name: "photo", exact: true }).first().click();
    await wait(1800);
    await shot("filtrer-par-etiquette");

    await page.getByRole("button", { name: "photo", exact: true }).first().click();
    await wait(1200);

    flowName = "partager-une-note";
    step = 0;
    await page.getByRole("button", { name: /Partager/ }).first().click();
    await wait(2000);
    await shot("la-fenetre-de-partage");
  },

  /**
   * Déposer un document, du panneau vide à la ligne dans la bibliothèque.
   *
   * Les champs propres à une image - le texte alternatif, la légende -
   * n'apparaissent qu'une fois le fichier choisi : le panneau ne sait pas
   * avant. Une capture du panneau vide, seule, laissait croire qu'ils
   * n'existent pas.
   */
  "deposer-un-document": async () => {
    await page.goto(`${BASE}/backend/ged/documents`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-bibliotheque");

    await pageAction("Ajouter un document");
    await wait(1500);
    await shot("le-panneau-de-depot");

    // Le bouton ouvre la fenêtre de l'ordinateur, que Playwright ne peut pas
    // photographier : le fichier est posé sur le champ caché qu'elle
    // remplirait.
    await page.locator("input[type='file']").first().setInputFiles(await sampleUpload());
    await wait(3000);
    await shot("les-champs-propres-a-une-image");

    await page.getByPlaceholder("Titre du document…").first().fill("Affiche du salon 2026");
    await page.getByPlaceholder("Description optionnelle…").first().fill("Affiche officielle, format portrait, à décliner en bannière.");

    await page.getByPlaceholder("Décris l'image pour les lecteurs d'écran et le SEO…").first().fill("Affiche du salon 2026, fond dégradé et titre centré.");
    await page.getByPlaceholder("Légende affichée sous l'image…").first().fill("Salon 2026, du 12 au 14 mars.");
    await wait(800);
    await shot("avant-d-enregistrer");

    await page.getByRole("button", { name: "Enregistrer" }).first().click();
    await wait(3000);
    await shot("le-document-dans-la-bibliotheque");

    // Le dépôt est réel : sans ce ménage, chaque prise laissait une affiche
    // de plus dans la démo, et la bibliothèque photographiée finissait par
    // n'être qu'une pile de doublons.
    await removeDocument("Affiche du salon 2026");
  },

  /**
   * Les quatre façons de regarder le même mois.
   */
  "les-quatre-vues": async () => {
    await openCalendar();
    await shot("la-vue-mois");

    for (const [view, name] of [["Semaine", "la-vue-semaine"], ["Jour", "la-vue-jour"], ["Agenda", "la-vue-agenda"]]) {
      await page.getByRole("button", { name: view, exact: true }).first().click();
      await wait(2200);
      await shot(name);
    }
  },

  /**
   * Les calendriers : la liste, la création, et ce que décocher change.
   */
  "agendas": async () => {
    await openCalendar();

    // Le conteneur qui porte à la fois l'intitulé et les calendriers : le plus
    // profond des deux ne contenait que la ligne de titre.
    const list = page.locator("div")
      .filter({ hasText: "Mes calendriers" })
      .filter({ has: page.getByRole("button", { name: "Astreinte", exact: true }) })
      .last();
    await list.scrollIntoViewIfNeeded();
    await wait(700);
    await shotOf(list, "la-liste-des-calendriers", 12, 10);

    await page.getByRole("button", { name: "Nouveau calendrier" }).first().click();
    await wait(1800);
    await shot("creer-un-calendrier");

    await page.getByRole("button", { name: "Annuler" }).first().click();
    await wait(1200);
  },

  /**
   * Un événement, du panneau vide à sa place dans le mois.
   */
  "un-evenement": async () => {
    await openCalendar();

    await page.getByRole("button", { name: "Nouvel événement" }).first().click();
    await wait(2000);
    await shot("le-panneau-d-un-evenement");

    await page.getByPlaceholder("Un titre court").first().fill("Revue éditoriale de rentrée");
    await page.getByPlaceholder("Une salle, une adresse, un lien").first().fill("Salle Vercors, ou en visio");
    await page.getByPlaceholder("Une phrase pour situer").first().fill("Relire les brouillons en attente et trancher les publications de septembre.");
    await wait(900);
    await shot("l-evenement-rempli");
  },

  /**
   * La récurrence, du choix courant au réglage détaillé.
   */
  "recurrences": async () => {
    await openCalendar();

    await page.getByRole("button", { name: "Nouvel événement" }).first().click();
    await wait(2000);

    // Le menu est un `select` natif : sa liste ouverte est dessinée par le
    // système et ne se photographie pas. Ce qui compte se voit autrement -
    // les quatre choix courants dans le menu fermé, puis le panneau que
    // « Personnalisé… » déplie.
    const menu = page.locator("select").filter({ has: page.locator('option:text-is("Ne se répète pas")') }).first();
    await menu.scrollIntoViewIfNeeded();
    await wait(700);
    await shotOf(menu, "le-menu-de-recurrence", 16, 28);

    await menu.selectOption("custom");
    await wait(1200);

    // Le bloc entier, menu compris : c'est le menu qui déplie le panneau, et
    // les montrer séparément demanderait au lecteur de les recoller.
    // Le deuxième ancêtre, pas le premier : `AppSelect` enveloppe son libellé
    // et son champ dans un conteneur qui porte les mêmes classes que celui du
    // bloc, et cadrer le premier ne montrait que le menu fermé.
    const panel = menu.locator('xpath=ancestor::div[contains(@class,"gap-1.5")][2]');
    await panel.scrollIntoViewIfNeeded();
    await wait(700);
    await shotOf(panel, "le-reglage-personnalise", 16, 10);
  },

  /**
   * Les participants, et la réponse qu'ils donnent.
   */
  "invites": async () => {
    await openCalendar();

    // Un événement qui a déjà ses invités, plutôt qu'un champ vide : ce qui
    // s'apprend ici, ce sont les réponses, et un formulaire neuf n'en a pas.
    await page.getByText("Réunion générale", { exact: true }).first().click();
    await wait(2500);
    await shot("un-evenement-et-ses-invites");

    // Le champ qui a servi à les inviter est dans la fenêtre de modification,
    // pas dans la bulle : la bulle montre les réponses, le champ montre
    // comment on invite.
    await page.getByRole("button", { name: /Modifier/ }).first().click();
    await wait(2500);

    const field = page.getByPlaceholder("Inviter quelqu'un…").first();
    await field.scrollIntoViewIfNeeded();
    await wait(900);
    await shotOf(field.locator('xpath=ancestor::div[contains(@class,"gap-1.5")][1]'), "le-champ-des-participants", 16, 10);
  },

  /**
   * Les alertes d'un événement : quand, et par quel canal.
   */
  "alertes": async () => {
    await openCalendar();

    await page.getByText("Réunion générale", { exact: true }).first().click();
    await wait(2000);
    await page.getByRole("button", { name: /Modifier/ }).first().click();
    await wait(2500);

    const add = page.getByRole("button", { name: "Ajouter une alerte" }).first();
    await add.scrollIntoViewIfNeeded();
    await wait(700);
    await shotOf(add.locator('xpath=ancestor::div[contains(@class,"flex-col")][1]'), "le-bloc-des-alertes", 16, 10);

    await add.click();
    await wait(1200);
    await shotOf(add.locator('xpath=ancestor::div[contains(@class,"flex-col")][1]'), "une-alerte-ajoutee", 16, 10);
  },

  /**
   * Les dates que les autres modules posent dans le calendrier.
   */
  "dates-des-autres-modules": async () => {
    await openCalendar();

    const entry = page.getByText(/Échéance facture/).first();
    await entry.scrollIntoViewIfNeeded();
    await wait(700);
    await shotOf(entry, "une-echeance-venue-du-studio", 24, 14);

    await entry.click();
    await wait(2000);
    await shot("ce-qu-elle-dit");
  },

  /**
   * Les comptes : la liste, ses filtres, et ce qu'on fait d'une ligne.
   */
  "les-comptes": async () => {
    await page.goto(`${BASE}/backend/platform/users`, { waitUntil: "domcontentloaded" });
    await wait(3000);
    await shot("la-liste-des-comptes");

    await page.getByRole("button", { name: /Actions pour Marie Dupont/ }).first().click();
    await wait(1500);
    await shot("les-actions-d-un-compte");

    await page.getByRole("button", { name: /^Voir le profil/ }).first().click();
    await wait(2500);
    await shot("la-fiche-d-un-compte");
  },

  /**
   * Les privilèges d'un compte, écran par écran.
   */
  "privileges": async () => {
    await page.goto(`${BASE}/backend/platform/users`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    await page.getByRole("button", { name: /Actions pour Jean Martin/ }).first().click();
    await wait(1500);
    await page.getByRole("button", { name: /^Privilèges/ }).first().click();
    await wait(2500);
    await shot("la-fenetre-des-privileges");
  },

  /**
   * Inviter quelqu'un.
   */
  "inviter-quelqu-un": async () => {
    await page.goto(`${BASE}/backend/platform/users`, { waitUntil: "domcontentloaded" });
    await wait(3000);

    await pageAction(/Inviter un utilisateur/);
    await wait(2000);
    await shot("le-panneau-d-invitation");
  },

  /**
   * Le mot de passe oublié, tel que le voit quelqu'un qui n'est pas connecté.
   */
  "mot-de-passe-oublie": async () => {
    const guest = await page.context().browser().newContext({
      viewport: VIEWPORT, locale: "fr-FR", timezoneId: "Europe/Paris", colorScheme: "dark",
    });
    const visitor = await guest.newPage();
    const held = page;
    page = visitor;

    await page.goto(`${BASE}/backend/platform/forgot-password`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-page-de-mot-de-passe-oublie");

    await page.locator("input[type='email']").first().fill("marie.dupont@aurora.app");
    await wait(700);
    await page.locator("button[type='submit']").first().click();
    await wait(3000);
    await shot("la-reponse-toujours-la-meme");

    await guest.close();
    page = held;
  },

  /**
   * Les rappels : ce qu'ils sont, et où ils s'affichent.
   */
  "rappels": async () => {
    await openCalendar();

    await page.getByRole("button", { name: "Nouveau rappel" }).first().click();
    await wait(2000);
    await shot("le-panneau-d-un-rappel");

    await page.getByPlaceholder("Appeler le client, relire le brouillon…").first().fill("Relancer l'imprimeur pour les affiches");
    await page.getByPlaceholder("Ce qu'il faut savoir pour le faire").first().fill("Devis reçu le 3, valable un mois.");
    await wait(900);
    await shot("le-rappel-rempli");

    await page.getByRole("button", { name: "Annuler" }).first().click();
    await wait(1200);

    // La semaine, pas le jour : la ligne des rappels n'apparaît que les jours
    // qui en portent, et celle-ci en montre trois d'un coup - un en retard,
    // un fait, un à faire.
    await page.getByRole("button", { name: "Semaine", exact: true }).first().click();
    await wait(2500);

    const row = await wideAncestor(page.getByText("Prendre le rendez-vous chez le dentiste").first(), 1040);
    await shotOf(row, "la-ligne-des-rappels", 10, 0);
  },

  /**
   * Partager un calendrier : à un compte, ou par une adresse secrète.
   */
  "partager-un-agenda": async () => {
    await openCalendar();

    // Partager à un compte se fait dans la fenêtre du calendrier, pas dans
    // celle des liens : ce sont deux partages différents, et les confondre
    // était le défaut de la page précédente.
    const row = page.getByRole("button", { name: "Astreinte", exact: true }).first();
    await row.hover();
    await wait(600);
    await page.getByRole("button", { name: "Modifier le calendrier" }).first().click();
    await wait(2200);
    await shot("la-fenetre-du-calendrier");

    const shares = page.getByPlaceholder("Ajouter quelqu'un…").first();
    await shares.scrollIntoViewIfNeeded();
    await wait(800);
    await shotOf(shares.locator('xpath=ancestor::div[contains(@class,"gap-1.5")][2]'), "partage-avec-un-compte", 16, 12);
  },

  /**
   * Les liens de partage : une adresse secrète, pour lire sans compte.
   *
   * Photographié sur un calendrier qui en a déjà un, sinon la fenêtre ne
   * montre que « Aucun lien » et le formulaire, c'est-à-dire la moitié de ce
   * que la page doit expliquer.
   */
  "liens-de-partage": async () => {
    await openCalendar();

    const row = page.getByRole("button", { name: "Formations", exact: true }).first();
    await row.hover();
    await wait(600);
    await page.getByRole("button", { name: "Partage par lien" }).first().click();
    await wait(2200);
    await shot("la-fenetre-des-liens");

    await page.getByPlaceholder("Marie, studio photo").first().fill("Marie, pour la saison");
    await wait(900);
    await shot("un-lien-a-creer");

    // Le second type, celui qui produit une adresse à coller dans une
    // application d'agenda plutôt qu'une page à ouvrir.
    const kind = page.locator("select").filter({ has: page.locator('option:text-is("Page web")') }).first();
    await kind.selectOption({ label: "Abonnement (.ics)" });
    await wait(1200);
    await shotOf(await wideAncestor(kind, 420), "un-abonnement-plutot-qu-une-page", 16, 40);
  },

  /**
   * Les dossiers : l'arborescence, la création, et le filtre qu'un dossier
   * pose sur la liste.
   */
  "dossiers": async () => {
    await page.goto(`${BASE}/backend/ged/documents`, { waitUntil: "domcontentloaded" });
    await wait(2500);

    // L'arborescence est en bas du menu, hors de l'écran au chargement : une
    // capture pleine page la montrait coupée après deux dossiers.
    const tree = page.locator('section:has(a[href*="folderId="])').first();
    await tree.scrollIntoViewIfNeeded();
    await wait(800);
    await shotOf(tree, "l-arborescence", 12, 10);

    await page.getByRole("button", { name: "Nouveau dossier" }).first().click();
    await wait(1500);
    await shot("creer-un-dossier");

    // Par le bouton : la fenêtre ne se ferme pas à Échap, et le clic suivant
    // tombait sur le voile.
    await page.getByRole("button", { name: "Annuler" }).first().click();
    await wait(1200);

    // Par l'adresse : les entrées de l'arbre n'ont pas de nom accessible, et
    // un nom de dossier de démo pourrait changer.
    await page.locator('a[href*="folderId="]').first().click();
    await wait(2500);
    await shot("la-liste-filtree-par-dossier");
  },

  /**
   * Les catégories : une par document, et ce que la page permet d'en faire.
   */
  "categories-de-documents": async () => {
    await page.goto(`${BASE}/backend/ged/categories`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-liste-des-categories");

    await pageAction("Ajouter une catégorie");
    await wait(1500);
    await shot("creer-une-categorie");

    // La fenêtre ne se ferme pas à Échap : `closeable` est faux, et le clic
    // suivant tombait sur le voile.
    await page.getByRole("button", { name: "Annuler" }).first().click();
    await wait(1200);

    await page.getByRole("button", { name: /^Actions pour / }).first().click();
    await wait(1200);
    await shot("les-actions-d-une-categorie");
  },

  /**
   * Les étiquettes : plusieurs par document, et le filtre qu'elles servent.
   */
  "etiquettes-de-documents": async () => {
    await page.goto(`${BASE}/backend/ged/tags`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await shot("la-liste-des-etiquettes");

    await pageAction(/Ajouter une étiquette/i);
    await wait(1500);
    await shot("creer-une-etiquette");

    await page.getByRole("button", { name: "Annuler" }).first().click();
    await wait(1200);

    await page.goto(`${BASE}/backend/ged/documents`, { waitUntil: "domcontentloaded" });
    await wait(2500);
    await page.locator("select, [role='combobox']").nth(1).click();
    await wait(1200);
    await shot("filtrer-la-bibliotheque-par-etiquette");
  },

  /**
   * L'historique des versions, dans le panneau de détail.
   *
   * Le bloc n'apparaît qu'à partir de deux versions, donc le document
   * photographié est celui que la démo fait remplacer deux fois.
   */
  "versions-d-un-document": async () => {
    await openCampaignDocument();
    await shot("le-panneau-de-detail");

    const history = page.locator("div").filter({ hasText: /^Historique des versions/i }).last();
    await shotOf(history, "l-historique-des-versions", 24, 12);
  },

  /**
   * Recadrer une image sans quitter la bibliothèque.
   */
  "recadrer-une-image": async () => {
    await openCampaignDocument();

    await page.getByRole("button", { name: "Recadrer" }).first().click();
    await wait(2500);
    await shot("l-outil-de-recadrage");

    // Une sélection tirée à la souris : la photo doit montrer un cadre
    // déplacé, pas le cadre par défaut qui couvre toute l'image.
    const frame = page.locator("img").last();
    const box = await frame.boundingBox();

    if (box) {
      await page.mouse.move(box.x + box.width * 0.3, box.y + box.height * 0.3);
      await page.mouse.down();
      await page.mouse.move(box.x + box.width * 0.75, box.y + box.height * 0.8, { steps: 20 });
      await page.mouse.up();
      await wait(1200);
    }

    await shot("la-zone-choisie");
  },
};

const wanted = process.argv.slice(2);
const flows = wanted.length === 0 ? Object.keys(FLOWS) : wanted;

await mkdir(outDir, { recursive: true });

// --lang plutôt que la locale du contexte : les contrôles natifs (le champ
// date d'un formulaire public, par exemple) suivent la langue du navigateur
// et pas celle de la page, et une capture française affichait mm/dd/yyyy.
const browser = await chromium.launch({ args: ["--lang=fr-FR"] });
const context = await browser.newContext({
  viewport: VIEWPORT, deviceScaleFactor: 1, locale: "fr-FR",
  timezoneId: "Europe/Paris", colorScheme: "dark",
});
context.setDefaultTimeout(20000);
page = await context.newPage();

// L'éditeur de notes pose une garde `beforeunload` tant qu'une modification
// n'est pas enregistrée, et Playwright refuse les boîtes de dialogue par
// défaut : la navigation ne partait pas, et la garde d'`openNote` concluait -
// à raison - que la note affichée n'était pas celle demandée. Ici on accepte,
// puisqu'une prise de vue n'a rien à sauvegarder.
page.on("dialog", (dialog) => dialog.accept().catch(() => {}));

await page.goto(`${BASE}/backend/platform/login`, { waitUntil: "domcontentloaded" });
await page.locator("input[type='email']").first().fill("dev@aurora.app");
await page.locator("input[type='password']").first().fill("password");
await page.locator("button[type='submit']").first().click();
await page.waitForURL(/\/backend/, { timeout: 20000 });

let failed = 0;
for (const name of flows) {
  flowName = name;
  step = 0;
  console.log(`## ${name}`);

  try {
    await FLOWS[name]();
  } catch (error) {
    failed += 1;
    console.log(`  ! ${error.message.split("\n")[0]}`);
  }
}

await browser.close();
console.log(failed === 0 ? "--- ok" : `--- ${failed} flux en echec`);
