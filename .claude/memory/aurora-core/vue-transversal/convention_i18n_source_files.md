---
name: Convention traductions i18n (YAML sources + structure)
description: Architecture complète des traductions — où écrire, structure par feature, pipeline YAML→JSON, tests de cohérence
type: project
---

## Règle

**Source unique de vérité : les fichiers YAML.** Le JSON frontend est un artefact
de build régénéré par `make translation`. Ne jamais toucher `src/Core/assets/locales/generated/*.json`.

## Structure des fichiers

Les traductions sont **co-localisées avec la feature** qui les consomme. La
plupart des features historiquement sous `src/Core/<Feature>/` ont migré sous
`src/Module/<Domaine>/<Feature>/` — d'où la profondeur 2 ci-dessous.

### Core

```
src/Core/translations/               → shared.*, backend.* + security.* / validators.*
src/Core/Mail/translations/          → frontend.*, shared.*
src/Core/Migration/translations/     → backend.*
src/Core/Module/translations/        → backend.* (permissions, modules)
src/Core/Notification/translations/  → backend.*
```

`src/Core/translations/` porte aussi `security.{fr,en,es,de}.yaml` et
`validators.{fr,en,es,de}.yaml`. Seuls `fr` et `en` sont des locales actives
(`Aurora\Core\Locale\Enum\LocaleEnum`) : les variantes `es`/`de` existent mais
ne sont **pas** générées côté JS.

### Modules

```
src/Module/Platform/Auth/translations/        → backend.*, frontend.*, shared.*
src/Module/Platform/User/translations/        → backend.*
src/Module/Configuration/Setting/translations/ → backend.* (settings, parameters, tabs)
src/Module/Configuration/Theme/translations/  → backend.*, frontend.*
src/Module/Editorial/translations/            → backend.*, frontend.*, editorial.*
src/Module/General/Profile/translations/      → backend.*
src/Module/General/Search/translations/       → backend.*
src/Module/Dev/Audit/translations/            → backend.*
src/Module/Dev/MountPoint/translations/       → backend.*
src/Module/Ged/translations/                  → backend.*, ged.*
```

**Découverte automatique** via glob dans `AuroraBundle` (~l. 298-310) —
**profondeur 1 et 2**, aucune config manuelle :

```
src/Core/*/translations       src/Core/*/*/translations
src/Module/*/translations     src/Module/*/*/translations
```

C'est ce second niveau qui permet `Configuration/Setting/` ou `Platform/Auth/`.

## Workflow

```bash
# Modifier FR + EN dans le bon fichier YAML, puis :
make translation   # régénère src/Core/assets/locales/generated/{fr,en}.json
```

**Aucun test ne rattrape l'oubli de `make translation`, par construction.**
`TranslationConsistencyTest` valide la parité FR/EN **des YAML**, et
`VueTranslationKeyTest` lit délibérément les YAML plutôt que le catalogue
généré — c'est écrit dans son en-tête, pour qu'il tienne que le dump ait été
lancé ou non. Résultat : une clé ajoutée en YAML mais pas dumpée passe
`make ft` en entier, passe le build Vite, et ne se voit **qu'à l'écran**, sous
la forme de `backend.posts.grid.canvas` affiché tel quel.

Le catalogue généré est gitignoré : un `git status` propre ne prouve donc rien
non plus. Le seul contrôle fiable est de regarder la page — ou de vérifier la
clé directement :

```bash
node -e 'console.log(require("./src/Core/assets/locales/generated/fr.json").backend.posts.grid.canvas)'
```

## Where does a key go?

- `backend.parameters.*` → `src/Module/Configuration/Setting/translations/`
- `backend.users.*` → `src/Module/Platform/User/translations/`
- `frontend.login.*` → `src/Module/Platform/Auth/translations/`
- `shared.common.*` → `src/Core/translations/messages.{fr,en}.yaml`
- Nouveau module → `src/Module/<Domaine>/<Module>/translations/messages.{fr,en}.yaml`, découvert auto

**Why:** séparation par feature = co-localisation avec le code qui utilise la
traduction. Un dev qui touche `src/Module/Platform/Auth/` sait exactement où
sont ses traductions.

## Tests de cohérence

`tests/Unit/Translation/TranslationConsistencyTest.php` tourne à chaque
`make ft` et valide **sur toutes les paires FR/EN découvertes** :

1. Parité des clés FR↔EN
2. Pas de valeurs vides
3. Cohérence des `{placeholders}`

> Le test découvre les paires par glob — ne pas figer leur nombre ici, il
> augmente à chaque module et le chiffre se périme aussitôt.

## How to apply

- Nouveau module : créer `src/Module/<Domaine>/<Module>/translations/messages.{fr,en}.yaml`, découvert auto.
- Nouvelle feature Core : créer `src/Core/<Feature>/translations/messages.{fr,en}.yaml`, découvert auto.
- Doc complète : `docs/aurora-shared/translations.md`
