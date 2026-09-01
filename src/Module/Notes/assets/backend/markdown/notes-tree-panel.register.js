import { registerModulePanel } from "@/shared/nav/modulePanelRegistry.js";

/**
 * Matched by hand against `NotesModule::getModuleNavView()`, so a rename on
 * one side is a panel that quietly stops appearing. `modulePanelRegistry.test.js`
 * fails in both directions when they drift.
 */
registerModulePanel(
    "notes/backend/markdown/NoteTreePanel",
    () => import("./NoteTreePanel.vue"),
);
