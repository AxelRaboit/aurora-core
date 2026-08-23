import fs from "fs";
import path from "path";

// Gate 2 - option B (monorepo-split). When aurora-core is consumed as an
// installed Composer package (vendor/axelraboit/aurora-core), the build runs
// from inside vendor/ and app.js's static glob `../../Module/**` no longer sees
// the module packages - each lives in a SIBLING package dir
// (vendor/axelraboit/aurora-tools, …). This plugin discovers those sibling
// packages at build time and exposes their Vue components through a virtual
// module, so the client's single Vite build bundles them like first-party
// modules (shared chunks + dedupe preserved).
//
// In the monorepo (dev), aurora-core is NOT under a vendor/ dir → the plugin is
// a no-op and modules keep coming from src/Module/** via app.js's own glob.
// This guard is deliberate: a naive relative glob (`../../../../aurora-*`) would
// collide in dev because the monorepo's parent dir holds sibling `aurora-*`
// checkouts (aurora-client, …).

const VIRTUAL_ID = "virtual:aurora-vendor-modules";
const RESOLVED_ID = "\0" + VIRTUAL_ID;

// Side-effect boot hooks (*.register.js) of vendored module packages, eager.
const BOOT_ID = "virtual:aurora-vendor-boot";
const RESOLVED_BOOT = "\0" + BOOT_ID;

// `aurora-personal-finance` → `personalfinance` (matches the lowercased
// PascalCase module key used by the monorepo glob, e.g. Module/PersonalFinance
// → `personalfinance`). Strip the `aurora-` prefix and all dashes.
function moduleKeyFromPackage(pkgDir) {
    return pkgDir
        .replace(/^aurora-/, "")
        .replace(/-/g, "")
        .toLowerCase();
}

// Merge packages hold several modules under top-level subdirs (aurora-commerce
// → Ecommerce/, Erp/). Their components must be keyed by the SUBDIR (ecommerce,
// erp), not the package name (commerce), to match the monorepo glob keys.
const MERGE_PACKAGES = new Set(["aurora-commerce"]);

function moduleKeyForFile(pkg, pkgRoot, file) {
    if (MERGE_PACKAGES.has(pkg)) {
        const rel = file.slice(pkgRoot.length).split(path.sep).filter(Boolean);
        return (rel[0] || "").toLowerCase();
    }

    return moduleKeyFromPackage(pkg);
}

// Collect every assets/**/*<suffix> file under a package dir (any depth of
// feature folders before `assets/`, mirroring the monorepo `**/assets/**`).
function collectFiles(dir, suffix, acc = []) {
    let entries;
    try {
        entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
        return acc;
    }
    for (const entry of entries) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (entry.name === "node_modules" || entry.name === ".git")
                continue;
            collectFiles(full, suffix, acc);
        } else if (
            entry.name.endsWith(suffix) &&
            full.includes(`${path.sep}assets${path.sep}`)
        ) {
            acc.push(full);
        }
    }
    return acc;
}

// Map an absolute file path to the exposed component key the rest of the app
// uses (e.g. `./tools/backend/vault/VaultApp.vue`). `moduleKey` is the package
// module name; everything after the LAST `assets/` segment is the rest.
function exposedKey(moduleKey, file) {
    const normalized = file.split(path.sep).join("/");
    const rest = normalized.replace(/^.*\/assets\//, "");
    return `./${moduleKey}/${rest}`;
}

// Sibling aurora-* package dirs next to aurora-core (vendor/axelraboit/aurora-*),
// excluding aurora-core itself. Returns null in the monorepo (not under vendor/).
function vendoredSiblings(packageDir) {
    if (!packageDir.split(path.sep).includes("vendor")) return null;

    const orgDir = path.resolve(packageDir, "..");
    try {
        return fs
            .readdirSync(orgDir, { withFileTypes: true })
            .filter(
                (e) =>
                    e.isDirectory() &&
                    /^aurora-/.test(e.name) &&
                    e.name !== "aurora-core",
            )
            .map((e) => path.join(orgDir, e.name));
    } catch {
        return [];
    }
}

export function auroraVendorModules({ packageDir }) {
    return {
        name: "aurora-vendor-modules",
        resolveId(id) {
            if (id === VIRTUAL_ID) return RESOLVED_ID;
            if (id === BOOT_ID) return RESOLVED_BOOT;
            return null;
        },
        load(id) {
            if (id !== RESOLVED_ID && id !== RESOLVED_BOOT) return null;

            const siblings = vendoredSiblings(packageDir);

            // Monorepo dev → no-op (modules come from src/Module via app.js globs).
            if (siblings === null) {
                return id === RESOLVED_BOOT ? "" : "export default {};";
            }

            // Boot module: eager side-effect imports of every *.register.js.
            if (id === RESOLVED_BOOT) {
                const imports = [];
                for (const pkgRoot of siblings) {
                    for (const file of collectFiles(pkgRoot, ".register.js")) {
                        imports.push(
                            `import ${JSON.stringify(file.split(path.sep).join("/"))};`,
                        );
                    }
                }
                return imports.join("\n");
            }

            // Vue components: lazy map keyed ./<module>/<rest>.
            const lines = [];
            for (const pkgRoot of siblings) {
                const pkg = path.basename(pkgRoot);
                for (const file of collectFiles(pkgRoot, ".vue")) {
                    const moduleKey = moduleKeyForFile(pkg, pkgRoot, file);
                    const key = exposedKey(moduleKey, file);
                    const importPath = file.split(path.sep).join("/");
                    lines.push(
                        `  ${JSON.stringify(key)}: () => import(${JSON.stringify(importPath)})`,
                    );
                }
            }

            return `export default {\n${lines.join(",\n")}\n};`;
        },
    };
}

export { VIRTUAL_ID as AURORA_VENDOR_MODULES_ID };
