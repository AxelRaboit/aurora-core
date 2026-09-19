import { registerSettingsTabComponent } from "@configuration/backend/settings/tabRegistry.js";
import CraftTab from "./CraftTab.vue";

// Correspond à `componentName: 'craft'` sur l'onglet que le module Studio
// contribue. Enregistré depuis le module plutôt qu'importé par le registre du
// noyau : c'est ainsi qu'un module garde ses propres écrans.
registerSettingsTabComponent("craft", CraftTab);
