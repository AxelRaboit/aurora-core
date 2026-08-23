# Stratégie de découplage « graphe en étoile »

> **Décision d'architecture (2026-05-30)**, en remplacement de l'option
> « bridges + require » initialement esquissée. Doc de référence du split
> monorepo : **comment** chaque module distribuable est rendu autonome. Le
> split est **réalisé** (13 packages sur GitHub) ; ce doc garde la rationale.

## Principe (invariant à tenir)

> **Un module distribuable n'importe JAMAIS un autre module distribuable.**
> Il ne dépend que d'`aurora-core`. Si deux modules sont sémantiquement
> inséparables, on les **fusionne** ; on ne crée pas de dépendance latérale
> ni de package bridge.

Conséquence : le graphe de dépendances des packages devient une **étoile**
(tout → `aurora-core`, zéro arête latérale). Chaque module devient un
**leaf** trivial à splitter, et un client ne tire **jamais** un module
qu'il n'a pas demandé.

**Périmètre de « core »** (imports autorisés depuis n'importe quel module) :
`Core/` + **Platform** + **Configuration** + **Dev** + **Ged**. Importer
ces namespaces = dépendre d'`aurora-core` = OK. L'invariant ne porte que
sur les modules **métier distribuables**.

### Critère de vérification (gate automatisable)

Pour chaque module métier `Y`, la commande suivante doit retourner **vide** :

```bash
grep -rhoE "use Aurora\\\\Module\\\\[A-Za-z]+" src/Module/<Y>/ \
  | grep -vE "Aurora\\\\Module\\\\(<Y>|Platform|Configuration|Dev|Ged)\\\\"
```

C'est le **critère de sortie du pass de découplage** (et un futur test
d'architecture, type `deptrac` / PHPStan rule).

## Taxonomie des arêtes & traitement

Les ~15 arêtes cross-business se répartissent en **5 catégories**, chacune
avec un mécanisme de découplage. Toutes les arêtes sont listées ci-dessous.

### Catégorie A - Value enum partagé → remonter en core

Une `enum` de valeur (pas de logique module) importée ailleurs.

| Arête | Symbole | Source actuelle | Action |
|---|---|---|---|
| Billing→Erp (3) | `CurrencyEnum` | déplacé : `src/Core/Money/Enum/CurrencyEnum.php` (`Aurora\Core\Money\Enum`) | ✅ **FAIT** (2026-05-30) - Billing+Ecommerce/Erp sont des packages distincts qui en ont besoin |
| Ecommerce→Erp (7) | `CurrencyEnum` | idem | ✅ **FAIT** (même déplacement) |
| ~~Erp→Ecommerce (1)~~ | `EcommerceSettingEnum` | `ProductSerializer` (Erp) lit un setting Ecommerce | ⏭️ **Sans objet** : Erp+Ecommerce **fusionnent** (cat. E → `aurora-commerce`), donc cette arête devient **intra-package**. Le « cycle » est moot après merge. Pas de churn. |

→ Élimine **11 refs** et le **cycle**. Risque faible (déplacement + maj des
`use`). À faire **en premier**.

### Catégorie B - Event cross-module → contrat d'event en core ✅ FAIT (2026-05-30)

Un module écoutait un event émis par un autre via l'import direct de cet event.

**Réalisé** : un **seul** event core unifie les deux cas (un producteur
capture des coordonnées → un CRM optionnel les matérialise en contact) :
`src/Core/Contact/Event/ContactSignalEvent.php` (email, fullName, phone,
sourceKey, tagSlugs - que des scalaires).

| Arête | Avant | Après |
|---|---|---|
| ~~Crm→Ecommerce (1)~~ | `OrderCrmSyncListener` ← `OrderCreatedEvent` | Ecommerce `OrderManager` dispatch **aussi** `ContactSignalEvent('order',['client'])` ; Crm écoute le core |
| ~~Crm→Editorial (3)~~ | `FormSubmissionCrmSyncListener` ← `FormSubmissionCreatedEvent` + `FormInterface` + `FormFieldTypeEnum` | l'extraction (email/name/tel par type de champ) **remonte dans Editorial** `FormManager` (qui possède la taxonomie) ; il dispatch `ContactSignalEvent('form')` si `isCrmSync()` |

Les 2 listeners Crm (`OrderCrmSyncListener`, `FormSubmissionCrmSyncListener`)
sont **fusionnés** en un seul `ContactSignalListener` qui ne dépend que du
core. Gating : 'order' reste opt-in via `crm_sync_orders` ; 'form' est gardé
par le producteur (`isCrmSync`).

→ **Résultat : Crm devient un leaf pur** (plus aucune arête cross-business ;
grep d'invariant vide). Producteurs Ecommerce/Editorial dispatchent
toujours ; sans CRM installé l'event est un no-op inoffensif.

### Catégorie C - Embed / agrégation de features → registry de providers en core ✅ FAIT (2026-05-30)

Un module **consommait** des features d'autres modules par import direct.
Core définit un **registry** (tag `_instanceof`) ; chaque module **enregistre**
sa contribution ; le consommateur injecte `iterable<…>` via `#[AutowireIterator]`.

| Arête(s) | Avant | Après |
|---|---|---|
| **C1** General→{Billing,Crm,Ecommerce,Editorial,Erp,Photo} | `Dashboard/StatsService` importait 6 repos modules | `Core\Dashboard\DashboardStatsProviderInterface` ; 6 providers (un par module, EditorialStatsProvider couvre posts/media/users) ; StatsService = agrégateur |
| **C2** General→{Editorial,Project} (+ →General de Editorial/Project/Assistant via le LLM `SearchProviderInterface`) | backend `SearchController` importait Editorial+Project ; `SearchProviderInterface` vivait dans General | `SearchProviderInterface` + `SearchSnippetBuilder` + `RelevanceSorter` → **core** ; nouveau `Core\Search\BackendSearchProviderInterface` ; providers Editorial/Project/Ged ; `RebuildSearchIndexCommand` → Editorial |
| **C3** Editorial→Ecommerce (3) | `BlocksRenderer` embed un `ListingInterface` | `Core\Content\BlockRendererInterface` + `BlockHtmlSanitizer` ; Ecommerce ship `ProductGridBlockRenderer` ('productGrid') ; BlocksRenderer délègue le `default` au registry |

→ **Résultat : General, Editorial, Assistant deviennent des leaves purs.**
Le risque d'inversion core→module (General dans core important des modules)
est éliminé. 9/14 modules métier sont désormais des leaves purs.

### Catégorie D - Lien entité vers Crm → soft reference ✅ FAIT (2026-05-30)

Réalisé via un resolver core **`Aurora\Core\Reference\EntityReferenceResolver`**
(+ `EntityReferenceProviderInterface`, tag `aurora.entity_reference_provider`) :
`summarize(type, id)` → résumé d'affichage ou null ; `supports(type)` → module
installé ? ; `options(type)` → liste pour picker. Crm fournit
`Contact/Company/DealReferenceProvider`.

| Arête | Avant | Après |
|---|---|---|
| Billing→Crm | `Tiers`→`Company` (ManyToOne) | `?int $companyId` ; migration drop FK |
| Photo→Crm | `Gallery`→`Contact` + ViewBuilder `CrmContext` + Manager repo | `?int $clientContactId` ; serializer/notif via resolver ; ViewBuilder `supports()` ; migration drop FK |
| Project→Crm | `crmCompany/crmDeal` (ManyToOne) + `crmContacts` (**ManyToMany**) + repos | `?int crmCompanyId/crmDealId` + `crm_contact_ids` **JSON** ; serializer/viewbuilder via resolver ; migration drop 2 FK + collapse join-table→JSON |
| ~~Project→Billing~~ | `ProjectInvoiceManager` (« facturer un projet ») | **Relocalisé côté client** (retiré d'aurora-core : backend + Vue + i18n) |

→ Billing, Photo, Project deviennent des leaves purs. Compromis assumé : perte
de l'intégrité FK DB pour ces liens optionnels (cf. migrations). down() des
migrations re-crée les FK → valide seulement si Crm installé (Phase 5).

### ~~Catégorie D - Lien entité vers Crm → soft reference (le point qui coûte)~~ (description originale)

Une entité d'un module a une **relation Doctrine via interface** vers une
entité Crm, + injecte le **repository** Crm pour hydrater. Problème dur :
si Crm n'est **pas** installé, la relation vers une interface non résolue
par `resolve_target_entities` = **erreur de mapping Doctrine**.

| Arête | Entité → cible | Détail |
|---|---|---|
| Billing→Crm (2) | `Tiers` → `CompanyInterface` | + relation Doctrine |
| Project→Crm (16) | `AbstractProject` → `Company/Contact/DealInterface` | + `ContactRepository`/`CompanyRepository`/`DealRepository` injectés |
| Photo→Crm (5) | `AbstractGallery` → `ContactInterface` | + `ContactRepository` + `CrmContext` |

**Décision : soft reference.** Au lieu d'une FK Doctrine vers une entité
d'un module possiblement absent :
- stocker un **identifiant + type** (`?int $contactId` / value object
  `EntityReference`), **pas** de `ManyToOne` vers `ContactInterface` ;
- résolution **paresseuse et optionnelle** via un **`Core\Reference\
  ReferenceResolverInterface`** : si Crm présent, il résout le contact ;
  sinon la référence reste « pendante » (affichée en ID brut ou masquée).

Compromis assumé : **perte de l'intégrité FK au niveau DB** pour ces liens
optionnels, en échange du découplage total. C'est cohérent avec « un module
optionnel ne doit pas casser le schéma quand il est absent ».

> ⚠️ **Risque #1 du chantier** : c'est la catégorie la plus délicate et
> celle à **valider en POC en priorité** (avant tout rollout). Si la soft
> reference s'avère trop pénible (UX admin, requêtes), repli possible :
> ces modules **require** Crm (on perd le « léger » pour eux seuls, pas
> pour les leaves).

### Catégorie E - Mono-domaine réel → fusion

Couplage **dur** sur une **entité concrète** (pas juste une interface),
signe que c'est un seul domaine.

| Arête | Détail | Action |
|---|---|---|
| **Ecommerce→Erp** (8 après retrait CurrencyEnum) | `Listing`/`Order`/`Cart` ↔ **`Product`** (entité concrète : `OrderManager`, `OrderRefundService`, `ListingManager`) | **FUSION** : `aurora-commerce` = **Ecommerce + Erp**. Un listing vend un produit = un domaine. |

→ **Seule fusion nécessaire** ✅ **FAIT** : Ecommerce + Erp restent 2 bundles
mais shippent dans un seul package `aurora-commerce` (l'arête Ecommerce↔Erp est
intra-package, donc tolérée). Crm, Billing, Project, Editorial, Photo restent
**autonomes** (leurs couplages relèvent de A/B/C/D).

## ✅ Invariant atteint (2026-05-30)

Le grep de vérification est vide pour tous les modules métier (hors le couple
intra-package Ecommerce↔Erp). **13 modules métier** shippent chacun via leur
`Aurora<X>Bundle` (`AbstractAuroraModuleBundle`) ; `AuroraBundle` est devenu un
bundle **purement core** (16 RTE : Platform/Configuration/Dev/Ged). Un client
compose son install à la carte module par module. Le packaging Composer
(composer.json + services.php + split) est **réalisé** - voir
`packaging_playbook.md` et `bin/split-modules.sh`.

## Extension points à créer dans `aurora-core` (avant le pass)

Le découplage suppose d'ajouter ces points d'extension au core. Tous
suivent la convention Aurora (interface + `#[AsAlias]`/tagged services) :

1. **`Core\Money\Enum\CurrencyEnum`** (déplacé depuis Erp) - cat. A. ✅
2. **`Core\Contact\Event\ContactSignalEvent`** : event générique
   (email/name/phone/sourceKey/tagSlugs) émis par Ecommerce/Editorial,
   écouté par Crm - cat. B. ✅
3. **`Core\Content\BlockRendererInterface`** + `BlockHtmlSanitizer` - cat. C3. ✅
4. **`Core\Dashboard\DashboardStatsProviderInterface`** - cat. C1. ✅
5. **`Core\Search\{SearchProviderInterface, BackendSearchProviderInterface,
   SearchSnippetBuilder, RelevanceSorter}`** - cat. C2. ✅
6. **`Core\Reference\EntityReference` + `ReferenceResolverInterface`** -
   cat. D. ⏳ à faire

Chacun doit passer les **garde-fous** §3bis du CLAUDE.md (pas d'interface
sans implémenteur multiple plausible) - ici tous en ont (≥2 modules
contributeurs), donc OK.

## Ordre d'exécution (le pass de découplage)

1. **Créer les 6 extension points core** (ci-dessus).
2. **Cat. A** : déplacer `CurrencyEnum` → core, neutraliser
   `EcommerceSettingEnum` côté Erp. ✔ vérifier : cycle cassé.
3. **Cat. B** : events → contrats core, recâbler les 2 listeners Crm.
4. **Cat. C** : registries blocs/widgets/search ; recâbler Editorial +
   **General** (le plus gros morceau).
5. **Cat. D** : soft-references Billing/Project/Photo → Crm. **(POC ici.)**
6. **Cat. E** : fusionner Ecommerce + Erp → `aurora-commerce`.
7. **Vérifier l'invariant** : le grep §critère est vide pour TOUS les
   modules métier. → graphe en étoile atteint.
8. **Tests verts + build OK** à chaque étape (commits atomiques par
   catégorie/module, cf. CLAUDE.md §6).

→ **Seulement après l'étape 7**, on attaque le split Composer (POC outil
splitsh + sous-bundles, jalon J3 du workplan). Le découplage est le
**prérequis**, pas une étape parallèle.

## Pourquoi c'est le bon choix

- **Définitif** : zéro dette de couplage reportée dans la topologie des
  packages. Pas de `require` en cascade, pas de bridge à maintenir.
- **« Léger » réel** : chaque client compose exactement ses modules.
- **Idiomatique** : registries/providers/events + `resolve_target_entities`
  sont déjà le vocabulaire d'Aurora ; on l'étend, on n'invente rien.
- **Aligné §3bis** « penser long terme » : on fait l'abstraction saine
  maintenant, sans attendre qu'un besoin force la refacto.
- **Testable** : l'invariant est un grep / une règle deptrac → non-
  régression garantie.
