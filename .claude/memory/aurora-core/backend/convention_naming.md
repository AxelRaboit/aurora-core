# Conventions de naming

## Règle

### Variables
**Mots complets, jamais 1-2 lettres** sauf cas idiomatique ultra-court (`$i`
en boucle for triviale).

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
  (backend vs frontend) se déduit du dossier (`Controller/Backend/`,
  `Controller/Frontend/`, `Security/Backend/`…). Un préfixe `Admin` est
  l'ancienne convention - toujours renommer si rencontré.
  ✅ `UserChecker.php` dans `Security/Backend/`
  ❌ `BackendUserChecker.php` ou `AdminUserChecker.php` à la racine de `Security/`
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
1. Pas d'abréviation 1-2 lettres
2. Le suffixe correspond au rôle (`Manager`/`Service`/`Repository`/`Serializer`)
3. Le dossier est en PascalCase (`Dto/` pas `DTO/`)
4. L'interface est nommée `<Name><Suffix>Interface` à côté de la concrete

## Préférences utilisateur

L'utilisateur a explicitement demandé "noms complets, pas d'abréviations" et
"renommer DTO en Dto" pendant le rollout. À respecter.
