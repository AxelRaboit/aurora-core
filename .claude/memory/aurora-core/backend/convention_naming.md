# Conventions de naming

## Règle

### Variables
**Mots complets, jamais d'abréviation ni de nom d'une ou deux lettres**, y
compris `$i` en boucle (`$index`), `$a`/`$b` en comparateur (`$left`/`$right`)
et `$e` en catch (`$exception`). Tenu depuis le 07/10/2026 par
`tests/Unit/NamesAreFullWordsTest.php` (PHP et Twig) et la règle ESLint
`aurora/full-word-names` (JS/Vue), qui lisent une seule liste :
`tools/naming/full-word-names.json` (mots interdits, noms courts permis).
Renommer un argument de constructeur qu'aurora-client lie par son nom dans
son `config/services.yaml` (`$auroraDirectory`, `$extraSourceDirectories` de
`DumpJsTranslationsCommand`) est une rupture : à faire dans une majeure, avec
la ligne du client. Ce jour-là, ~2 700 noms abrégés dans ~560 fichiers ont été
renommés d'un coup : la règle était écrite depuis longtemps, rien ne la tenait.

Permis : `t` (vue-i18n), `props`, `emit`, `$io` (SymfonyStyle), le suffixe
`Ref` des refs Vue, les unités `Ms`/`Px`, les sigles (`id`, `url`…), `x`/`y`
pour de vraies coordonnées, `$at`/`$on`/`$by`. En JS, jamais `document`,
`window` ou `event` comme nom de variable : ce sont des globales du navigateur.

✅ `$company`, `$contact`, `$translation`, `$invoice`, `$auditPayload`
❌ `$c`, `$ct`, `$tr`, `$inv`, `$payload` (si shadowed)

**Pour une instance d'un service / classe : reprendre le nom complet en
camelCase**. Si le service s'appelle `MySuperCoolFoo`, la variable est
`$mySuperCoolFoo`. Pas d'abréviation, pas de raccourci.

✅ `$projectColumnManager`, `$galleryDownloadService`, `$mySuperCoolFoo`
❌ `$mgr`, `$svc`, `$pcm`, `$gds`, `$foo` (perd le contexte)

### Dossiers / namespaces
- `Dto/` (pas `DTO/`) - l'acronyme reste "DTO" en prose mais le namespace
  est `Dto` PascalCase comme tous les autres.
- `Manager/` pour les classes qui persistent/flushent une entité.
- `Service/` pour la logique stateless pure (helpers, calculs, validateurs).
- **Plus jamais `Contract/`** pour les Managers instrumentés - l'interface
  vit dans `Manager/`. `Contract/` reste OK pour des interfaces non-Manager
  légitimes (provider patterns, location registries).

### Fichiers / classes
- **Pas de préfixe `Admin` dans les noms de fichiers/classes**. Le contexte
  (backend vs frontend) se déduit du dossier (`Controller/Suite/`,
  `Controller/Frontend/`, `Security/Suite/`…). Un préfixe `Admin` est
  l'ancienne convention - toujours renommer si rencontré.
  ✅ `UserChecker.php` dans `Security/Suite/`
  ❌ `SuiteUserChecker.php` ou `AdminUserChecker.php` à la racine de `Security/`
- `<Name>Interface` (jamais `<Name>InterfaceInterface` - celui-là on l'a vu)
- `<Name>InputInterface` + `<Name>InputFactory` + `<Name>InputFactoryInterface`
  + `<Name>Input` (concrete)
- `<Name>ManagerInterface` + `<Name>Manager` (concrete)
- `<Name>SerializerInterface` + `<Name>Serializer` (concrete)
- Sub-DTO (consommés en interne par un DTO racine) : `final readonly class`,
  pas d'instrumentation.

### Côté Vue
- Composable unifié : `useXxxForm.js` (pas `useXxxEdit` ni `useXxxCreate`).
  Exception : User (invite/edit) et Theme (create simple/edit complexe) ont
  deux composables car les forms n'ont rien en commun.
- Slots scoped : `extra-headers`, `extra-cells`, `extra-form-fields`.
- Callback de hydratation depuis une entité : `fromEntity(entity)` (jamais
  `fromAgency`, `fromDeal`, etc - le nom doit rester générique).

## Pourquoi

Cohérence. La convention sur 24 entités le démontre : un développeur peut
ouvrir n'importe quelle entité et trouver les mêmes fichiers aux mêmes
endroits avec les mêmes noms. La charge cognitive est minime.

## Comment l'appliquer

Avant de nommer une variable / fichier, vérifier que :
1. Pas d'abréviation ni de nom d'une ou deux lettres (les deux garde-fous le disent)
2. Le suffixe correspond au rôle (`Manager`/`Service`/`Repository`/`Serializer`)
3. Le dossier est en PascalCase (`Dto/` pas `DTO/`)
4. L'interface est nommée `<Name><Suffix>Interface` à côté de la concrete

## Préférences utilisateur

L'utilisateur a explicitement demandé "noms complets, pas d'abréviations" et
"renommer DTO en Dto" pendant le rollout. À respecter.
