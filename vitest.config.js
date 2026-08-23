import { defineConfig } from "vitest/config";
import vue from "@vitejs/plugin-vue";
import path from "path";
import { aliases } from "./aliases.js";

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            ...aliases,
            // vite.config.js derives this one from AURORA_CLIENT_DIR, so it was
            // missing here - and any test transitively reaching i18n.js died on
            // its `import.meta.glob("@client/src/locales/*.js")` before running
            // a single assertion. Same empty placeholder standalone dev uses.
            "@client": path.resolve(
                __dirname,
                "src/Core/assets/.client-fallback",
            ),
        },
    },
    test: {
        environment: "jsdom",
        globals: true,
        include: ["src/**/*.{test,spec}.{js,ts}"],
        exclude: ["node_modules", "tests/e2e/**", "dist/**"],
        pool: "threads",
        minThreads: 1,
        maxThreads: 4,
        testTimeout: 30000,
        env: {
            TZ: "UTC",
        },
    },
});
