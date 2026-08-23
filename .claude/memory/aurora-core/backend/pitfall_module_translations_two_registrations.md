---
name: Nouveau module Aurora - seul resolve_target_entities est manuel dans AuroraBundle.php
description: Créer un dossier src/Module/Xxx/ suffit pour Doctrine mappings, Twig namespaces et traductions - seul resolve_target_entities nécessite une entrée manuelle par entité
type: feedback
---

## Règle

Quand tu crées un **nouveau module** `src/Module/<Module>/`, tout est auto-découvert **sauf** `resolve_target_entities`.

### Ce qui est automatique (rien à toucher)

| Quoi | Mécanisme | Condition |
|------|-----------|-----------|
| Doctrine `mappings` | `glob('src/Module/*')` dans `AuroraBundle::prependExtension()` | dossier existe |
| Twig namespace `@<Module>` | même glob | `src/Module/<Module>/templates/` doit exister |
| Symfony Translator paths | même glob | `src/Module/<Module>/translations/` doit exister |
| DumpJsTranslationsCommand (vue-i18n) | `glob('src/Module/*/translations')` | `translations/` doit exister |
| `services.yaml` + `app.js` | `_instanceof ModuleInterface` + glob Vite | classe `<Module>Module` implémente `ModuleInterface` |

### Ce qui est encore manuel - `resolve_target_entities`

Pour **chaque entité** du module, ajouter dans `src/AuroraBundle.php` :

```php
'resolve_target_entities' => [
    // ...
    XxxInterface::class => Xxx::class,
],
```

**Pourquoi c'est impossible à automatiser :** mapper `XxxInterface → Xxx` nécessite de connaître la hiérarchie des classes (quelle concrete implémente quelle interface). Déduire ça depuis le système de fichiers sans risquer une mauvaise résolution silencieuse n'est pas fiable.

## Vérification après création d'un nouveau module

```bash
# 1. Vérifier que les entités sont bien dans resolve_target_entities
grep "XxxInterface" src/AuroraBundle.php

# 2. Vérifier que le mapping Doctrine est bien découvert
php bin/console doctrine:mapping:info | grep Aurora<Module>

# 3. Vérifier les traductions (si module a un dossier translations/)
php bin/console app:translations:dump-js
grep -c "<module>" src/Core/assets/locales/generated/fr.json

# 4. Vérifier le namespace Twig (si module a des templates)
php bin/console debug:twig --filter=<Module>
```

## Historique

- Avant 2026-05-09 : 2 enregistrements manuels (AuroraBundle.php + DumpJsTranslationsCommand)
- 2026-05-09 : DumpJsTranslationsCommand automatisé par glob
- 2026-05-09 : AuroraBundle.php automatisé pour mappings/Twig/translations - seul resolve_target_entities reste manuel
