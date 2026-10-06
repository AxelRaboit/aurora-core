---
name: Studio - les présentations vivent aussi dans un espace client
description: Une présentation (livrable slides) se crée, se copie, se compose et se lit dans un espace client sous les droits de l'espace ; gestes partagés par AbstractDeliverableSlidesController, deux jeux de routes
type: project
---

## Règle

Une présentation (livrable `format = slides`) vit dans Studio **ou** dans un
espace client, comme une page (06/10/2026). Dans un espace :

- **Création** depuis l'onglet Livrables : format, « Partir d'un modèle »
  (modèles de Studio lisibles, du même format, via
  `DeliverableManager::copyToSpace()`), « Importer un texte »
  (`workspace_space_deliverables_import`). La fenêtre partage
  `DeliverableFormatFields` et `DeliverableImportModal` avec Studio.
- **Éditeur** : `workspace_space_deliverables_edit` aiguille sur
  `space-deliverables/slides.html.twig` ; les gestes sont sous
  `workspace_space_deliverables_slides_*` (`SpaceDeliverableSlidesController`),
  droits de l'espace par `DeliverableAccess` (view lit/présente/imprime, edit
  écrit). Les routes `suite_studio_deliverables_slides_*` restent pour Studio
  seul : chaque jeu répond 404 pour un livrable de l'autre.
- **Client** : `public_space_deliverable` rend le lecteur de diapositives
  (`readerDeck`, jamais les notes) si la présentation est montrée
  (`ClientVisibility`), 404 sinon ; retour vers l'espace par `backUrl`.
- Copie Studio ↔ espace : diapositives, notes, thème et style suivent
  (`persistCopy` → `SlidesManager::copySlides`).

**Why:** la v1 de la fusion Deck → livrables avait gardé les présentations
dans Studio (refus `slides_not_in_space`) faute d'éditeur et de lecteur côté
espace ; un point mensuel ou un comité de pilotage se montre pourtant au
client comme un audit.

**How to apply:** un geste neuf de l'éditeur de diapositives va dans
`AbstractDeliverableSlidesController` (méthode protégée sans route), puis une
route fine dans **chacun** des deux contrôleurs concrets (un attribut de route
sur une méthode héritée serait chargé deux fois). Une donnée neuve de
l'éditeur passe par `DeliverableSlidesViewBuilder::slidePaths()` pour servir
les deux contextes. `public_deliverable_font` suit « Livrables OU espaces ».
Détail des ruptures : `docs/aurora-client/MIGRATION_STUDIO.md` section 12.

## Liens

- [[decision_deck_free_canvas]] - le moteur de diapositives lui-même.
- [[decision_client_visibility_one_rule]] - la règle qui décide si le client la lit.
