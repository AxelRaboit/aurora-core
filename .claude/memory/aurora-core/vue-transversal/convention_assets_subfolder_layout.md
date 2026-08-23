---
name: convention_assets_subfolder_layout
description: Convention de compartimentage des assets Vue - quand et comment créer des sous-dossiers feature dans src/Module/<M>/assets/backend/
metadata:
  type: feedback
---

## Règle

Chaque `src/Module/<M>/assets/backend/` (et `src/Core/assets/backend/<section>/`) doit être compartimenté en **sous-dossiers par feature** dès qu'il contient plusieurs features distinctes (≥ 2 App.vue ou ≥ 8 fichiers au total).

### Structure cible par sous-dossier

```
<module>/backend/<feature>/
  <Feature>App.vue          ← point d'entrée Vue (entité/page principale)
  <SupportComponent>.vue    ← composants internes à la feature
  components/               ← si plusieurs composants internes
  composables/
    useXxx.js               ← composables propres à cette feature
```

### Règle de naming

- Nom en `kebab-case`, singulier ou pluriel cohérent avec la resource : `documents/`, `invoices/`, `companies/`, `mount-points/`
- Le sous-dossier s'appelle comme la resource, pas comme l'App : `invoices/` (pas `invoice-show/`)
- Quand une feature a une page liste ET une page détail, tout va dans le même sous-dossier (ex: `invoices/InvoicesApp.vue` + `invoices/InvoiceShowApp.vue`)

### Relation Twig ↔ Vue

La référence `vue_component('module/backend/<feature>/<Name>App', ...)` dans le Twig doit refléter le chemin réel. Le parallelisme Twig/Vue est donc automatique.

### Imports entre features (cross-group)

Quand un `*App.vue` orchestrateur (ex : `PlanningsApp`) importe des composables d'une autre feature du même module, utiliser des chemins relatifs remontants : `../events/composables/useEventForm.js`.

### Exceptions - ne pas créer de sous-dossier

- Module avec **une seule feature** et ≤ 6 fichiers : la feature = tout le dossier module. Exemple : `PasswordGenerator/backend/` (2 fichiers), `Vault/backend/` (SPA mono-page avec components/ + composables/).
- `shared/`, `components/`, `utils/`, `constants/` restent à la racine du module (cross-cutting).

## Pourquoi

Éviter les dossiers plats de 20+ fichiers qui ne permettent plus de distinguer d'un coup d'œil ce qui appartient à quelle page/entité.

## Comment l'appliquer

1. Nouvelle feature dans un module existant → créer le sous-dossier d'emblée (ne pas poser les fichiers à plat).
2. Feature existante à plat → refacto avec `git mv` (préserve l'historique) + mise à jour des imports (`@alias/backend/<feature>/composable.js` → `./composables/composable.js` relatif dans la plupart des cas) + mise à jour des `vue_component(...)` dans les Twig.
3. Après tout déplacement : `npm run build` + `php bin/phpunit`.

### Modules déjà conformes (référence)

| Module | Sous-dossiers |
|---|---|
| Editorial | posts/, comments/, menus/, forms/, post-types/, taxonomies/ |
| Ecommerce | listings/, orders/ (backend), checkout/ (frontend) |
| Photo | galleries/, gallery-edit/ |
| Erp | products/ |
| Crm | companies/, contacts/, deals/ |
| Billing | invoices/, tiers/, compliance/, ocr/ |
| Ged | documents/, document-categories/ |
| Hr | employees/ |
| Planning | plannings/, events/, resources/ |
| Project | projects/, tasks/ |
| PdfForm | documents/ (avec components/ interne), templates/ |
| Core/dev | users/, access-requests/, audit/, permissions/, modules/, mount-points/, parameters/, overview/ |
