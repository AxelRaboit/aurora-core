/**
 * Captures the screenshots the public tour of Aurora is illustrated with.
 *
 * The tour at /fr/page/aurora is one card per subject, each with a picture of
 * the screen it describes. Those pictures were taken by hand, one at a time,
 * which is why two of them are duplicates of the same screen and why the
 * accounting module has none at all: its three screens could not even be
 * opened locally until the route-template bug was fixed.
 *
 * So this exists to make them reproducible. Point it at a local instance
 * loaded with `make demo`, run it, and every card's picture is regenerated at
 * the same size, in the same theme, with the same demo data.
 *
 * **Local only, and demo data only.** These images go on a public page. A
 * capture taken against production would put a real customer's name and SIRET
 * on it, the audit log would carry a real address, and the sidebar footer
 * would show a personal email. The fixtures exist precisely so that none of
 * that is ever in frame.
 *
 * Authentication reuses the fixture account the repository's own end-to-end
 * tests sign in with (`dev@aurora.app`, seeded by `AppFixtures` with a
 * password that is a literal in this public repository). Nothing secret is
 * handled here, and nothing but a local instance will accept it.
 *
 * Usage:
 *   node tools/screenshots/capture-tour.mjs                  # everything
 *   node tools/screenshots/capture-tour.mjs contracts trames  # by name
 *
 * Output: var/screenshots/<name>.png, git-ignored.
 */

import { chromium } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const outDir = resolve(root, "var/screenshots");

const BASE_URL = process.env.TOUR_BASE_URL ?? "http://127.0.0.1:8000";
const EMAIL = process.env.TOUR_EMAIL ?? "dev@aurora.app";
const PASSWORD = process.env.TOUR_PASSWORD ?? "password";

// The word typed into the search palette. A parameter because the point of
// that picture is a query that answers in several sections at once, and which
// word does that depends on what the demo fixtures happen to hold.
const SEARCH_QUERY = process.env.TOUR_SEARCH_QUERY ?? "aurora";

// The size every existing tour capture already has. Kept identical so a
// regenerated picture drops into a card without the crop moving.
const VIEWPORT = { width: 1600, height: 1000 };

/**
 * One entry per picture.
 *
 * `prepare` runs after the page has loaded and before the shutter: it is where
 * a panel gets opened or a query typed, because several of these screens only
 * say what they do once something is on them.
 */
/**
 * Met la bibliothèque à plat, si elle ne l'est pas déjà.
 *
 * Le bouton porte le geste qu'il ferait, pas l'état où l'on est : « Tout
 * afficher à plat » quand on est par dossiers, « Afficher par dossiers »
 * quand on est à plat. Et l'état est retenu d'une visite à l'autre, donc le
 * deuxième scénario cherchait un libellé que le premier venait de faire
 * disparaître.
 */
async function flatten(page) {
    const bouton = page.getByTitle("Tout afficher à plat").first();

    if (await bouton.count() > 0) {
        await bouton.click();
        await page.waitForTimeout(1_200);
    }
}

/**
 * Fait défiler la page pour que `element` commence à `top` pixels du haut.
 *
 * Pour les blocs d'une longue page publique : `scrollIntoViewIfNeeded` les
 * colle au bord, sous l'entête collante, ou les laisse où ils sont s'ils
 * dépassent à peine. Mesuré après coup, parce qu'un bloc qui s'ouvre (le
 * formulaire d'un rendez-vous) a changé de hauteur entre-temps.
 */
async function placeAt(page, element, top) {
    await element.scrollIntoViewIfNeeded();
    const box = await element.boundingBox();
    await page.evaluate((delta) => window.scrollBy(0, delta), box.y - top);
    // Les zones apparaissent en glissant quand elles entrent dans l'écran.
    await page.waitForTimeout(1_500);
}

/**
 * Une prise sur un onglet de l'éditeur de publication.
 *
 * Quatre cartes racontent chacune un onglet - l'entête, la galerie, le
 * référencement, les langues - et montraient toutes la même liste de
 * publications.
 *
 * `exact` sur le nom de l'onglet : « Types de contenu » vit dans le menu
 * latéral et contient « Contenu », donc un nom approchant attrape le menu et
 * la prise ressort sur l'onglet d'à côté - qui ressemble assez pour qu'on ne
 * le voie pas.
 */
function postTabShot(name, tab, extra) {
    return {
        name,
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: tab, exact: true }).first().click();
            await page.waitForTimeout(2_000);

            if (extra) await extra(page);
        },
    };
}

/**
 * Ouvre une publication de la démonstration.
 *
 * Par son adresse et non par un clic dans la liste : une ligne n'est pas un
 * lien, et son menu d'actions est un popover sans rôle à viser.
 */
const DEMO_POST_ID = process.env.TOUR_POST_ID ?? "1";

async function openPost(page) {
    // `domcontentloaded` et non `load` : l'éditeur garde une connexion ouverte
    // en dev, donc l'événement `load` n'arrive jamais.
    await page.goto(`${BASE_URL}/backend/editorial/posts/${DEMO_POST_ID}/edit`, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(4_000);
}

/** L'éditeur d'une publication, sur l'onglet demandé. */
const postTab = (name) => async (page) => {
    await openPost(page);
    await page.getByRole("tab", { name }).first().click().catch(async () => {
        await page.getByRole("button", { name }).first().click();
    });
    await page.waitForTimeout(1_500);
};

/**
 * Un espace de la démonstration, sur la vue demandée.
 *
 * Par la liste et nommément, pour la raison que `tour-espaces-clients`
 * explique : les identifiants changent à chaque rechargement des fixtures, et
 * la démonstration porte des espaces vides qui photographient une page qui
 * réussit sans rien montrer.
 *
 * La vue ouverte est retenue d'une visite à l'autre, donc chaque prise
 * reclique la sienne au lieu de compter sur ce qui était affiché avant.
 */
const spaceView = (view) => async (page) => {
    await openSpace(page);
    await spaceSection(page, view).click();
    await page.waitForTimeout(2_000);
};

/**
 * Une entrée du rail d'un espace, par son libellé.
 *
 * Depuis la 0.9.328 les sections d'un espace sont un rail de boutons, et une
 * entrée porte un compteur quand quelque chose attend (« Contenus 3 ») : son
 * nom accessible n'est plus le libellé seul, et un `exact: true` ne la trouve
 * plus. Le libellé, suivi ou non d'un nombre, et rien d'autre.
 */
function spaceSection(page, label) {
    // Dans `main` : le menu latéral a aussi ses « Réglages ».
    return page.locator("main").getByRole("button", { name: new RegExp(`^${label}(\\s+\\d+)?$`) }).first();
}

/**
 * Déplie les encarts « Comment ça marche » de la page.
 *
 * Les prises les gardent repliés (voir le script d'init du contexte) ; celles
 * qui montrent un encart l'ouvrent ici. Un clic sur un seul suffit : le choix
 * est commun, ils s'ouvrent tous.
 */
async function openGuides(page) {
    await page.locator("[data-guide] summary").first().click();
    await page.waitForTimeout(800);
}

/**
 * L'adresse de l'éditeur d'un livrable de l'espace ouvert, par son titre : la
 * liste est rangée par dernière modification, et la démo y met trois
 * livrables à côté de l'audit. Le titre de chaque carte mène à son éditeur.
 */
async function deliverableEditUrl(page, title = "Audit de présence en ligne") {
    const href = await page
        .locator("main li")
        .filter({ hasText: title })
        .getByRole("link", { name: title, exact: true })
        .first()
        .getAttribute("href");

    return new URL(href, page.url()).toString();
}

/**
 * Ouvrir l'espace « Réseaux sociaux », d'où partent toutes les prises d'un
 * espace.
 *
 * **L'onglet de la liste est retenu d'une visite à l'autre**, et `espaces-
 * prospects` le laisse sur Prospects juste avant. L'espace cherché est chez
 * un client, donc il n'est plus dans la liste, le clic expire au bout de
 * trente secondes et le scénario suivant tombe - seul il passait, dans la
 * série il ratait, ce qui est la signature d'un état partagé. On remet donc
 * l'onglet sur Clients avant de chercher, sans se demander où il en est.
 */
async function openSpace(page) {
    await page.getByRole("button", { name: /^Clients/ }).first().click();
    await page.waitForTimeout(1_000);

    await page.getByRole("link", { name: /Réseaux sociaux/ }).first().click();
    await page.waitForTimeout(3_500);
}

/**
 * Les contenus d'un espace, en kanban ou en liste.
 *
 * **La forme est retenue d'une visite à l'autre** : une prise en liste
 * laissait la suivante sur la liste, et le tableau photographié n'en était
 * plus un. Chaque prise dit donc la sienne.
 */
const contents = (shape) => async (page) => {
    await openSpace(page);
    await spaceSection(page, "Contenus").click();
    await page.waitForTimeout(1_500);
    await page.locator("main").getByTitle(shape, { exact: true }).first().click();
    await page.waitForTimeout(1_500);
};

/** Une fiche du tableau ouverte, par son titre. */
const openCard = (title) => async (page) => {
    await contents("Kanban")(page);
    await page.locator("main").getByText(title, { exact: true }).first().click();
    await page.getByRole("dialog").first().waitFor();
    await page.waitForTimeout(1_500);
};

/** Les espaces, d'où toutes les prises d'un espace partent. */
const SPACES = "/backend/studio/spaces";

/**
 * La page que le client ouvre, par une vraie adresse : un lien émis comme le
 * studio l'émet, puis suivi. Partagé par la prise de l'espace côté client et
 * par celle de ses livrables.
 */
async function openClientSide(page) {
    await openSpace(page);

    const espace = new URL(page.url());
    await page.goto(`${espace.origin}${espace.pathname}/access`, { waitUntil: "networkidle" });

    // Attendre le bouton plutôt que compter jusqu'à mille cinq cents.
    //
    // Ce scénario passait seul et tombait dans la série complète, sur
    // un `click` expiré au bout de trente secondes : une pause fixe
    // suffit sur une machine au repos et plus sur la même machine au
    // soixante-huitième écran. L'attente porte donc sur ce qu'on
    // attend vraiment, l'application montée et son bouton présent.
    const ouvrir = page.getByRole("button", { name: "Créer un lien" }).first();
    await ouvrir.waitFor({ state: "visible", timeout: 30_000 });
    await ouvrir.click();
    await page.waitForTimeout(1_000);

    // Par l'exemple du champ et non par son libellé : les champs de
    // cette modale n'ont pas d'identifiant, donc rien ne relie le
    // `<label>` à son `<input>` pour un outil qui lit la page.
    await page.getByPlaceholder("camille@societe.fr").fill("camille@atelier-dupont.example.com");
    await page.getByPlaceholder(/^Camille, /).fill("Camille, gérante");

    await page.getByRole("button", { name: "Créer un lien" }).last().click();
    await page.waitForTimeout(2_500);

    const adresse = (await page.locator("code").first().innerText()).trim();
    await page.goto(adresse, { waitUntil: "networkidle" });
    await page.waitForTimeout(2_500);
}


/**
 * Une présentation ouverte depuis la liste, par son titre : les identifiants
 * changent à chaque rechargement des fixtures.
 */
function openDeck(title) {
    return async (page) => {
        await page.locator("main").getByRole("link", { name: new RegExp(title) }).first().click();
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(2_500);
    };
}

/**
 * Une capture par carte du tour, nommée comme le document qu'elle remplace.
 *
 * **Le nom est le lien avec la production.** Chaque carte de /fr/page/aurora
 * affiche un document de la médiathèque appelé `tour-<nom>.png` ; une capture
 * qui porte le même nom se remplace par `aurora:ged:replace`, et la carte n'a
 * rien à savoir. Tenir une table de correspondance à côté serait une seconde
 * source de vérité à garder en phase.
 *
 * `prepare` tourne après le chargement et avant le déclencheur : c'est là
 * qu'un panneau s'ouvre ou qu'une requête se tape, parce que plusieurs de ces
 * écrans ne disent ce qu'ils font qu'une fois quelque chose dessus.
 */
const SHOTS = [
    { name: "tour-dashboard", path: "/backend" },
    {
        // Le même écran en thème clair, pour la moitié droite du bandeau du
        // sommaire.
        //
        // Le thème du back-office vit dans `localStorage` sous
        // `aurora-theme`, et c'est `useTheme` qui le repose sur `<html>` au
        // démarrage de l'application. On l'écrit donc **avant** le
        // chargement : le poser après, sur le document, se ferait écraser par
        // le composable une fraction de seconde plus tard.
        name: "tour-dashboard-clair",
        path: "/backend",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
        async before(page) {
            await page.addInitScript(() => {
                window.localStorage.setItem("aurora-theme", "light");
            });
        },
        async after(page) {
            // Remis comme on l'a trouvé : toutes les autres prises sont en
            // sombre, et une préférence qui survit ferait basculer la
            // suivante sans prévenir.
            await page.addInitScript(() => {
                window.localStorage.setItem("aurora-theme", "dark");
            });
        },
    },
    {
        name: "tour-recherche",
        path: "/backend",
        async prepare(page) {
            // Aucun raccourci clavier ne l'ouvre : le bouton est la seule
            // porte, et il s'annonce, ce qui le rend trouvable ici.
            await page.getByRole("button", { name: "Rechercher…" }).click();

            const field = page.getByPlaceholder(/^Rechercher dans le back-office/);
            await field.waitFor({ state: "visible", timeout: 5_000 });
            await field.fill(SEARCH_QUERY);
            await page.waitForTimeout(1_500);
        },
    },

    { name: "tour-publications", path: "/backend/editorial/posts" },
    { name: "tour-grille", path: "/backend", prepare: postTab(/^Contenu$/) },
    { name: "tour-entete", path: "/backend", prepare: postTab(/En-tête/) },
    { name: "tour-seo", path: "/backend", prepare: postTab(/Moteurs/) },
    { name: "tour-galerie", path: "/backend", prepare: postTab(/Galerie/) },
    {
        name: "tour-traductions",
        path: "/backend",
        // L'espagnol plutôt que le français : la carte parle d'une seule mise
        // en page pour plusieurs langues, et c'est la langue traduite qui le
        // montre. Le sélecteur de langue est au-dessus des onglets.
        async prepare(page) {
            await openPost(page);
            await page.getByRole("button", { name: "es", exact: true }).first().click();
            await page.waitForTimeout(2_000);
            await page.getByRole("tab", { name: /Paramétrage/ }).first().click().catch(() => {});
            await page.waitForTimeout(1_200);
        },
    },

    { name: "tour-types", path: "/backend/editorial/post-types" },
    { name: "tour-taxonomies", path: "/backend/editorial/taxonomies" },
    { name: "tour-menus", path: "/backend/editorial/menus" },
    { name: "tour-commentaires", path: "/backend/editorial/comments" },
    {
        // La liste des formulaires, depuis la 0.9.320 : une entrée de menu,
        // et derrière elle un tableau qui dit pour chacun s'il est en ligne,
        // combien il pose de questions et s'il reçoit des réponses.
        name: "tour-formulaires-liste",
        path: "/backend/editorial/forms",
        async prepare(page) {
            await page.locator("main").getByRole("table").first().waitFor();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // La création : un titre et un point de départ. Le modèle « Demande
        // de devis » choisi, pour montrer qu'un modèle peut porter des étapes.
        name: "tour-formulaire-modeles",
        path: "/backend/editorial/forms",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.getByRole("button", { name: /Nouveau formulaire/ }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Point de départ" }).first();
            await dialog.waitFor();
            await dialog.getByRole("textbox").first().fill("Demande de devis");
            await dialog.getByRole("radio", { name: /Demande de devis/ }).first().click();
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-formulaire-champs",
        // Les questions d'un formulaire, une question ouverte dans le panneau
        // et l'aperçu dessous : c'est l'écran où l'on compose, et la carte en
        // parle. Depuis la 0.9.320 chaque formulaire a sa page ; plus de
        // détour par la liste, ni de repli qui photographierait l'écran
        // d'avant si le clic ratait.
        path: "/backend/editorial/forms/1",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.locator("main").getByRole("button", { name: /Type de projet/ }).first().click();
            await page.waitForTimeout(1_500);
        },
    },

    {
        // La grille, choisie explicitement : un navigateur neuf ouvre la
        // médiathèque en liste sur un écran large, et la carte montre les
        // vignettes.
        name: "tour-mediatheque-grille",
        path: "/backend/ged/documents",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Vue cartes" }).click();
            await page.waitForTimeout(2_000);
        },
    },
    /**
     * La fiche d'un document : ses métadonnées et son historique de versions.
     *
     * La seconde image de la carte médiathèque. Elle illustrait déjà la page
     * et ne se refaisait pas : elle datait d'une semaine de plus que toutes
     * les autres.
     */
    {
        name: "tour-mediatheque-document",
        path: "/backend/ged/documents",
        async prepare(page) {
            // Cherché plutôt que cliqué dans la liste : la médiathèque de
            // démonstration tient sur deux pages, et celui-ci n'est pas
            // toujours sur la première. C'est celui-là qu'on veut, parce
            // qu'il porte trois versions et que la carte en parle.
            // Le champ cherche aussi dans le texte et les étiquettes depuis la
            // 0.9.327, et son exemple l'annonce : « Rechercher : titre, … ».
            // En cartes : une ligne de la liste ne s'ouvre pas au clic, une
            // carte si, et le mode est retenu d'une visite à l'autre.
            await page.locator("main").getByRole("button", { name: "Vue cartes" }).click();
            await page.waitForTimeout(1_000);
            await page.getByPlaceholder(/^Rechercher :/).fill("Visuel de campagne");
            await page.waitForTimeout(2_000);
            await page.locator("main").getByText("Visuel de campagne", { exact: false }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // La grille et sa palette : quarante-huit colonnes, les zones déjà
        // posées, et en bas tout ce qu'on peut poser. La carte énumère dix
        // sortes de zones et n'en montrait aucune.
        name: "tour-grille-palette",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            // `exact`, sinon le menu latéral gagne : « Types de contenu » contient
            // « contenu », et c'est lui que le premier résultat désigne. La
            // capture sortait alors sur l'onglet Paramétrage, qui est celui
            // d'à côté et qui ressemble assez pour qu'on ne le voie pas.
            await page.getByRole("button", { name: "Contenu", exact: true }).first().click();
            await page.waitForTimeout(2_000);
            // Jusqu'au bas de la palette : elle compte 44 types, et le haut
            // de l'onglet n'en montrait que trois rangées.
            await page
                .locator("main")
                .getByRole("button", { name: "Pile", exact: true })
                .last()
                .evaluate((el) => el.scrollIntoView({ block: "end" }));
            await page.mouse.wheel(0, 60);
            await page.waitForTimeout(1_000);
        },
    },
    // Pas de prise de l'éditeur d'une zone, et j'ai essayé trois fois.
    // L'éditeur s'ouvre sous la grille, et le défilement ne tient pas
    // jusqu'à l'obturateur : la capture ressort sur la grille, c'est-à-dire
    // en double de celle du dessus. Deux images identiques valent moins
    // qu'une seule, et celle de la palette dit déjà ce que la carte promet -
    // vingt-quatre sortes de zones à poser. À reprendre en visant le
    // conteneur qui défile vraiment, qui n'est pas la fenêtre.

    {
        // Le paramétrage d'une publication : son statut, ses dates, son type
        // et son adresse. La carte parle d'un cycle - brouillon, revue,
        // programmation, publication, archivage - et ne montrait que la liste
        // où le statut se lit, jamais l'endroit où il se décide.
        name: "tour-publications-parametrage",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            // `domcontentloaded` ne suffit pas ici : l'onglet n'existe qu'une
            // fois le composant monté, et l'éditeur garde une connexion
            // ouverte en dev, donc `load` n'arrive jamais.
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "Paramétrage" }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // L'historique : ce qui a été enregistré avant est conservé et se
        // compare. La carte le promet noir sur blanc.
        //
        // La prise n'a été possible qu'après avoir donné des révisions au
        // jeu de démonstration : les fixtures écrivent les publications en
        // direct, alors qu'une révision naît d'un enregistrement passé par
        // le gestionnaire. La modale s'ouvrait donc sur « Aucune version
        // enregistrée pour le moment », soit l'image qui réussit sans rien
        // montrer.
        name: "tour-publications-historique",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "Actions" }).first().click();
            await page.waitForTimeout(800);
            await page.getByRole("button", { name: "Historique" }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // La corbeille, qui traverse les modules : supprimer n'efface pas
        // tout de suite, et l'écran dit combien de temps il reste.
        name: "tour-publications-corbeille",
        path: "/backend/trash",
    },
    // La carte promet les réglages de l'en-tête et la prise n'en montrait
    // aucun : l'aperçu occupe toute la fenêtre et les contrôles - placement,
    // hauteur, largeur, dégradé, fondu, boutons - commencent sous le pli. On
    // descend donc jusqu'à « Hauteur », ce qui laisse le bas de l'aperçu en
    // haut du cadre : on voit ce qu'on règle et ce que ça donne.
    postTabShot("tour-entete-reglages", "En-tête", async (page) => {
        await page.getByText("Hauteur", { exact: true }).first().scrollIntoViewIfNeeded();
        await page.waitForTimeout(1_200);
    }),
    postTabShot("tour-seo-onglet", "Moteurs de recherche"),
    postTabShot("tour-galerie-onglet", "Galerie"),
    {
        // La même publication dans une autre langue : même disposition,
        // mots différents. La carte dit « un onglet par langue » et montrait
        // une page en français.
        name: "tour-multilingue-en",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "en", exact: true }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Le tableau de bord sur un autre module que l'éditorial : la carte
        // promet « un panneau par module actif, chacun avec ses chiffres ».
        name: "tour-dashboard-modules",
        path: "/backend",
        async prepare(page) {
            await page.waitForTimeout(3_000);

            // **Dans le contenu, pas dans la page entière.** « GED » nomme à
            // la fois l'onglet du tableau de bord et une section du menu
            // latéral, et `.first()` prenait la section : le menu se dépliait,
            // se décalait, l'onglet Éditorial restait ouvert, et la prise
            // montrait l'écran d'avant sous un nom qui promettait l'autre.
            // `exact: true` n'y peut rien, les deux libellés sont identiques.
            // Le menu vit hors du `<main>`, donc y limiter la recherche les
            // départage pour de bon.
            await page.locator("main").getByRole("button", { name: "GED", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // La recherche ouverte, avec ses résultats groupés par nature.
        name: "tour-recherche-resultats",
        path: "/backend",
        async prepare(page) {
            await page.waitForTimeout(3_000);
            await page.keyboard.press("Control+K");
            await page.waitForTimeout(1_200);
            // « client » et non le mot par défaut : il touche trois natures à
            // la fois - une entrée de navigation, un média, des événements -
            // et c'est le groupement que la carte promet. « aurora » ne
            // ramenait que des médias, soit une liste et pas une démonstration.
            await page.keyboard.type("client", { delay: 60 });
            await page.waitForTimeout(2_500);
        },
    },
    {
        // La modération des commentaires et ses trois états.
        name: "tour-commentaires-moderation",
        path: "/backend/editorial/comments",
    },
    {
        // Les demandes reçues par un formulaire : la carte parle de ce qu'un
        // formulaire sait faire et s'arrêtait à ses champs.
        // Les demandes vivent **sous** les champs, sur la page du
        // formulaire.
        //
        // Deux erreurs avant d'y arriver, et la seconde est partie en
        // production : chercher un onglet « Réponses » qui n'existe pas, puis
        // viser `/submissions`, qui est l'API JSON et non un écran. La prise
        // était un dump de JSON brut, et elle a illustré la carte publique
        // pendant une heure. Une adresse qui répond n'est pas une page.
        name: "tour-formulaire-reponses",
        // L'onglet Réponses, par son adresse : depuis la 0.9.320 les réponses
        // ont leur onglet, et il est dans le fragment de l'URL.
        path: "/backend/editorial/forms/1#submissions",
        async prepare(page) {
            await page.waitForTimeout(3_000);
        },
    },
    {
        // Un menu ouvert, avec ses entrées imbriquées : la carte décrit ce
        // qu'une entrée peut viser et montrait la liste des menus.
        name: "tour-menus-entrees",
        path: "/backend/editorial/menus",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("link", { name: /Navigation principale/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les termes d'une taxonomie : la carte oppose l'arbre des catégories
        // et les étiquettes à plat, sans montrer ni l'un ni l'autre.
        name: "tour-taxonomies-termes",
        path: "/backend/editorial/taxonomies",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            // La page ouvre d'office la première taxonomie, les catégories :
            // rien à cliquer, mais on vérifie que c'est bien elle, pour ne
            // jamais photographier une autre liste en croyant montrer celle-ci.
            await page.locator("main h2", { hasText: "Catégories" }).first().waitFor();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les champs d'un type de contenu : c'est ce que la carte détaille,
        // et la liste des types n'en dit rien.
        name: "tour-types-champs",
        path: "/backend/editorial/post-types",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.locator('#sidemenu a[href*="/backend/editorial/post-types/"]', { hasText: "Article" }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // La palette d'un thème : la carte parle de couleurs déduites et de
        // contrastes, donc c'est là qu'il faut regarder.
        name: "tour-themes-palette",
        path: "/backend/configuration/themes",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            // Le thème livré avec Aurora, toujours là. Pas de repli : un clic
            // raté photographiait la liste des thèmes à la place de la palette,
            // en double de tour-themes.
            await page.locator("main").getByRole("button", { name: "Actions pour Default" }).click();
            await page.getByRole("button", { name: "Modifier", exact: true }).click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les privilèges d'un compte, écran par écran : c'est la promesse
        // centrale de la carte, et elle ne montrait que la liste des comptes.
        name: "tour-privileges",
        path: "/backend/platform/users",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByTitle(/^Actions pour Jean Martin/).first().click();
            await page.waitForTimeout(1_000);
            await page.getByRole("button", { name: /^Privilèges/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les réglages et leurs onglets : la carte parle de ce qui se règle
        // sans montrer où.
        name: "tour-reglages-onglets",
        path: "/backend/configuration/settings",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Une intégration sur le gabarit commun (0.9.328) : ce qu'elle fait,
        // son état, la carte de connexion, et le mode d'emploi à côté.
        name: "tour-integrations",
        path: "/backend/configuration/settings/pexels",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await openGuides(page);
        },
    },
    {
        // Un encart « Comment ça marche » ouvert, sur un écran simple : chaque
        // écran a le sien, à côté de ce qu'il explique.
        name: "tour-encarts",
        path: "/backend/ged/categories",
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await openGuides(page);
        },
    },
    {
        // Les catégories : une par nature de document, celle qui décide où un
        // fichier est rangé. La carte en parle et ne la montrait pas.
        name: "tour-mediatheque-categories",
        path: "/backend/ged/categories",
    },
    {
        // Les étiquettes, qui traversent les catégories : un même document
        // peut en porter autant qu'il veut, là où il n'a qu'une catégorie.
        name: "tour-mediatheque-etiquettes",
        path: "/backend/ged/tags",
    },
    {
        // La carte promet « le rendu à côté de la source », et son texte de
        // remplacement décrit « une note, son rendu à côté, ses étiquettes et
        // ses liens ». La prise montrait la bibliothèque : des dossiers et des
        // vignettes, c'est-à-dire la seule chose que la carte ne dit pas.
        //
        // Par le nom et non par un identifiant : les fixtures renumérotent à
        // chaque rechargement. « Cabinet Verrier » est la seule note de la
        // démonstration qui réunisse les trois : un bandeau, un lien wiki dans
        // son texte, et un lien entrant, donc un panneau qui montre quelque
        // chose. « Sommaire des clients » a une source plus riche mais rien ne
        // pointe vers elle : le panneau s'ouvrait sur « Aucun lien entrant »,
        // au milieu d'une image qui sert à montrer que les notes se relient.
        name: "tour-notes",
        path: "/backend/notes/markdown",
        async prepare(page) {
            // Par son adresse, retrouvée dans la liste : la bande « Récemment
            // modifiées » ne la montre plus depuis que la démonstration porte
            // aussi les notes d'un espace partagé, plus récentes qu'elle.
            const id = await page.evaluate(async () => {
                const r = await fetch("/backend/notes/markdown/list", { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } });
                const j = await r.json();

                return j.notes.find((n) => "Cabinet Verrier" === n.title)?.id ?? null;
            });

            if (null === id) throw new Error("la note « Cabinet Verrier » manque à la démonstration");

            await page.goto(`${BASE_URL}/backend/notes/markdown/${id}`, { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(2_500);
            await page.getByTitle("Édition + aperçu").first().click();
            await page.waitForTimeout(1_000);

            // Le volet d'écriture est rétréci avant d'ouvrir les liens.
            // Sa largeur est retenue d'une visite à l'autre, et la valeur
            // par défaut laissait au rendu deux cent trente pixels une fois
            // le panneau sorti : le tableau de la note y était coupé en
            // plein milieu d'un en-tête, ce qui se lit comme un défaut
            // d'affichage et non comme une colonne qui continue.
            await page.evaluate(() => {
                localStorage.setItem("aurora.notes.markdown.editorWidth", "380");
            });
            await page.reload({ waitUntil: "domcontentloaded" });
            await page.waitForTimeout(2_500);

            await page.getByTitle("Afficher les liens entrants").first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // La bibliothèque, à plat et en mosaïque : chaque note montre le
        // début de son contenu en petit, comme sur une étagère. C'est la
        // première chose qu'on voit en ouvrant le module, et la carte n'en
        // montrait rien.
        name: "tour-notes-bibliotheque",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await flatten(page);
        },
    },
    {
        // La même bibliothèque en vue cartes : la grille dense, sans extrait.
        // Trois façons de regarder le même carnet, et une seule était
        // photographiée.
        name: "tour-notes-vue-cartes",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await flatten(page);
            await page.getByTitle("Cartes").first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Et en liste : titre, étiquettes, dossier, date. C'est la vue de
        // celui qui cherche une note précise plutôt que de parcourir.
        name: "tour-notes-vue-liste",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await flatten(page);
            await page.getByTitle("Liste").first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // L'habillage d'une note, où les deux choses se décident au même
        // endroit : l'image d'entête, cherchée chez Pexels et recadrée à la
        // molette, et les six apparences. Une seule fenêtre pour les deux,
        // donc une seule image.
        name: "tour-notes-entete",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire des clients/ }).first().click();
            await page.waitForTimeout(2_500);
            // **Dans le contenu, pas dans la page entière.** Depuis que
            // chaque ligne de l'arbre porte sa propre feuille d'actions,
            // « Actions pour… » existe aussi dans le menu latéral, et
            // `.first()` y attrapait la première ligne au lieu de la note
            // ouverte. L'arbre vit hors du `<main>`, ce qui les départage.
            await page.locator("main").getByTitle(/^Actions/).first().click();
            await page.waitForTimeout(800);
            await page.getByRole("button", { name: "Image d'entête" }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Le graphe, que le texte de la carte promet depuis le début sans
        // l'avoir jamais montré. Il s'ouvre depuis le menu d'une note, donc
        // il faut en ouvrir une d'abord.
        name: "tour-notes-graphe",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire des clients/ }).first().click();
            await page.waitForTimeout(2_500);
            await page.locator("main").getByTitle(/^Actions/).first().click();
            await page.waitForTimeout(700);
            await page.getByRole("button", { name: "Ouvrir le graphe" }).first().click();
            // La construction est animée : elle place les nœuds avant de se
            // stabiliser, et photographier trop tôt donne une pelote.
            await page.waitForTimeout(4_000);
        },
    },
    {
        // La page publique d'une note : l'autre promesse du texte, « montrer
        // une note à quelqu'un qui n'a pas de compte ».
        //
        // L'adresse est demandée au serveur plutôt qu'écrite ici : le jeton
        // est tiré au hasard à chaque chargement des fixtures, donc une
        // adresse en dur serait morte au premier `make demo`.
        //
        // La note aussi se retrouve par son titre. Le scénario demandait les
        // partages de la note 1, qui était le sommaire tant que la démo
        // n'avait jamais changé ; une démo rechargée par-dessus une ancienne
        // garde l'ancienne note sous ce numéro, et la carte a publié quatre
        // lignes sans image ni notes liées pendant que le vrai sommaire, sa
        // couverture et son lien « avec les notes liées » restaient à côté.
        name: "tour-notes-partage",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire des clients/ }).first().click();
            await page.waitForTimeout(2_500);

            const id = /\/markdown\/(\d+)/.exec(page.url())?.[1];

            if (undefined === id) throw new Error("la note ne s'est pas ouverte");

            const url = await page.evaluate(async (noteId) => {
                const r = await fetch(`/backend/notes/markdown/shares/${noteId}`, { headers: { Accept: "application/json" } });
                const j = await r.json();

                return j?.links?.[0]?.url ?? null;
            }, id);

            if (!url) throw new Error("aucun lien de partage dans la démonstration");

            await page.goto(url, { waitUntil: "networkidle" });
            await page.waitForTimeout(2_000);
        },
    },
    {
        // La vue de lecture : une adresse qui n'affiche **que** la note, sans
        // le menu ni le fil d'Ariane, pour qui a un compte.
        //
        // La prise montrait l'aperçu de l'éditeur, c'est-à-dire la même
        // fenêtre que la première image, en mode rendu. Deux photos du même
        // écran, et la vue qui existe précisément pour montrer une note nue
        // n'était sur aucune. Elle se distingue aussi de la page de partage,
        // qui est l'autre bout : celle-ci se lit sans compte et porte la
        // liste des notes liées quand le lien les emporte.
        //
        // Par l'adresse plutôt que par un clic : le bouton qui y mène est
        // dans le menu de la note, et ouvrir un menu pour photographier ce
        // qu'il y a derrière allonge le scénario sans rien prouver de plus.
        name: "tour-notes-apparence",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire des clients/ }).first().click();
            await page.waitForTimeout(2_500);

            const id = /\/markdown\/(\d+)/.exec(page.url())?.[1];

            if (undefined === id) throw new Error("la note ne s'est pas ouverte");

            await page.goto(`${BASE_URL}/backend/notes/markdown/${id}/read`, { waitUntil: "networkidle" });
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Le panneau par espace : son carnet d'abord, puis « Guide de
        // l'agence », que la démonstration partage avec tout le back-office.
        // Le dossier de l'espace est déplié pour qu'on voie ce qu'il range.
        name: "tour-notes-espaces",
        path: "/backend/notes/markdown",
        async prepare(page) {
            const header = page.locator("[data-space-header]").filter({ hasText: /Guide de l.agence/i }).first();
            await header.waitFor();

            // Tout replié d'abord : son carnet déplié repoussait l'espace
            // partagé sous le bord de l'image. Le bouton n'existe que si
            // quelque chose est ouvert.
            const replier = page.getByTitle("Tout replier").first();
            if (await replier.count() > 0) await replier.click();

            await page.getByRole("link", { name: /^Procédures/ }).first().click();
            await page.waitForTimeout(1_500);
            // La mosaïque, qui montre le début de chaque note ; la vue est
            // retenue d'un scénario à l'autre.
            await page.locator("main").getByTitle("Mosaïque").first().click();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // Créer un espace : la même fenêtre que pour une note ou un dossier,
        // avec qui y entre et ce qu'on y fait.
        name: "tour-notes-nouvel-espace",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByTitle("Ajouter", { exact: true }).first().click();
            await page.waitForTimeout(800);
            await page.locator('[data-add-kind="space"]').click();
            await page.locator("[data-add-name] input, input[data-add-name]").first().fill("Documentation client");
            await page.waitForTimeout(600);
        },
    },
    {
        // Les réglages d'un espace : l'accès, les membres et leur rôle, et
        // la publication sur le web avec son adresse. Le bouton n'apparaît
        // qu'au survol de l'en-tête, comme pour une vraie souris.
        name: "tour-notes-reglages-espace",
        path: "/backend/notes/markdown",
        async prepare(page) {
            const header = page.locator("[data-space-header]").filter({ hasText: /Guide de l.agence/i }).first();
            await header.hover();
            await header.locator("[data-space-settings]").click();
            await page.locator("[data-space-publication]").waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // Un espace publié, lu sans compte : son arbre et sa première note,
        // rien du back-office autour. L'adresse est celle que les fixtures
        // donnent à l'espace de démonstration.
        name: "tour-notes-publique",
        path: "/p/guide-agence",
        async prepare(page) {
            await page.locator("[data-reader-public-title]").waitFor();
            await page.waitForTimeout(1_500);
        },
    },
    { name: "tour-calendrier", path: "/backend/planning/calendar" },

    { name: "tour-contrats", path: "/backend/studio/contracts" },
    { name: "tour-trames", path: "/backend/studio/contract-templates" },
    {
        // L'aperçu d'une trame, depuis la 0.9.318 : chaque valeur venue d'une
        // variable est colorée selon sa source (exemple inventé, vraie
        // information, champ à remplir), avec la légende sous l'encadré.
        name: "tour-trame-apercu",
        path: "/backend/studio/contract-templates",
        async prepare(page) {
            // Le titre d'une trame n'est pas un lien : c'est sa version qui
            // ouvre l'éditeur.
            await page.locator("main tr").filter({ hasText: "Contrat de prestation mensuelle" })
                .getByRole("link", { name: /^Version/ }).first().click();
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.getByText("Aperçu", { exact: true }).first().click();
            await page.getByText("Le texte en couleur vient des variables.").first().waitFor();
            await page.waitForTimeout(2_000);
        },
    },
    { name: "tour-clients", path: "/backend/studio/customers" },

    /**
     * Le carrousel de l'en-tête : l'accueil de la démo en a trois diapositives.
     * La deuxième plutôt que la première, pour qu'on voie qu'elle en est une.
     * « Diapositive 2 » est un onglet, pas un bouton : visé par son texte.
     */
    {
        name: "tour-entete-carrousel",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "En-tête", exact: true }).first().click();
            await page.waitForTimeout(2_000);
            await page.locator("main").getByText("Diapositive 2", { exact: true }).first().click();
            await page.waitForTimeout(1_500);
            await page
                .locator("main")
                .getByRole("button", { name: "Ajouter une diapositive" })
                .evaluate((el) => el.scrollIntoView({ block: "center" }));
            await page.mouse.wheel(0, -260);
            await page.mouse.move(1_590, 990);
            await page.waitForTimeout(1_200);
        },
    },

    /**
     * Une variante de couleur, par la famille du « Visuel de campagne » que
     * `make demo` décline en rouge et en bleu. Derrière la fenêtre, le bandeau
     * de famille déplié montre l'original et sa variante. « Voir la famille »
     * est une étiquette de bouton, pas un texte : visé par l'attribut.
     */
    {
        name: "tour-mediatheque-variante",
        path: "/backend/ged/documents",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Vue liste" }).click();
            await page.waitForTimeout(1_500);
            await page.locator("main").locator('[aria-label="Voir la famille"], [title="Voir la famille"]').first().click();
            await page.waitForTimeout(2_500);
            await page.getByRole("button", { name: /Variante de couleur/ }).first().click();
            await page.mouse.move(1_590, 990);
            await page.waitForTimeout(2_500);
        },
    },

    /**
     * Les présentations, par l'audit et nommément : ses onze diapositives
     * passent par tous les gabarits, là où la trame n'en a que quatre.
     */
    { name: "tour-presentations", path: "/backend/studio/decks" },
    { name: "tour-presentations-editeur", path: "/backend/studio/decks", prepare: openDeck("Audit du site") },
    {
        name: "tour-presentations-diaporama",
        path: "/backend/studio/decks",
        async prepare(page) {
            await openDeck("Audit du site")(page);
            await page.locator("main").getByRole("button", { name: "Présenter" }).click();
            await page.waitForTimeout(1_500);
            // La quatrième diapositive, une photo pleine page : la première
            // n'est qu'un titre sur fond uni.
            for (let i = 0; i < 3; i++) await page.keyboard.press("ArrowRight");
            await page.waitForTimeout(1_500);
        },
    },
    /**
     * La diapo libre de l'audit, prise en main : la photo choisie montre ses
     * poignées et sa poignée de rotation, et le panneau à côté ce qu'on règle
     * sur une image. Atteinte par son libellé dans la liste, « 11. Diapo
     * libre », et non par le bouton du même nom qui en ajoute une.
     */
    {
        name: "tour-presentations-libre",
        path: "/backend/studio/decks",
        async prepare(page) {
            await openDeck("Audit du site")(page);
            await page.locator("main").getByRole("button", { name: /^\d+\. Diapo libre/ }).first().click();
            await page.waitForTimeout(1_500);
            await page.locator("main .fc-stage .fe-image").first().click();
            // Le clic a fait défiler la page jusqu'à la photo : on remonte,
            // pour que le bouton « Présenter » ne soit pas coupé en haut.
            await page.mouse.wheel(0, -2_000);
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-presentations-libre-diaporama",
        path: "/backend/studio/decks",
        async prepare(page) {
            await openDeck("Audit du site")(page);
            await page.locator("main").getByRole("button", { name: /^\d+\. Diapo libre/ }).first().click();
            await page.waitForTimeout(1_000);
            await page.locator("main").getByRole("button", { name: "Présenter" }).click();
            await page.waitForTimeout(1_500);
            // Les trois cartes entrent une à une : trois pressions, et le
            // temps de leur animation.
            for (let i = 0; i < 3; i++) {
                await page.keyboard.press("ArrowRight");
                await page.waitForTimeout(700);
            }
            await page.waitForTimeout(1_500);
        },
    },
    {
        name: "tour-avenant-scelle",
        // Le contrat conclu qui a un avenant : le seul état qui montre à la
        // fois le sceau, les deux signatures et la chaîne d'avenants. Atteint
        // par sa référence dans la liste plutôt que par un identifiant en dur,
        // qui dépend de ce que la base contenait quand les fixtures ont tourné.
        // La référence sans suffixe : celle en « -A1 » est l'avenant lui-même.
        path: "/backend/studio/contracts",
        async prepare(page) {
            const row = page
                .locator("main table tbody tr")
                .filter({ hasText: "Conclu" })
                .filter({ hasText: "Roux Photographie" })
                .filter({ hasNotText: "-A1" })
                .first();
            await row.locator("a[href*='/backend/studio/contracts/']").first().click();
            await page.waitForLoadState("networkidle");
            await page.waitForTimeout(1_500);
        },
    },

    /**
     * Le texte d'un contrat adapté pour son client : le brouillon de la démo
     * porte une clause ajoutée, et la colonne de droite la montre comme une
     * différence avec la trame. Atteint par la fiche, comme un lecteur le
     * ferait, plutôt que par un identifiant qui change à chaque chargement.
     */
    {
        name: "tour-contrat-adapte",
        path: "/backend/studio/contracts",
        async prepare(page) {
            const row = page
                .locator("main table tbody tr")
                .filter({ hasText: "Brouillon" })
                .filter({ hasText: "Atelier Dupont" })
                .first();
            await row.locator("a[href*='/backend/studio/contracts/']").first().click();
            await page.waitForLoadState("networkidle");
            await page.locator("main").getByRole("link", { name: "Voir le texte" }).first().click();
            await page.waitForLoadState("networkidle");
            await page.waitForTimeout(2_000);
        },
    },

    /**
     * Le tableau d'un espace client.
     *
     * Par la liste plutôt que par une adresse : les identifiants changent à
     * chaque rechargement des fixtures, et un parcours qui code un
     * `/workspace/8` en dur photographie une page d'erreur le lendemain.
     *
     * **Nommément, et pas le premier venu.** La démonstration porte plusieurs
     * espaces dont un vide ; ouvrir le premier de la liste donnait cinq
     * colonnes qui disent toutes « Rien ici pour le moment », c'est-à-dire
     * une capture qui réussit et ne montre rien.
     *
     * Puis un clic sur Contenus : la vue ouverte est retenue d'une visite à
     * l'autre, donc l'espace peut s'ouvrir sur Notes selon ce qui a été
     * regardé avant.
     */
    { name: "tour-espaces-clients", path: SPACES, prepare: contents("Kanban") },

    /** Le même plan en liste : la carte promet « en kanban ou en liste ». */
    { name: "espace-liste", path: SPACES, prepare: contents("Liste") },

    /**
     * Une fiche jusqu'en bas : son fil d'échange et l'avis du client, ici
     * « à reprendre ». La fenêtre défile toute seule jusqu'au fil, que la
     * prise du haut de la fiche coupait.
     */
    {
        name: "espace-fiche-echanges",
        path: SPACES,
        async prepare(page) {
            await openCard("Offre de rentrée")(page);
            await page.evaluate(() => {
                const dialog = document.querySelector("[role='dialog']");
                const scroller = [...dialog.querySelectorAll("*")].find((el) => el.scrollHeight > el.clientHeight + 20 && /(auto|scroll)/.test(getComputedStyle(el).overflowY));
                if (scroller) scroller.scrollTop = scroller.scrollHeight;
            });
            await page.waitForTimeout(800);
        },
    },

    /**
     * Envoyer à relire : ce qui part chez le client, et comment.
     *
     * Le bandeau, pas la fenêtre de confirmation. Depuis la 0.9.320 c'est lui
     * qui dit ce qui attend et ce que le client reçoit ; ouverte par-dessus, la
     * fenêtre le recouvrait d'un voile et la carte montrait l'ancien geste.
     */
    {
        name: "espace-envoyer-relire",
        path: SPACES,
        async prepare(page) {
            await contents("Kanban")(page);
            await page.locator("main").getByRole("button", { name: /^Envoyer à relire/ }).first().waitFor();
            await page.waitForTimeout(800);
        },
    },

    /**
     * Les autres vues du même espace.
     *
     * **Elles illustrent déjà la carte, et n'étaient pas reproductibles.**
     * Prises à la main une fois, elles ont vieilli sans que rien ne le dise :
     * celle du côté client montrait encore une page qui empilait tout et un
     * invité signé de son adresse e-mail, deux versions après que l'une et
     * l'autre aient disparu. C'est exactement ce que ce fichier existe pour
     * éviter.
     */
    { name: "espace-calendrier", path: SPACES, prepare: spaceView("Calendrier") },
    { name: "espace-fichiers", path: SPACES, prepare: spaceView("Fichiers") },
    { name: "espace-discussion", path: SPACES, prepare: spaceView("Discussion") },
    { name: "espace-notes", path: SPACES, prepare: spaceView("Notes") },
    { name: "espace-informations", path: SPACES, prepare: spaceView("Informations") },
    { name: "espace-liens", path: SPACES, prepare: spaceView("Ressources") },

    /** Une fiche ouverte : le titre, la date, ses fichiers et son fil. */
    {
        name: "espace-une-fiche",
        path: SPACES,
        async prepare(page) {
            await openCard("Portrait de l'équipe")(page);
        },
    },

    /**
     * Un espace ouvert pour un prospect.
     *
     * L'onglet a son propre compteur, et c'est ce que la capture montre : on
     * travaille avec quelqu'un avant qu'il signe.
     */
    {
        name: "espaces-prospects",
        path: SPACES,
        async prepare(page) {
            await page.getByRole("button", { name: /^Prospects/ }).first().click();
            await page.waitForTimeout(1_500);
        },
    },

    /**
     * La page que le client ouvre, par une vraie adresse.
     *
     * **Un lien émis ici même, et non l'aperçu du studio.** L'aperçu porte un
     * bandeau qui prévient que ce n'est pas ce que le client a reçu : vrai
     * dans l'application, trompeur sur une carte qui promet de montrer ce que
     * le client voit. Le lien se crée donc comme le studio le crée, et
     * l'adresse se lit là où l'écran l'affiche une fois - c'est la seule fois
     * où elle existe en clair.
     */
    {
        name: "espace-cote-client",
        path: SPACES,
        prepare: openClientSide,
    },

    /** Ses livrables, du même côté : ce qu'on lui a écrit, publié. */
    {
        name: "espace-cote-client-livrables",
        path: SPACES,
        async prepare(page) {
            await openClientSide(page);
            await page.getByRole("button", { name: "Livrables", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },

    /** Les livrables d'un espace côté studio : l'audit de la démo, publié. */
    { name: "espace-livrables", path: SPACES, prepare: spaceView("Livrables") },

    /** Les réglages d'un espace : son Drive, son fuseau, ses accès (0.9.328). */
    { name: "espace-reglages", path: SPACES, prepare: spaceView("Réglages") },

    /**
     * Les liens de lecture de l'audit, ouverts depuis son éditeur.
     *
     * Atteint par l'espace plutôt que par un identifiant : il change à chaque
     * rechargement de la démo.
     */
    {
        name: "tour-publications-liens-lecture",
        path: SPACES,
        async prepare(page) {
            await spaceView("Livrables")(page);
            await page.goto(await deliverableEditUrl(page), { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(4_000);
            // Rangés dans le menu « Actions » de l'éditeur, avec l'aperçu.
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.waitForTimeout(500);
            await page.getByText("Liens de lecture", { exact: true }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_500);
        },
    },

    /**
     * La page qu'un lien de lecture ouvre : l'audit, sans le site autour.
     *
     * L'adresse se demande au serveur, qui la rend avec la liste des liens :
     * le jeton est tiré au hasard à chaque chargement de la démo. Attendue
     * assez longtemps pour que les chiffres clés aient fini de compter.
     */
    {
        name: "tour-publications-page-lecture",
        path: SPACES,
        async prepare(page) {
            await spaceView("Livrables")(page);
            const edit = new URL(await deliverableEditUrl(page));
            const links = `${edit.pathname}/links`;
            const url = await page.evaluate(async (path) => {
                const response = await fetch(path, { headers: { "X-Requested-With": "XMLHttpRequest" } });

                return (await response.json()).links[0].url;
            }, links);
            const response = await page.goto(url, { waitUntil: "networkidle" });
            await assertPage(page, response, url);
            await page.waitForTimeout(3_500);
        },
    },

    /**
     * La page « La corbeille » du tour.
     *
     * La démonstration en remplit plusieurs, à des dates différentes : une
     * publication, trois documents, un dossier, une catégorie et deux notes.
     * L'écran s'ouvre sur la plus pleine, les documents.
     */
    {
        name: "tour-corbeille",
        path: "/backend/trash",
        async prepare(page) {
            await page.waitForTimeout(1_500);
        },
    },
    {
        // Un autre module dans le même écran : la publication supprimée,
        // avec le lien vers la liste d'où elle vient.
        name: "tour-corbeille-publications",
        path: "/backend/trash",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: /Publications/ }).first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Vider demande confirmation, et dit que c'est sans retour.
        name: "tour-corbeille-vider",
        path: "/backend/trash",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Vider", exact: true }).click();
            await page.waitForTimeout(1_000);
        },
    },

    /**
     * Les deux gestes de la liste des publications que la carte
     * « Publier, programmer, archiver » ne montrait pas : agir sur plusieurs
     * lignes à la fois, et dupliquer.
     *
     * La barre de sélection porte son propre bouton « Actions », en plus de
     * celui de la page : c'est le second.
     */
    {
        name: "tour-publications-selection",
        path: "/backend/editorial/posts",
        async prepare(page) {
            const cases = page.locator("main tbody input[type=checkbox]");

            for (const ligne of [3, 4, 5]) {
                await cases.nth(ligne).check();
            }

            await page.waitForTimeout(500);
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).nth(1).click();
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-publications-dupliquer",
        path: "/backend/editorial/posts",
        async prepare(page) {
            // La première ligne, quelle qu'elle soit : l'ordre de la liste
            // suit la date de modification, et un titre nommé ici passait en
            // page deux au rechargement suivant de la démo.
            await page.locator("main").getByRole("button", { name: /^Actions pour / }).first().click();
            await page.waitForTimeout(800);
        },
    },

    /**
     * Le profil, pour « Comptes, rôles et privilèges ».
     *
     * La phrase d'humeur est tapée et pas enregistrée : l'enregistrer la
     * mettrait sur toutes les autres prises qui montrent ce compte.
     */
    {
        name: "tour-profil",
        path: "/backend/general/profile",
        async prepare(page) {
            await page.locator("main textarea").first().fill("En séance photo jusqu'à 18 h, je réponds le soir.");
            await page.locator("main textarea").first().blur();
            await page.waitForTimeout(600);
        },
    },
    {
        // Le menu latéral à sa main : une couleur pour une section, un module
        // masqué. Rien n'est enregistré, pour la même raison.
        name: "tour-profil-menu",
        path: "/backend/general/profile/sidemenu",
        async prepare(page) {
            await page.locator("main [title='amber']").first().click();
            const ligne = page.locator("main .divide-y > div", { has: page.getByText("Corbeille", { exact: true }) }).first();
            await ligne.locator("button, [role=switch], input[type=checkbox]").last().click();
            await page.waitForTimeout(800);
        },
    },

    /**
     * Ce que le visiteur remplit en dehors d'un formulaire, pour la carte
     * « Les formulaires » : un rendez-vous et un sondage, sur la page des
     * nouveaux blocs de la démonstration.
     *
     * Sans session, comme le site public. Le rendez-vous est rempli et pas
     * envoyé : l'envoyer poserait un événement dans l'agenda de la démo à
     * chaque prise, et prendrait le créneau.
     */
    {
        name: "tour-reservation",
        path: "/fr/page/nouveaux-blocs",
        anonymous: true,
        async prepare(page) {
            const zone = page.locator("[data-booking]").first();
            await zone.locator("[data-booking-slots]:not([hidden]) [data-booking-slot]").nth(2).click();
            await zone.locator("[data-booking-name]").fill("Camille Laurent");
            await zone.locator("[data-booking-email]").fill("camille.laurent@example.com");
            await zone.locator("[data-booking-message]").fill("Séance portrait en extérieur, si possible en fin de journée.");
            await zone.locator("[data-booking-message]").blur();
            await placeAt(page, zone, 140);
        },
    },
    {
        // Le vote fait apparaître les résultats. Déjà voté depuis ce
        // navigateur, ils sont là d'emblée : le bouton n'est cliqué que s'il
        // attend encore une voix.
        name: "tour-sondage",
        path: "/fr/page/nouveaux-blocs",
        anonymous: true,
        async prepare(page) {
            const titre = page.getByText("Quel format préférez-vous ?", { exact: true }).first();
            await titre.scrollIntoViewIfNeeded();

            // Visible, et pas seulement présent : les résultats sont dans la
            // page avant le vote, cachés, et les compter suffisait à ne
            // jamais voter.
            if (!(await page.getByText(/^Votes :/).first().isVisible())) {
                await page.getByRole("button", { name: /Les réels/ }).click();
                await page.getByText(/^Votes :/).first().waitFor({ state: "visible" });
            }

            await placeAt(page, titre, 220);
        },
    },

    { name: "tour-utilisateurs", path: "/backend/platform/users" },
    { name: "tour-audit", path: "/dev/dashboard/audit" },
    { name: "tour-themes", path: "/backend/configuration/themes" },
    { name: "tour-reglages", path: "/backend/configuration/settings/general" },

    {
        name: "tour-calendrier-semaine",
        path: "/backend/planning/calendar",
        async prepare(page) {
            await page.getByRole("button", { name: /^Semaine$/ }).click();
            await page.waitForTimeout(1_000);

            // La grille s'ouvre sur l'heure courante, donc une capture prise
            // le soir montre un après-midi vide alors que les événements de
            // démonstration sont le matin. La molette au-dessus de la grille
            // est ce que le composant écoute ; fixer `scrollTop` sur un
            // élément deviné, non. Remontée à fond d'abord, puis descendue
            // d'un nombre fixe : descendre d'un delta seul atterrirait
            // ailleurs selon l'heure de la prise.
            await page.mouse.move(1000, 600);
            await page.mouse.wheel(0, -2_000);
            await page.waitForTimeout(300);
            await page.mouse.wheel(0, 530);
            await page.waitForTimeout(600);
        },
    },

    /**
     * Le site public, vu sans session.
     *
     * Le bandeau d'administration s'affiche au-dessus d'un site quand on le
     * visite connecté, et il n'a rien à faire sur une image qui montre ce que
     * voit un visiteur.
     */
    /**
     * Le site public, tel qu'un visiteur le voit.
     *
     * Refait le 28/09/2026 avec la démonstration habillée (photos, vrais
     * textes, carrousel d'accueil) : l'accueil, une page de service, une
     * réalisation, la visionneuse, le site sur téléphone et le contact. Tout
     * sans session, pour que la barre d'administration n'y soit pas.
     */
    {
        // L'accueil et son carrousel, sur la première diapositive : elle ne
        // tourne qu'au bout de sept secondes, et la prise est faite avant.
        name: "tour-site-public",
        path: "/fr",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Une page composée : l'accroche, puis image et texte en alternance.
        name: "tour-site-public-service",
        path: "/fr/services/developpement-web",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(1_500);
        },
    },
    {
        // Une réalisation : la grande image, le récit et les chiffres.
        // Défilée jusqu'au récit, que la grande image cache sinon.
        name: "tour-site-public-realisation",
        path: "/fr/projets/projet-lumen",
        anonymous: true,
        async prepare(page) {
            await page.evaluate(() => window.scrollTo(0, 380));
            await page.waitForTimeout(1_500);
        },
    },
    {
        // La visionneuse, ouverte sur la galerie de l'accueil.
        name: "tour-site-public-galerie",
        path: "/fr",
        anonymous: true,
        async prepare(page) {
            const opener = page.locator("[data-gallery-open]").first();
            await opener.scrollIntoViewIfNeeded();
            await page.waitForTimeout(800);
            await opener.click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // Trois pages sur téléphone, côte à côte. Chacune est prise dans un
        // contexte de 390 px de large, où le site se met en page comme sur un
        // vrai téléphone, puis les trois images sont posées sur la toile. Des
        // cadres n'iraient pas : le site refuse d'être affiché dans une page
        // qui n'est pas la sienne.
        name: "tour-site-public-telephone",
        path: "/fr",
        anonymous: true,
        async prepare(page) {
            const phone = await browser.newContext({
                viewport: { width: 390, height: 844 },
                deviceScaleFactor: 1,
                isMobile: true,
                hasTouch: true,
                colorScheme: "dark",
                reducedMotion: "reduce",
            });
            const shots = [];
            for (const path of ["/fr", "/fr/services/photographie", "/fr/projets/projet-atlas"]) {
                const tab = await phone.newPage();
                await tab.goto(`${BASE_URL}${path}`, { waitUntil: "networkidle" });
                await tab.addStyleTag({ content: ".sf-toolbar,.sf-minitoolbar{display:none!important}" });
                await tab.waitForTimeout(1_200);
                shots.push((await tab.screenshot()).toString("base64"));
            }
            await phone.close();

            await page.setContent(`<!doctype html><html><body style="margin:0;height:1000px;display:flex;align-items:center;justify-content:center;gap:56px;background:radial-gradient(ellipse at 50% 40%,#12302a,#030712 75%)">${shots
                .map((shot) => `<div style="padding:10px;border-radius:46px;background:#0b0f17;box-shadow:0 30px 60px rgba(0,0,0,.6),inset 0 0 0 1px rgba(255,255,255,.12)"><img src="data:image/png;base64,${shot}" style="display:block;width:390px;height:844px;border-radius:36px"></div>`)
                .join("")}</body></html>`);
            await page.waitForTimeout(300);
        },
    },
    {
        // Une seconde page publique, celle qui porte le formulaire : elle
        // montre une autre composition et le rendu d'un formulaire côté
        // visiteur.
        //
        // J'ai d'abord voulu le même écran en mode clair, la carte promettant
        // « un mode sombre et un mode clair ». Deux essais pour rien : le
        // site public n'inclut pas le script d'amorçage du back-office, donc
        // `aurora-theme` n'y est lu par personne, et il ne suit pas non plus
        // `prefers-color-scheme` - la palette est celle du thème actif, et
        // celui de la démonstration est sombre. Il faudrait changer de thème
        // pour le montrer, ce qui est un autre sujet.
        name: "tour-site-public-contact",
        path: "/fr/page/contact",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Les notes d'une version, en français et du point de vue de ce qui
        // change à l'écran : c'est ce que la carte promet, et la liste des
        // versions ne le montre pas.
        //
        // Sans session, comme la liste : c'est GitHub, pas l'application.
        name: "tour-release-notes",
        url: "https://github.com/AxelRaboit/aurora-core/releases/latest",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("button", { name: /Accept|Reject|Refuser/ }).first().click().catch(() => {});
            await page.waitForTimeout(1_500);
        },
    },
    {
        // Les permissions, côté développeur : la carte parle de ce que
        // l'outil fait par défaut, et l'audit seul n'en montrait qu'une part.
        name: "tour-permissions",
        path: "/dev/dashboard/permissions",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },

    /**
     * Les versions publiées, chez GitHub.
     *
     * **La seule capture qui ne vient pas de l'application.** La carte parle
     * de la façon dont les versions sortent, et c'est la page des releases
     * qui le montre. Publique, donc prise sans session.
     */
    {
        name: "tour-releases",
        url: "https://github.com/AxelRaboit/aurora-core/releases",
        anonymous: true,
        async prepare(page) {
            // Le bandeau de cookies et l'invite de connexion couvrent le haut
            // de la page pour un visiteur non identifié.
            await page.getByRole("button", { name: /Accept|Reject|Refuser/ }).first().click().catch(() => {});
            await page.waitForTimeout(1_500);
        },
    },
];

async function login(page) {
    await page.goto(`${BASE_URL}/backend/platform/login`, { waitUntil: "domcontentloaded" });
    await page.locator("input[type='email'], input[name*='email']").first().fill(EMAIL);
    await page.locator("input[type='password']").first().fill(PASSWORD);
    await page.locator("button[type='submit']").first().click();
    await page.waitForURL(/\/backend/, { timeout: 15_000 });
}

/**
 * Everything that belongs to the developer rather than to the product.
 *
 * The Symfony toolbar is the obvious one. The rest is quieter and shows up
 * only once the picture is beside the others: a focus ring left on whatever
 * was clicked, and the caret blinking in a field.
 */
async function hideChrome(page) {
    await page.addStyleTag({
        content: `
            .sf-toolbar, .sf-minitoolbar, #sfToolbarMainContent, #sfToolbarClearer { display: none !important; }
            /* La version sous le logo. Sur une instance locale elle vaut
               « dev », et c'est la seule chose de ces images qui dise à un
               client qu'il regarde une machine de développement plutôt que
               le produit. En production elle porterait un numéro, qui ne lui
               apprend rien non plus. */
            [data-app-version] { display: none !important; }
            *, *::before, *::after { caret-color: transparent !important; }
            :focus-visible { outline: none !important; }
        `,
    });
}

const wanted = process.argv.slice(2);
const shots = wanted.length === 0 ? SHOTS : SHOTS.filter((shot) => wanted.includes(shot.name));

if (shots.length === 0) {
    console.error(`aucune capture nommee ${wanted.join(", ")}`);
    console.error(`disponibles : ${SHOTS.map((shot) => shot.name).join(", ")}`);
    process.exit(1);
}

await mkdir(outDir, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 1,
    locale: "fr-FR",
    timezoneId: "Europe/Paris",
    // The tour is shown in the dark theme, which is what the twenty-eight
    // existing captures are in.
    colorScheme: "dark",
});
// Les encarts « Comment ça marche » repliés, sur chaque page : ouverts, ils
// posent un bloc de texte en tête de chaque écran et poussent ce que la carte
// montre sous la ligne de flottaison. Le choix est commun à tous les encarts
// et retenu dans le navigateur (`aurora.guides.open`), donc il se pose avant
// chaque chargement ; une prise qui veut l'encart ouvert le déplie dans son
// `prepare` (`openGuides`), et le chargement suivant le replie de nouveau.
await context.addInitScript(() => {
    window.localStorage.setItem("aurora.guides.open", "0");
});

const page = await context.newPage();

await login(page);

/**
 * Le contexte sans session, créé seulement si une capture en demande un.
 *
 * Deux captures montrent ce que voit quelqu'un qui n'est pas connecté : le
 * site public, qui porterait sinon le bandeau d'administration, et la page
 * des versions chez GitHub. Ouvrir ce second navigateur pour les vingt autres
 * serait du temps perdu à chaque lancement.
 */
let anonymous = null;

async function anonymousPage() {
    if (null === anonymous) {
        anonymous = await browser.newContext({
            viewport: VIEWPORT,
            deviceScaleFactor: 1,
            locale: "fr-FR",
            timezoneId: "Europe/Paris",
            colorScheme: "dark",
        });
    }

    return anonymous.newPage();
}

/**
 * Refuser de photographier ce qui n'est pas la page attendue.
 *
 * Deux prises sont parties en production sans que personne ne s'en aperçoive :
 * un dump de JSON brut, parce que `/submissions` est l'API et non un écran,
 * et la trace d'exception Symfony d'un 404, chemin de disque compris, sur la
 * carte qui présente le site public. Playwright réussit dans les deux cas :
 * l'adresse répond, donc `goto` est content, et le fichier s'écrit.
 *
 * **Une adresse qui répond n'est pas une page.** On vérifie donc le code, le
 * type de contenu, et la signature de la page d'erreur de Symfony, et on
 * échoue avant d'écrire plutôt que de laisser relire l'image à quelqu'un.
 */
async function assertPage(target, response, address) {
    const status = response?.status();

    if (undefined !== status && status >= 400) {
        throw new Error(`${address} répond ${status}`);
    }

    const type = response?.headers()["content-type"] ?? "";

    if ("" !== type && !type.includes("text/html")) {
        throw new Error(`${address} renvoie ${type.split(";")[0]}, pas une page`);
    }

    const symfony = await target.evaluate(
        () => null !== document.querySelector(".exception-summary, #traces-text, .sf-reset .exception"),
    );

    if (symfony) {
        throw new Error(`${address} affiche une exception Symfony`);
    }
}

let failed = 0;

for (const shot of shots) {
    const file = resolve(outDir, `${shot.name}.png`);
    const address = shot.url ?? `${BASE_URL}${shot.path}`;

    let target = page;

    try {
        if (true === shot.anonymous) {
            target = await anonymousPage();
        }

        // `networkidle` attend un silence que GitHub n'offre jamais tout à
        // fait ; pour une adresse externe, le document chargé suffit et le
        // `prepare` fait le reste de l'attente.
        if (shot.before) await shot.before(target);

        const response = await target.goto(address, {
            waitUntil: undefined === shot.url ? "networkidle" : "domcontentloaded",
        });

        await assertPage(target, response, address);
        await hideChrome(target);

        if (shot.prepare) {
            await shot.prepare(target);
            await hideChrome(target);
        }

        await target.screenshot({ path: file });

        if (shot.after) await shot.after(target);

        console.log(`+ ${shot.name} -> var/screenshots/${shot.name}.png`);
    } catch (error) {
        failed += 1;
        console.error(`! ${shot.name} : ${error.message.split("\n")[0]}`);
    } finally {
        if (target !== page) {
            await target.close();
        }
    }
}

await browser.close();

process.exit(failed === 0 ? 0 : 1);
