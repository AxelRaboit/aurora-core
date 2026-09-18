/**
 * Captures the screens the documentation pages are illustrated with.
 *
 * Local only, demo data only: these pictures go on a public page.
 *
 * Usage: node var/doc-screenshots/capture-doc.mjs [name...]
 * Output: var/doc-screenshots/out/<name>.png
 */
import { chromium } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const outDir = resolve(here, "../../var/doc-screenshots/out");
/**
 * Le meme hote que `capture-steps.mjs`, qui lui en depend : le jeton
 * d'abonnement au hub Mercure voyage dans un cookie, et un cookie appartient
 * a un hote. Deux bases differentes pour deux outils qui photographient la
 * meme application seraient une difference a redecouvrir.
 */
const BASE = process.env.DOC_BASE_URL ?? "http://localhost:8000";
const VIEWPORT = { width: 1600, height: 1000 };

const settle = async (page, ms = 1200) => page.waitForTimeout(ms);

/**
 * Opens an existing publication rather than a blank one.
 *
 * By its address rather than by clicking a row: the list is a component, not a
 * page of links - a row has no href, and its actions menu is a custom popover
 * with no menu role to aim at. The id comes from the demo fixtures, which is
 * the only reason a literal is acceptable here.
 */
const DEMO_POST_ID = process.env.DOC_POST_ID ?? "1";

async function openFirstPost(page) {
  // `domcontentloaded`, not `load`: the editor keeps a connection open in dev
  // and the load event never fires, so the shot waited out its timeout on a
  // page that had been ready for seconds.
  await page.goto(`${BASE}/backend/editorial/posts/${DEMO_POST_ID}/edit`, { waitUntil: "domcontentloaded" });
  await settle(page, 4000);
}

const tab = (name) => async (page) => {
  await openFirstPost(page);
  await page.getByRole("tab", { name }).first().click().catch(async () => {
    await page.getByRole("button", { name }).first().click();
  });
  await settle(page, 1500);
};

const SHOTS = [
  { name: "dashboard", path: "/backend" },
  {
    name: "search-palette",
    path: "/backend",
    async prepare(page) {
      await page.getByRole("button", { name: "Rechercher…" }).click();
      const field = page.getByPlaceholder(/Rechercher des contenus/);
      await field.waitFor({ state: "visible", timeout: 5000 });
      await field.fill("aurora");
      await settle(page, 1500);
    },
  },
  {
    // La cloche, pas l'adresse du même nom : /backend/notifications est le
    // point d'API qui la nourrit, et la photographier donnait un pavé de
    // JSON sur fond sombre. Les notifications n'ont pas de page.
    name: "notifications",
    path: "/backend",
    async prepare(page) {
      await page.getByRole("button", { name: /^Notifications/ }).first().click();
      await settle(page, 1500);
    },
  },
  { name: "profile", path: "/backend/general/profile" },
  { name: "sidemenu", path: "/backend/general/profile/sidemenu" },

  { name: "posts", path: "/backend/editorial/posts" },
  { name: "post-editor-content", prepare: tab(/^Contenu$/) },
  { name: "post-editor-header", prepare: tab(/En-tête/) },
  { name: "post-editor-settings", prepare: tab(/Paramétrage/) },
  { name: "post-editor-appearance", prepare: tab(/Apparence/) },
  { name: "post-editor-seo", prepare: tab(/Moteurs/) },
  { name: "post-editor-gallery", prepare: tab(/Galerie/) },
  { name: "post-types", path: "/backend/editorial/post-types" },
  { name: "taxonomies", path: "/backend/editorial/taxonomies" },
  { name: "menus", path: "/backend/editorial/menus" },
  { name: "comments", path: "/backend/editorial/comments" },
  { name: "forms", path: "/backend/editorial/forms" },
  // L'écran, pas le point d'API du même nom : /backend/editorial/captcha/settings
  // répond du JSON, que le navigateur affichait sur fond sombre - et la page
  // « Le captcha » a publié un rectangle noir. Le réglage est un onglet de
  // l'écran Configuration. Même erreur pour Pexels et pour le graphe.
  { name: "captcha", path: "/backend/configuration/settings/captcha" },

  { name: "ged-documents", path: "/backend/ged/documents" },
  { name: "ged-categories", path: "/backend/ged/categories" },
  { name: "ged-tags", path: "/backend/ged/tags" },
  { name: "ged-pexels", path: "/backend/configuration/settings/pexels" },

  { name: "planning-month", path: "/backend/planning/calendar" },
  {
    name: "planning-week",
    path: "/backend/planning/calendar",
    async prepare(page) {
      await page.getByRole("button", { name: /^Semaine$/ }).click();
      await settle(page, 1000);
      await page.mouse.move(1000, 600);
      await page.mouse.wheel(0, -2000);
      await settle(page, 300);
      await page.mouse.wheel(0, 530);
      await settle(page, 600);
    },
  },

  { name: "notes", path: "/backend/notes/markdown" },
  {
    name: "notes-graph",
    path: "/backend/notes/markdown",
    async prepare(page) {
      // Le graphe est une surcouche de l'écran des notes, pas une adresse :
      // /backend/notes/markdown/graph est le point d'API qui le nourrit.
      await page.getByRole("button", { name: /Ouvrir le graphe/ }).first().click();
      await settle(page, 2500);
    },
  },

  { name: "customers", path: "/backend/studio/customers" },
  { name: "contracts", path: "/backend/studio/contracts" },
  { name: "templates", path: "/backend/studio/contract-templates" },

  { name: "users", path: "/backend/platform/users" },
  // One picture per settings tab: the thirteen pages of the Configuration
  // rubric each name a group, and a single shot of the first tab would have
  // illustrated twelve of them with somebody else's screen.
  ...["general", "reading", "localization", "branding", "appearance", "seo",
      "system", "email", "media", "sequences", "navigation", "studio"]
      .map((tabId) => ({ name: `settings-${tabId}`, path: `/backend/configuration/settings/${tabId}` })),
  { name: "themes", path: "/backend/configuration/themes" },

  { name: "audit", path: "/dev/dashboard/audit" },
  { name: "permissions", path: "/dev/dashboard/permissions" },
  { name: "mount-points", path: "/dev/dashboard/mount-points" },
  { name: "dev-users", path: "/dev/dashboard/users" },
  { name: "modules", path: "/dev/dashboard/modules" },
  { name: "access-requests", path: "/dev/dashboard/access-requests" },
];

async function login(page) {
  await page.goto(`${BASE}/backend/platform/login`, { waitUntil: "domcontentloaded" });
  await page.locator("input[type='email']").first().fill("dev@aurora.app");
  await page.locator("input[type='password']").first().fill("password");
  await page.locator("button[type='submit']").first().click();
  await page.waitForURL(/\/backend/, { timeout: 20000 });
}

async function hideChrome(page) {
  await page.addStyleTag({
    content: `
      .sf-toolbar, .sf-minitoolbar, #sfToolbarMainContent, #sfToolbarClearer { display: none !important; }
      *, *::before, *::after { caret-color: transparent !important; }
      :focus-visible { outline: none !important; }
    `,
  }).catch(() => {});
}

const wanted = process.argv.slice(2);
const shots = wanted.length === 0 ? SHOTS : SHOTS.filter((s) => wanted.includes(s.name));

await mkdir(outDir, { recursive: true });

const browser = await chromium.launch();
const ctx = await browser.newContext({
  viewport: VIEWPORT, deviceScaleFactor: 1, locale: "fr-FR",
  timezoneId: "Europe/Paris", colorScheme: "dark",
});
ctx.setDefaultTimeout(20000);
const page = await ctx.newPage();
await login(page);

let failed = 0;
for (const shot of shots) {
  try {
    if (shot.path) {
      await page.goto(`${BASE}${shot.path}`, { waitUntil: "load" });
      await settle(page, 1800);
    }
    if (shot.prepare) await shot.prepare(page);
    await hideChrome(page);
    await page.screenshot({ path: resolve(outDir, `${shot.name}.png`) });
    console.log(`+ ${shot.name}`);
  } catch (e) {
    failed += 1;
    console.log(`! ${shot.name} : ${e.message.split("\n")[0]}`);
  }
}

await browser.close();
console.log(`--- ${shots.length - failed}/${shots.length}`);
