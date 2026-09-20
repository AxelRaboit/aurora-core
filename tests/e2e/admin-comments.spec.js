import { expect, test } from "@playwright/test";

async function loginAsAdmin(page) {
    await page.goto("/login");
    await page
        .locator("input[type='email'], input[name*='email']")
        .first()
        .fill("dev@aurora.app");
    await page.locator("input[type='password']").first().fill("password");
    await page.locator("button[type='submit']").first().click();
    await page.waitForURL(/\/admin/);
}

test.describe("Admin Comments", () => {
    test("navigates to comments page", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/comments");

        const heading = page.getByRole("heading").first();
        await expect(heading).toBeVisible();
    });

    test("moderation toggle works", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/comments");

        const toggleButton = page
            .locator("button")
            .filter({ hasText: /modération|moderation/i })
            .first();
        await expect(toggleButton).toBeVisible();
        await toggleButton.click();

        // Expect a toast or feedback element to appear
        const toast = page
            .locator(
                "[data-sonner-toast], [role='status'], .toast, [class*='toast']",
            )
            .first();
        await expect(toast).toBeVisible({ timeout: 5000 });
    });
});
