import { registerSettingsTabComponent } from "@configuration/suite/settings/tabRegistry.js";
import DriveTab from "./DriveTab.vue";

// Correspond à `componentName: 'drive'` sur l'onglet que le module Studio
// contribue.
registerSettingsTabComponent("drive", DriveTab);
