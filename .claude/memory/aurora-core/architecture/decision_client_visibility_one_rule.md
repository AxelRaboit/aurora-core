---
name: Studio - une seule règle de visibilité par le client
description: Tout ce qu'un espace client peut montrer naît caché ; montrer ou cacher demande studio.spaces.share en plus de edit ; trois exceptions voulues
type: project
---

## Règle

Dans un espace client, une **étape**, une **ressource**, un **livrable**, un
**canal** et un **fichier** naissent cachés au client. Les montrer ou les
cacher demande `studio.spaces.share` (le droit des liens d'accès) **en plus**
de `studio.spaces.edit`. La règle vit dans
`Studio/CustomerSpace/Security/ClientVisibility` : `PRIVILEGE` pour un
`#[IsGranted]` sur une route qui ne fait que montrer/cacher, `allowsChange($actuel, $voulu)`
pour un formulaire qui enregistre tout (403 seulement si la visibilité change).
Côté écran, `can("studio.spaces.share")` cache le geste et laisse lire l'état.

Exceptions voulues : un espace neuf montre ses étapes Relecture, « Programmé »
et Publié (`seedDefaults` ; « Programmé » pour qu'un contenu validé ne
disparaisse pas du calendrier du client avant sa sortie), le canal « Général » est toujours montré, un fichier envoyé
par le client reste visible (`addedByClient` pose `true`, le Manager refuse de
le cacher). Les notes ne sont jamais montrées.

## Pourquoi

Décision d'Axel le 06/10/2026 après l'audit de Studio, qui avait trouvé six
règles différentes (étape visible par défaut sous `edit`, fichier sans
réglage, etc.). Montrer quelque chose au client, c'est le lui envoyer : même
geste, même droit que donner un lien d'accès.

## Comment l'appliquer

- Un nouvel élément montrable : drapeau `visibleToClient` à `false` (entité,
  DTO, colonne `DEFAULT false`), route de bascule sous
  `ClientVisibility::PRIVILEGE`, formulaire passé par `allowsChange()`, filtre
  sur la page publique **et** sur la route qui sert l'élément (une adresse
  devinée ne doit pas servir ce que la liste tait).
- Migration d'un drapeau neuf : `DEFAULT true` pour les lignes existantes,
  puis `SET DEFAULT false` (cf. `Version20261006200000`).
- Les tests : `SpaceClientVisibilityRuleTest` tient les cinq éléments ; un
  test qui pose une fiche sur la page du client la met dans la Relecture
  (`findForSpace($space)[2]`), pas dans la première étape.
- Glossaire : « Montrer au client » / « Cacher au client », état « Visible
  par le client » (`docs/aurora-core/dev/studio_glossary.md`).
