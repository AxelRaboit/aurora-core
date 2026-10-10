---
name: Studio - suivi des prospects (tableau, relances, historique)
description: Le tableau des prospects est une seconde mise en page des clients ; deux rôles d'étape (gagné, perdu) portent des règles ; la relance sonne une fois par date ; un formulaire crée un prospect par ProspectDirectoryInterface
type: project
---

## Règle

Livré en 4.10.0 (10/10/2026), sur demande d'Axel. Pas de module à part : un
prospect est déjà un `Customer` au statut `prospect`, le suivi s'ajoute dessus.

- **Étapes** : `Studio/Pipeline` (`PipelineStage`, globales, réglables depuis le
  tableau). Semées depuis une liste traduite **à la première lecture**
  (`PipelineStageManager::stages()`), pas à l'installation : un projet qui
  monte de version ne lance jamais `aurora:install`.
- **Rôles** (`PipelineStageRoleEnum`) : `won` et `lost`, une étape chacun au
  plus. Un prospect n'entre en `won` **qu'en se convertissant**
  (`PipelineManager::move()` refuse ; le tableau ouvre la conversion) et
  `convertToClient()` comme le passage en client depuis la fiche appellent
  `fileAsWon()`. Un client ne sort pas de `won`. Entrer en `lost` demande une
  raison (facultative) et retire la relance. Garder au moins une étape en
  cours : un `pipelineStage` nul s'affiche dans la première.
- **Le tableau tient des ids, la liste des clients** : `PipelineViewBuilder::board()`
  rend `{stages, columns:[{stageId, customerIds}], lastInteractions}` ; la page
  dessine les cartes avec les lignes de `customers`. Gagné/perdu ne montrent
  que les derniers jours réglés dans Réglages > Studio (`StudioPipelineOutcomeDays`,
  30 par défaut, borné 1-365 par `PipelineOutcomeWindow`, réglage depuis la 4.11.0). Chaque geste répond `{customers, pipeline}`.
- **Relance** : `nextFollowUpOn` (jour, heure du site via `FollowUpCalendar`),
  `followUpNotifiedOn` remis à nul dès que la date change. Notification
  quotidienne 8 h 30 (`FollowUpReminders`, type `studio.follow_up`) à tous ceux
  qui ont `studio.customers.view` (`isGrantedForUser`). Même compte pour le
  tableau de bord (`followUpsDue`), l'onglet `?followUps=due` et la pastille du
  menu (`NavItemAttentionProviderInterface`, nouveau dans le cœur).
- **Historique** : `Studio/CustomerInteraction`, jamais montré au client.
  Noter un échange **neuf** avec `setsFollowUp` remplace la relance (vide = la
  retire) ; modifier un ancien échange n'y touche pas.
- **Formulaire → prospect** : `Core/Contact/Prospect/ProspectDirectoryInterface`
  (Editorial n'importe pas Studio). `sourceReference` = référence de la réponse,
  ce qui empêche deux fiches pour un message ; la réponse devient le premier
  échange (le champ Notes de la fiche est lu par le client, l'historique non).

## Pourquoi

Axel suit ses prospects (CM, photo, dev) à côté d'un CDI ; la facturation passe
par Indy, donc le montant est une estimation, jamais une facture.

## Comment l'appliquer

- Un champ de suivi neuf va dans `CustomerInput` comme le reste de la fiche,
  mais s'affiche dans `CustomerPipelineCard.vue`, pas `CustomerFormFields.vue`.
  L'étape, elle, ne passe jamais par la sauvegarde de la fiche (`useCustomerStage`).
- Le glisser-déposer du tableau (SortableJS) ne réagit pas aux gestes
  synthétiques du navigateur intégré : vérifier les déplacements par la route
  `pipeline/move` et `useProspectPipeline.test.js`.
- Voir [[decision_customer_page_single_write_path]].
