import { expect, test } from "@playwright/test";

/**
 * A "How it works" panel never touches the edge of the block that contains
 * it.
 *
 * The panel carries no margin: the container spaces it, through its `gap`
 * or its padding (see `AppGuide.vue`). The day one of them has none, the
 * panel sticks to the card, and nothing else says so: it happened to the
 * notes library, placed between a header and a content that each had their
 * padding, and it had none (1 pixel from the edge, against 13 once fixed).
 *
 * Measured in the browser rather than read from classes, for the same reason
 * as the viewer: what breaks is the layout.
 *
 * Against the demo (`make demo`), with the fixtures account. A deliberately
 * embedded panel (`:rounded="false"`) is left aside: it is made to touch an
 * edge.
 */
const SCREENS = [
    "/suite",
    "/suite/editorial/posts",
    "/suite/editorial/posts/1/edit",
    "/suite/editorial/forms",
    "/suite/editorial/comments",
    "/suite/editorial/menus",
    "/suite/editorial/post-types",
    "/suite/editorial/taxonomies",
    "/suite/editorial/post-galleries",
    "/suite/ged/documents",
    "/suite/ged/categories",
    "/suite/ged/tags",
    "/suite/notes/markdown",
    "/suite/planning/calendar",
    "/suite/studio/contracts",
    "/suite/studio/contract-templates",
    "/suite/studio/customers",
    "/suite/studio/deliverables",
    "/suite/studio/spaces/calendar",
    "/suite/platform/users",
    "/suite/configuration/themes",
    "/suite/configuration/settings/general",
    "/suite/configuration/settings/pexels",
    "/suite/trash",
    "/suite/general/profile",
    "/workspace/1?view=board",
    "/workspace/1?view=files",
    "/workspace/1?view=deliverables",
    "/workspace/1?view=notes",
    "/workspace/1?view=resources",
    "/workspace/1?view=settings",
];

/** The least room accepted between a panel and the edge of its block. */
const MIN_GAP = 4;

async function signIn(page) {
    await page.goto("/suite/platform/login");
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

        // For each panel, the gap with the first ancestor that has a border: that
        // is the edge the eye sees it touch.
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
