import { registerSettingsTabComponent } from "@configuration/suite/settings/tabRegistry.js";
import GitHubTab from "./GitHubTab.vue";

// Matches `componentName: 'github'` on the tab the Editorial module
// contributes.
registerSettingsTabComponent("github", GitHubTab);
