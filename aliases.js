import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// Module name (PascalCase) → its vendored Composer package dir.
// PersonalFinance → aurora-personal-finance, Tools → aurora-tools.
function vendoredPackageDir(name) {
    const kebab = name.replace(/([a-z0-9])([A-Z])/g, "$1-$2").toLowerCase();
    return `aurora-${kebab}`;
}

// In the monorepo a module's assets live at src/Module/<Name>/assets. When the
// module ships as its own Composer package, that dir is absent from aurora-core
// and the assets live in the sibling package vendor/axelraboit/aurora-<kebab>/
// assets. Resolve to whichever exists so @<module> aliases work in both layouts.
export function moduleAlias(name) {
    const local = path.resolve(__dirname, `src/Module/${name}/assets`);
    if (fs.existsSync(local)) return local;

    return path.resolve(__dirname, `../${vendoredPackageDir(name)}/assets`);
}

export const aliases = {
    "@": path.resolve(__dirname, "src/Core/assets"),
    "@core": path.resolve(__dirname, "src/Core/assets"),
    "@shared": path.resolve(__dirname, "src/Core/assets/shared"),
    "@platform": moduleAlias("Platform"),
    "@configuration": moduleAlias("Configuration"),
    "@general": moduleAlias("General"),
    "@dev": moduleAlias("Dev"),
    "@editorial": moduleAlias("Editorial"),
    "@ged": moduleAlias("Ged"),
};
