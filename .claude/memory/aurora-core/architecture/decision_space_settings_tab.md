---
name: Studio - un espace se règle dans son onglet Réglages
description: Nom, client, couleur, fuseau, statut (éditeurs) puis équipe et Drive (référent/admin) dans l'onglet Réglages de l'espace ; la liste n'a plus de fenêtre de modification, seulement de création
type: project
---

## Règle

Tout ce qui décrit un espace client se modifie dans **son onglet Réglages**
(`/workspace/{id}?view=settings`, `SpaceSettingsView`), en sous-onglets :

- « Espace » (nom, description, client, couleur, fuseau, statut) : quiconque a
  `studio.spaces.edit` et voit l'espace ;
- « Équipe » et « Google Drive » : `SpaceVisibility::canConfigure()` (référent,
  administrateur, développeur) seulement.

Un seul chemin d'écriture : `suite_studio_spaces_update` → `CustomerSpaceManager::update()`
(validation du DTO, `refuseTeamChangeUnlessLead`, annonce au calendrier,
`SpaceNoteSpaceSync`). La liste des espaces garde « Modifier » comme **lien**
vers l'onglet ; sa fenêtre ne sert qu'à créer. Les données de l'onglet viennent
de `CustomerSpacesViewBuilder::settingsView()` (`null` sans le droit d'éditer,
pour ne pas lire comptes et clients à chaque ouverture d'un espace).

**Why:** deux endroits réglaient un espace (fenêtre de la liste, onglet Drive),
et l'onglet Réglages n'existait que pour le référent ; Axel voulait qu'un espace
se règle depuis l'espace. Même logique que la page du client
([[decision_customer_page_single_write_path]]).

**How to apply:** un champ neuf d'un espace va dans `CustomerSpaceInput` (+
fabrique, `applyInput`, sérialiseur, `formFrom()`/`emptyForm()` de
`useCustomerSpacesForm.js`, `CustomerSpaceFormFields`) ; il apparaît alors dans
la fenêtre de création et dans l'onglet. Un réglage réservé au référent va dans
une section de `SpaceSettingsView` filtrée par `canConfigure`, et sa route
appelle `canConfigure` côté serveur. L'en-tête de l'espace est rendu en Twig :
après un enregistrement, `useSpaceSettingsForm` recharge la page. Détail des
ruptures : `docs/aurora-client/MIGRATION_STUDIO.md` section 13.
