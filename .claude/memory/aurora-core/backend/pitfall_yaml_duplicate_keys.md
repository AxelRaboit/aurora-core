---
name: Piège YAML - clés dupliquées dans les fichiers de traduction
description: Une clé YAML dupliquée au même niveau lève une exception lors du dump des traductions JS - silencieuse en PHP mais fatale au build Vue
type: feedback
---

## Règle

Ne jamais définir la même clé deux fois au même niveau dans un fichier `messages.*.yaml`.

**❌ Cause l'erreur** `Duplicate key "strength" detected at line 38` lors de `app:translations:dump-js` (et donc de `npm run build`) :

```yaml
vault:
  setup:
    submit: Créer le coffre-fort
    strength: Force du mot de passe   # ← PREMIER
    warning: Attention…
    strength:                          # ← DOUBLON - erreur
      weak: Très faible
      fair: Faible
```

**✅ Correct** - soit scalar, soit mapping, pas les deux :

```yaml
vault:
  setup:
    submit: Créer le coffre-fort
    warning: Attention…
    strength:
      weak: Très faible
      fair: Faible
      good: Moyen
      strong: Fort
      very_strong: Très fort
```

**Why:** PHP (Symfony Yaml) retourne l'une des deux valeurs silencieusement (la dernière gagne). Mais `DumpJsTranslationsCommand` parse lui aussi le YAML et lève une exception, cassant le build Vue. Découvert lors de la création du module Vault (2026-05-09).

**How to apply:** Avant d'ajouter des sous-clés, vérifier qu'aucune clé scalaire du même nom n'existe déjà au même niveau. Lors d'un ajout de structure nested, supprimer simultanément l'éventuel scalaire.
