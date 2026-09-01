import { registerModulePanel } from "@/shared/nav/modulePanelRegistry.js";

/**
 * Matched by hand against `PlanningModule::getModuleNavView()`;
 * `modulePanelRegistry.test.js` fails in both directions when they drift.
 */
registerModulePanel(
    "planning/backend/planning/CalendarListPanel",
    () => import("./CalendarListPanel.vue"),
);
