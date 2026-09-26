import { registerSettingsTabComponent } from "@configuration/backend/settings/tabRegistry.js";
import NewsletterTab from "./NewsletterTab.vue";

registerSettingsTabComponent("newsletter", NewsletterTab);
