import { registerSettingsTabComponent } from "@configuration/suite/settings/tabRegistry.js";
import CaptchaTab from "./CaptchaTab.vue";

// Matches `componentName: 'captcha'` on the ConfigurationTab the Editorial
// module contributes. Registered from the module rather than imported by the
// core registry, which is how a module keeps its own screens.
registerSettingsTabComponent("captcha", CaptchaTab);
