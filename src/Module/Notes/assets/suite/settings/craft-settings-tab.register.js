import { registerSettingsTabComponent } from "@configuration/suite/settings/tabRegistry.js";
import CraftTab from "./CraftTab.vue";

// Matches `componentName: 'craft'` on the tab the Notes module contributes.
// Registered from the module rather than imported by the core registry: that
// is how a module keeps its own screens.
registerSettingsTabComponent("craft", CraftTab);
