import { registerSettingsTabComponent } from "@configuration/suite/settings/tabRegistry.js";
import DriveTab from "./DriveTab.vue";

// Matches `componentName: 'drive'` on the tab the Studio module
// contributes.
registerSettingsTabComponent("drive", DriveTab);
