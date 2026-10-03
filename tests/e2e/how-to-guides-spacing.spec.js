import { expect, test } from "@playwright/test";

/**
 * Un encart « Comment ça marche » ne touche jamais le bord du bloc qui le
 * contient.
 *
 * L'encart ne porte pas de marge : c'est le conteneur qui l'espace, par son
 * `gap` ou son padding (voir `AppGuide.vue`). Le jour où l'un d'eux n'en a pas,
 * l'encart colle à la carte, et rien d'autre ne le dit : c'est arrivé à la
 * bibliothèque des notes, posée entre un entête et un contenu qui avaient
 * chacun leur padding, et lui aucun (1 pixel du bord, contre 13 une fois
 * corrigé).
 *
 * Mesuré dans le navigateur plutôt que lu sur des classes, pour la même raison
 * que la visionneuse : ce qui casse, c'est la mise en page.
 *
 * Contre la démonstration (`make demo`), avec le compte des fixtures. Un
 * encart volontairement encastré (`:rounded="false"`) est laissé de côté : il
 * est fait pour toucher un bord.
 */
const SCREENS = [
    "/backend",
    "/backend/editorial/posts",
    "/backend/editorial/posts/1/edit",
    "/backend/editorial/forms",
    "/backend/editorial/comments",
    "/backend/editorial/menus",
    "/backend/editorial/post-types",
    "/backend/editorial/taxonomies",
    "/backend/editorial/post-galleries",
    "/backend/ged/documents",
    "/backend/ged/categories",
    "/backend/ged/tags",
    "/backend/notes/markdown",
    "/backend/planning/calendar",
    "/backend/studio/contracts",
    "/backend/studio/contract-templates",
    "/backend/studio/customers",
    "/backend/studio/decks",
    "/backend/studio/calendar",
    "/backend/platform/users",
    "/backend/configuration/themes",
    "/backend/configuration/settings/general",
    "/backend/configuration/settings/pexels",
    "/backend/trash",
    "/backend/general/profile",
    "/workspace/1?view=board",
    "/workspace/1?view=files",
    "/workspace/1?view=deliverables",
    "/workspace/1?view=notes",
    "/workspace/1?view=resources",
    "/workspace/1?view=settings",
];

/** Le moins d'air qu'on accepte entre un encart et le bord de son bloc. */
const MIN_GAP = 4;

async function signIn(page) {
    await page.goto("/backend/platform/login");
    await page.getByPlaceholder("votre@email.com").fill("dev@aurora.app");
    await page.getByPlaceholder("••••••••").fill("password");
    await page.keyboard.press("Enter");
    await page.waitForURL((url) => !url.pathname.endsWith("/login"));
}

for (const screen of SCREENS) {
    test(`the how-to guide breathes inside its block on ${screen}`, async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1600, height: 1000 });
        await signIn(page);
        await page.goto(screen, { waitUntil: "domcontentloaded" });
        await page.locator("[data-guide]").first().waitFor({ timeout: 15_000 });

        // Pour chaque encart, l'écart avec le premier ancêtre qui a une
        // bordure : c'est ce bord-là que l'œil voit toucher.
        const gaps = await page.evaluate(() =>
            [...document.querySelectorAll("[data-guide]")]
                .filter((guide) => !guide.classList.contains("rounded-none"))
                .map((guide) => {
                    const box = guide.getBoundingClientRect();
                    let parent = guide.parentElement;
                    while (parent && parent !== document.body) {
                        const style = getComputedStyle(parent);
                        if (
                            parseFloat(style.borderLeftWidth) > 0 ||
                            parseFloat(style.borderRightWidth) > 0
                        )
                            break;
                        parent = parent.parentElement;
                    }
                    if (!parent || parent === document.body) return null;

                    const frame = parent.getBoundingClientRect();

                    return {
                        title: guide.getAttribute("aria-label"),
                        left: Math.round(box.left - frame.left),
                        right: Math.round(frame.right - box.right),
                    };
                })
                .filter(Boolean),
        );

        for (const gap of gaps) {
            expect(
                gap.left,
                `« ${gap.title} » colle au bord gauche`,
            ).toBeGreaterThanOrEqual(MIN_GAP);
            expect(
                gap.right,
                `« ${gap.title} » colle au bord droit`,
            ).toBeGreaterThanOrEqual(MIN_GAP);
        }
    });
}
