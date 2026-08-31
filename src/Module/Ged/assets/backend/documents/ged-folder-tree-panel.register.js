import { registerModulePanel } from "@/shared/nav/modulePanelRegistry.js";

/**
 * Registered under the exact string `GedModule::FOLDER_TREE_PANEL` names. The
 * two are matched by hand, so a rename on one side is a panel that stops
 * appearing - silently, because the menu draws its links either way.
 */
registerModulePanel(
    "ged/backend/documents/FolderTreePanel",
    () => import("./FolderTreePanel.vue"),
);
