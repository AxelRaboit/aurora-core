---
name: convention-overrides-vs-modules
description: Where to place client-side Vue files - co-location with the PHP extension under `src/Module/<Module>/<Feature>/assets/`. The path "Agency" (PascalCase PHP feature folder) + "agencies/" (kebab plural URL segment) are two mirrors of two different layers, not a duplication.
metadata:
  type: project
---

# Convention : co-localisation Vue + PHP sous `src/Module/<X>/<Feature>/`

Aurora-client co-localise les **overrides Vue** d'une entité Aurora **dans
le même dossier** que son extension PHP. Une seule convention, un seul
endroit pour tout ce qui touche à un feature.

## La règle

Pour étendre une entité d'un module Aurora (e.g. Agency dans Platform) :

```
src/Module/Platform/Agency/             ← tout ce qui concerne Agency vit ici
├── Entity/Agency.php                   ← extends AbstractAgency
├── Dto/AgencyInput.php                 ← extends AgencyInput aurora
├── Dto/AgencyInputFactory.php          ← #[AsAlias(AgencyInputFactoryInterface)]
├── Manager/AgencyManager.php           ← override create<X>() + applyInput()
├── Serializer/AgencySerializer.php     ← override serialize() avec spread parent
└── assets/backend/agencies/AgenciesApp.vue   ← wrapper Vue (extraFields + slots)
```

Le glob `@client/src/Module/**/assets/**/*.vue` détecte ce fichier et
l'expose comme **`platform/backend/agencies/AgenciesApp`** - **même clé**
que le composant Aurora. Comme `clientModules` est spread après
`auroraModules` dans `vueContext`, le client win automatiquement → shadow
direct sans Twig override.

## La règle des deux mirrors

Le path `Platform/Agency/assets/backend/agencies/AgenciesApp.vue` traverse
**deux layers** différents, séparés par `/assets/` :

```
src/Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue
            └───────┬──────┘ ▲ └──────────┬──────────┘
                    │        │            │
              PHP mirror     │       URL mirror
        (Aurora\Module\…\Agency)        (/backend/platform/agencies/…)
                            │
                       séparateur
```

- **Avant `/assets/`** → mirror du namespace PHP (Doctrine/Sylius layer).
  `Platform/Agency/` = `Aurora\Module\Platform\Agency\`. PascalCase singulier,
  convention PSR-4.
- **`/assets/`** = séparateur conventionnel "ce qui suit est côté front".
- **Après `/assets/`** → mirror de l'URL/route (HTTP layer).
  `backend/agencies/AgenciesApp.vue` = route `/backend/platform/agencies/` + composant
  SPA. kebab pluriel, convention REST/Symfony.

`Agency/` et `agencies/` ne sont **pas un doublon** - ce sont deux
abstractions distinctes du même domaine, exprimées chacune dans sa
convention naturelle. Aurora-core utilise les deux partout (`AgencyManager`
+ `AgenciesController` + route `/backend/platform/agencies/`).

## Pourquoi ce glob fonctionne

Le glob côté `app.js` :

```js
const auroraModules = import.meta.glob("../../Module/**/assets/**/*.vue");
const clientModules = import.meta.glob("@client/src/Module/**/assets/**/*.vue");

// Regex de mapping : capture le NOM du module (premier segment après Module/),
// skip les feature folders intermédiaires, capture ce qui suit /assets/.
const MODULE_PATH_RE = /Module\/([^/]+)\/(?:[^/]+\/)*assets\/(.*)$/;
```

Conséquence :
- Aurora-core : `Module/Platform/assets/backend/agencies/AgenciesApp.vue`
  → moduleName=Platform, rest=backend/agencies/AgenciesApp.vue
  → clé `./platform/backend/agencies/AgenciesApp`
- Aurora-client : `Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue`
  → moduleName=Platform, rest=backend/agencies/AgenciesApp.vue (feature folder `Agency` ignoré)
  → clé `./platform/backend/agencies/AgenciesApp`
- **Même clé** → client wins (spread later) → override sans Twig.

## Quand utiliser quoi

| Cas | Path | Glob |
|---|---|---|
| Module client autonome (Tracking, Loyalty, ...) | `src/Module/<Module>/assets/backend/<Module>App.vue` | `clientModules` |
| Override d'une entité Aurora (Agency, Post, Deal, ...) | `src/Module/<AuroraModule>/<Feature>/assets/backend/<plural>/<Name>App.vue` | `clientModules` (co-localisé avec l'extension PHP) |
| Extension PHP seule (pas de Vue à override) | `src/Module/<AuroraModule>/<Feature>/` sans `assets/` | _(n/a)_ |
| Override d'un composant non-module (e.g. dans `src/Core/assets/`) | `src/Overrides/<full-path>/<Name>.vue` | `clientOverrides` (escape hatch) |

## `src/Overrides/` reste là, mais en escape hatch

Le bucket `src/Overrides/` continue de fonctionner - son glob `Overrides/**/*.vue`
expose le path tel quel. Utile pour **shadow des composants non-module**
d'aurora-core (ceux sous `src/Core/assets/backend/...`, exposés sous la clé
`./core/backend/...`). Pour ces cas-là, mettre le fichier à
`src/Overrides/core/backend/<X>.vue` shadow directement la clé `./core/backend/<X>`.

**Mais pour les modules Aurora (Platform, Editorial, Crm, ...), préférer
la co-localisation** - c'est plus propre, plus traçable, et co-loyalty avec
l'extension PHP.

## Anti-patterns

- ❌ Mettre l'override Vue sous `src/Module/<X>/assets/` quand il existe une
  extension PHP au niveau `<Feature>/` - le path ne reflète pas la portée
  réelle de l'override
- ❌ Override un composant via `src/Overrides/` quand il vit dans un module
  Aurora (la co-localisation sous `Module/<AuroraModule>/<Feature>/assets/`
  est plus propre et utilise le même mécanisme `clientModules`)
- ❌ Confondre le segment **avant** `/assets/` (mirror PHP) avec celui
  **après** (mirror URL) - ils répondent à deux conventions différentes

## Source

- `src/Core/assets/app.js` - les 3 globs `auroraModules`, `clientModules`,
  `clientOverrides` (cf. commentaires + constante `MODULE_PATH_RE`)
- Aurora-core commit qui a introduit le `**/` dans les globs (cherche
  "co-localisation PHP+Vue" dans `git log`)
- Lien : [[pattern_extend_vue]] - pattern wrapper Vue avec `extraFields` + slots
