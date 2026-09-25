import { registerSettingsTabComponent } from "@configuration/backend/settings/tabRegistry.js";
import GitHubTab from "./GitHubTab.vue";

// Correspond à `componentName: 'github'` sur l'onglet que le module Editorial
// contribue.
registerSettingsTabComponent("github", GitHubTab);
