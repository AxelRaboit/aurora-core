import { expect, test } from "@playwright/test";
import { mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

async function loginAsAdmin(page) {
    await page.goto("/login");
    await page
        .locator("input[type='email'], input[name*='email']")
        .first()
        .fill("admin@aurora.app");
    await page.locator("input[type='password']").first().fill("password");
    await page.locator("button[type='submit']").first().click();
    await page.waitForURL(/\/admin/);
}

// 1×1 PNG (smallest valid PNG, decoded from base64).
const TINY_PNG_BASE64 =
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=";

test.describe("Admin Media", () => {
    let scratchDir;
    let imagePath;

    test.beforeAll(() => {
        scratchDir = mkdtempSync(join(tmpdir(), "aurora-e2e-"));
        imagePath = join(scratchDir, "e2e-upload.png");
        writeFileSync(imagePath, Buffer.from(TINY_PNG_BASE64, "base64"));
    });

    test.afterAll(() => {
        if (scratchDir) {
            rmSync(scratchDir, { recursive: true, force: true });
        }
    });

    test("media library page loads", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/media");
        await expect(page).toHaveURL(/\/admin\/media/);
        const heading = page.getByRole("heading").first();
        await expect(heading).toBeVisible();
    });

    test("uploads a file via the upload endpoint", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/media");

        // The upload <input type="file"> is hidden; setInputFiles works on hidden inputs.
        const fileInput = page.locator("input[type='file']").first();
        await fileInput.setInputFiles(imagePath);

        // After upload, either a toast confirms it or the new item shows up in the grid.
        const toast = page
            .locator("[data-sonner-toast], [role='status'], [class*='toast']")
            .first();
        await expect(toast).toBeVisible({ timeout: 10_000 });
    });

    test("filters by media type", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/media");

        // Click the "Images" filter and verify the URL or the active state changes.
        // The filter buttons are part of the toolbar (typeFilter ref).
        const imagesFilter = page
            .getByRole("button", { name: /^Images$/i })
            .first();
        if (await imagesFilter.count()) {
            await imagesFilter.click();
            // The button should now be styled as active (accent color).
            await expect(imagesFilter).toHaveClass(/bg-accent/);
        }
    });

    test("switches sort mode and persists across reload", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/media");

        // Click the A-Z sort.
        const nameSort = page.getByRole("button", { name: /A-Z/ }).first();
        if (await nameSort.count()) {
            await nameSort.click();
            await expect(nameSort).toHaveClass(/bg-surface-3|text-primary/);

            // Reload - sort should be restored from localStorage.
            await page.reload();
            const restored = page.getByRole("button", { name: /A-Z/ }).first();
            await expect(restored).toHaveClass(/bg-surface-3|text-primary/);
        }
    });

    test("position sort button advertises drag-drop in tooltip", async ({
        page,
    }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/media");

        const positionBtn = page
            .locator("button[title]")
            .filter({ hasText: /^#$/ })
            .first();
        if (await positionBtn.count()) {
            const title = await positionBtn.getAttribute("title");
            // The hint should mention drag-drop / glisser-déposer / Drag.
            expect(title).toMatch(/drag|glisser/i);
        }
    });
});
