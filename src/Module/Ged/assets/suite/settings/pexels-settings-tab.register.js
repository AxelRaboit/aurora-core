import { registerSettingsTabComponent } from "@configuration/suite/settings/tabRegistry.js";
import PexelsTab from "./PexelsTab.vue";

// Matches `componentName: 'pexels'` on the ConfigurationTab the GED module
// contributes. Registered from the module rather than imported by the core
// registry, which is how a module keeps its own screens.
registerSettingsTabComponent("pexels", PexelsTab);
