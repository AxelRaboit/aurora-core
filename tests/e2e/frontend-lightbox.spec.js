import { expect, test } from "@playwright/test";

/**
 * The overlay that opens a picture, and the one thing it must not do: put the
 * picture where the reader cannot see it.
 *
 * A tall photograph used to render at its own height inside a flex row whose
 * item had no lower bound, so the figure grew past the screen and the image's
 * own `max-h-full` resolved against a box that had already overflowed. At
 * 1280 by 720 a story-shaped picture started 582 pixels above the top and ran
 * 618 below the bottom.
 *
 * Measured rather than asserted on classes: what broke was layout, and only a
 * browser computes layout. That is also why this lives in the Playwright
 * suite rather than in the component tests, where jsdom would report the same
 * numbers whatever the CSS said.
 */
const SHAPES = {
    // A story: the tallest thing the portfolio shows.
    story: { width: 1519, height: 2700 },
    // A panorama, for the other axis.
    panorama: { width: 3000, height: 900 },
};

function picture({ width, height }) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="${width}" height="${height}" fill="#123"/></svg>`;

    return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

async function openLightbox(page) {
    await page.goto("/fr");
    await page.waitForTimeout(2500);

    const opener = page
        .locator("[data-gallery-open], [data-grid-image-open]")
        .first();
    await expect(opener).toBeVisible();
    await opener.click();
    await page.waitForTimeout(1000);

    return page.locator("figure img").last();
}

for (const [name, shape] of Object.entries(SHAPES)) {
    for (const viewport of [
        { width: 1280, height: 720 },
        { width: 390, height: 844 },
    ]) {
        test(`a ${name} picture stays on screen at ${viewport.width}x${viewport.height}`, async ({
            page,
        }) => {
            test.setTimeout(120000);
            await page.setViewportSize(viewport);

            const lightboxImage = await openLightbox(page);

            // The source is swapped rather than fixtured: what is being tested
            // is how the overlay handles a shape, not which file it holds.
            await lightboxImage.evaluate((element, pictureSource) => {
                element.src = pictureSource;
                element.removeAttribute("srcset");
            }, picture(shape));
            await page.waitForTimeout(800);

            const box = await lightboxImage.boundingBox();

            expect(box.y).toBeGreaterThanOrEqual(-1);
            expect(box.x).toBeGreaterThanOrEqual(-1);
            expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);
            expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
        });
    }
}
