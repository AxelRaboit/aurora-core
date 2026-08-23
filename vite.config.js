import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import symfonyPlugin from 'vite-plugin-symfony';
import fs from 'fs';
import path from 'path';
import { aliases } from './aliases.js';
import { auroraVendorModules } from './vite-plugin-aurora-modules.js';

// Every npm dependency of aurora-core. Deduping them all forces resolution to
// aurora-core's own node_modules, so a VENDORED module package
// (vendor/axelraboit/aurora-<x>) that imports e.g. `vue-draggable-plus` resolves
// it - otherwise Rolldown walks up from the package dir and never reaches
// aurora-core's node_modules. No-op in the monorepo (single node_modules).
const auroraPkg = JSON.parse(fs.readFileSync(path.resolve(__dirname, 'package.json'), 'utf-8'));
const sharedDeps = Object.keys({ ...auroraPkg.dependencies, ...auroraPkg.devDependencies });

// AURORA_CLIENT_DIR points to a client project's ROOT (e.g. aurora-client/),
// not to a subdirectory - app.js globs `@client/src/Module/**` and
// `@client/src/Overrides/**` off it. When set, aurora's Vite scans that dir for
// additional Vue components, controllers and CSS, exposed under the @client
// alias. Standalone aurora dev leaves the var unset; the alias then resolves
// to an empty placeholder dir so import.meta.glob('@client/...') returns {}.
const CLIENT_DIR = process.env.AURORA_CLIENT_DIR
    ? path.resolve(process.env.AURORA_CLIENT_DIR)
    : path.resolve(__dirname, 'src/Core/assets/.client-fallback');

// Tailwind v4 discovers its sources from the CSS file's own package, so nothing
// a client project writes - its Twig themes, its Vue components - ever reaches
// the scanner, and a class used only there is silently missing from the build.
// The CSS cannot name the client directory itself (it is only known at build
// time through AURORA_CLIENT_DIR), so the @source lines are injected here.
function clientTailwindSources(clientDir, enabled) {
    const CSS_ENTRY = 'src/Core/assets/css/app.css';

    return {
        name: 'aurora-client-tailwind-sources',
        enforce: 'pre',
        transform(code, id) {
            // Match on the path alone. The dev server appends a query to the
            // id (`app.css?direct`, `?used`), which a bare endsWith rejects -
            // so this ran in build and never in dev, and `make dev` served a
            // stylesheet missing every class used only by the client. The two
            // modes disagreeing is worse than neither working: the built site
            // was correct, so the gap only ever showed up locally.
            const file = id.replace(/\\/g, '/').split('?')[0];

            if (!enabled || !file.endsWith(CSS_ENTRY)) return null;

            const sources = [
                `@source "${clientDir}/templates";`,
                `@source "${clientDir}/src";`,
            ].join('\n');

            return { code: code.replace('@import "tailwindcss";', `@import "tailwindcss";\n${sources}`), map: null };
        },
    };
}

export default defineConfig({
    plugins: [
        clientTailwindSources(CLIENT_DIR, Boolean(process.env.AURORA_CLIENT_DIR)),
        tailwindcss(),
        vue(),
        symfonyPlugin({
            stimulus: {
                controllersFilePath: './src/Core/assets/stimulus.json',
                controllersDir: './src/Core/assets/stimulus',
            },
        }),
        // Gate 2 (option B): discover Vue components of sibling module packages
        // (vendor/axelraboit/aurora-*) when aurora-core is a vendored package.
        // No-op in the monorepo. See vite-plugin-aurora-modules.js.
        auroraVendorModules({ packageDir: __dirname }),
    ],
    resolve: {
        alias: {
            ...aliases,
            '@client': CLIENT_DIR,
        },
        // Dedupe every aurora-core dependency: forces a single instance resolved
        // from aurora-core's node_modules. Fixes both duplicate Vue/vue-i18n at
        // runtime (client @client imports) AND bare imports from vendored module
        // packages that can't walk up to aurora-core's node_modules.
        dedupe: sharedDeps,
    },
    server: {
        fs: {
            // `..` lets the dev server read sibling module packages
            // (vendor/axelraboit/aurora-*) discovered by auroraVendorModules.
            allow: [path.resolve(__dirname), path.resolve(__dirname, '..'), CLIENT_DIR],
        },
    },
    build: {
        rolldownOptions: {
            input: {
                app: './src/Core/assets/app.js',
                flash: './src/Core/assets/flash.js',
                theme: './src/Core/assets/theme.js',
                guest: './src/Core/assets/guest.js',
            },
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;
                    if (id.includes('@editorjs') || id.includes('editorjs-drag-drop') || id.includes('editorjs-undo')) return 'vendor-editorjs';
                    if (id.includes('lucide-vue-next')) return 'vendor-icons';
                    if (id.includes('axios')) return 'vendor-utils';
                    if (id.includes('vue-i18n') || id.includes('vue-sonner') || id.includes('/vue/') || id.includes('/vue@')) return 'vendor-vue';
                },
            },
        },
    },
});
