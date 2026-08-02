# Roadmap — Modules à venir

Inspiré de Dolibarr, cette liste recense les modules manquants dans Aurora, classés par priorité.

## État actuel

> **Recentrage (juillet 2026)** : Aurora a été réduit à **Core + Editorial**
> (CMS façon WordPress). Les modules ci-dessous marqués « extrait, non
> ré-publié » ont été retirés du monorepo ; leur code reste figé dans leur
> repo standalone (`aurora-<module>`), non maintenu depuis aurora-core.
> Réintégration = reprendre depuis ce repo, pas depuis aurora-core.
>
> **Retrouver le retrait exact** — sur `aurora-core` :
> - Tag `pre-simplify-editorial-only` = état du monorepo juste **avant** le
>   retrait (dernier commit avec les 12 modules encore présents).
> - Sur `develop`, le retrait tient dans 5 commits consécutifs juste après
>   ce tag : `1482e4d9` (suppression du code source),
>   `b4c9d2b7` (dé-branchement config/JS), `eeef9c6f` (docs/mémoire),
>   `779bd769` (fixs de code mort trouvés en testant),
>   `b860524f` (fix fixtures démo). Comparer :
>   `git diff pre-simplify-editorial-only..develop` pour voir le diff complet.
> - Sur `split/core`, l'équivalent est un unique commit squashé `8d0c752a`
>   (+ `4c57f0e2` pour le fix fixtures).
> - **Suite (Ollama/Stripe + Agency/Service)** : `develop` `bc1c2f46` +
>   `bab23e68` ; `split/core` `07bd5fee`. Agency/Service (org-chart
>   "qui travaille où" de Platform/User) n'avait plus d'utilité une fois
>   Hr/Crm/etc. retirés — supprimé en totalité.
> - **Reliquat trouvé après coup** (variable Twig `agencies`/`services`
>   encore passée à `UsersApp`, + quelques commentaires d'exemple citant
>   encore `Module/Platform/Agency`) : `develop` `66f7491b` ; `split/core`
>   `cea2bdeb`. Si un nouveau symptôme du même genre apparaît (route,
>   traduction, colonne DB orpheline), il appartient à ce même reliquat —
>   compléter cette liste plutôt qu'ouvrir une nouvelle note.
> - Détail complet de la décision (pourquoi, ce qui a été gardé/coupé,
>   notamment le choix de ne pas toucher aux migrations SQL) :
>   mémoire [[project_monorepo_split_chantier]] section « Recentrage ».
> - **URLs frontend aplaties (juillet 2026)** : conséquence directe du
>   recentrage — Editorial étant désormais le seul front public, le préfixe
>   `/{locale}/editorial/...` (hérité de l'époque où plusieurs modules
>   pouvaient chacun exposer leur propre front) n'a plus de raison d'être.
>   `/{locale}/editorial` devient `/{locale}`, `/{locale}/editorial/{x}/{y}`
>   devient `/{locale}/{x}/{y}`, etc. — les noms de route ne changent pas
>   (`editorial_home`, `editorial_post`, ...), donc tout ce qui génère les
>   URLs via `path()`/`generateUrl()` suit automatiquement. `develop`
>   `58d895d3` ; repo standalone `aurora-editorial` `2ec1620`.
>
> **Editorial complètement sorti du monorepo (juillet 2026)** : dernière
> étape du recentrage — `develop` **devient** Core seul (le contenu qui
> était jusque-là celui de `split/core`), au lieu de contenir Core+Editorial
> avec `split/core` comme filtrage dérivé. Editorial ne vit plus **que**
> dans `aurora-editorial`, même traitement que les 11 autres modules déjà
> extraits. `split/core` est donc supprimé (redondant, `develop` le
> remplace intégralement).
> - **Retrouver l'état d'avant** : branche `archive/develop-pre-editorial-split`
>   = exact contenu de `develop` juste avant ce changement (Core + Editorial
>   combinés, comme depuis le début du recentrage éditorial).
> - **Reliquats trouvés** (`split/core` n'avait en réalité jamais tourné
>   comme application autonome — il n'était consommé que via Composer, où
>   le kernel du projet client prenait le relais) : `config/bundles.php`
>   référençait encore `AuroraEditorialBundle` (classe absente → kernel ne
>   bootait plus du tout) ; le layout frontend partagé (`layout.html.twig`,
>   utilisé même par les pages Auth/login) dépendait en dur d'Editorial
>   (`menu_items()`, route `editorial_home`, composants Vue
>   `SiteHeaderApp`/`SiteFooterApp`) ; `head.html.twig` référençait la route
>   RSS d'Editorial. Tout ça corrigé : `develop` `d9ff538b` (bundles/DI) +
>   `4320d159` (découplage du thème frontend, nouvelle fonction Twig
>   `default_front_home_path()` qui résout vers le front réellement
>   enregistré — GED ici).
> - **Suivi pour une future réintégration d'Editorial** : le thème frontend
>   par défaut de Core est maintenant volontairement minimal (nom du site +
>   sélecteur de langue, pas de menu de nav). Si Editorial est réinstallé
>   un jour, son propre package devrait surcharger `layout.html.twig`/
>   `head.html.twig` (mécanisme déjà documenté dans
>   [frontend_theme_override.md](../dev/frontend_theme_override.md)) pour
>   retrouver le header/footer riches (menus, dropdown compte, flux RSS) —
>   pas fait ici, volontairement hors-scope.

> **Demi-tour : Editorial revient dans aurora-core (août 2026)** — le
> multi-dépôt coûtait plus qu'il ne rapportait pour un module qui est au
> même niveau que Ged ou Platform : bumps Composer manuels (`make
> aurora-update` ne nommait jamais `axelraboit/aurora-editorial`), boucle
> de dev via zip GitHub sans checkout local, et une présentation déjà
> éclatée sur deux repos puisque les templates du thème par défaut
> d'Editorial vivaient dans Core. Editorial sera **reconstruit** comme
> module core simple (`src/Module/Editorial/`, glob central de
> `services.yaml`, entités dans `AuroraBundle::$resolve_target_entities`),
> livré par défaut, sans `composer.json` ni `AuroraEditorialBundle`.
> Reconstruit et non recopié : `aurora-editorial` sert de spécification en
> lecture seule jusqu'à la fin, puis sera archivé.
>
> **Étape 1 — purge des résidus** (ce qui restait après l'extraction :
> 97 occurrences sur 47 fichiers source). Tag `pre-editorial-purge` =
> état juste avant. Commits sur `develop`, du plus ancien au plus récent :
> `e6b89bdc` (crash `/backend/settings`), `f06f8934` (bloc `postsList`),
> `c09200ff` (panneau de dashboard), `ba464f10` (câblage : alias Vite,
> `ThemeResolver::resolveAll()`, thèmes de couleur, persona de démo,
> défauts `DefaultFront`), `04a94e06` (5 templates de thème),
> `726f1dd9` (`bin/make-frontend`).
>
> Trois trouvailles qui n'étaient pas du simple code mort :
> - `SettingsViewBuilder` générait la route `backend_editorial_posts_search` ;
>   `UrlGeneratorInterface::generate()` lève `RouteNotFoundException` plutôt
>   que de dégrader, donc **toute la page `/backend/configuration/settings`
>   tombait** dès qu'Editorial n'était plus installé.
> - `bin/make-frontend` générait un import `@editorial/frontend/LocaleSwitcher.vue`
>   et un appel Twig `menu_items()` : tout module scaffoldé produisait un
>   build cassé et un template qui plante au rendu.
> - `.claude/client_template/composer.json` épinglait `dev-split/core` —
>   une branche gelée depuis le 26 juillet — et requérait
>   `aurora-editorial`. Tout nouveau projet client démarrait sur un core
>   périmé.
>
> Gardés délibérément : les migrations SQL (même raison qu'au recentrage —
> `schema:create` ne construit que les entités restantes), les docblocks
> des points d'extension de Core (`BlockRendererInterface`,
> `SearchProviderInterface`, `DashboardStatsProviderInterface`,
> `ContactSignalEvent`) qui expliquent pourquoi ces coutures existent, et
> les docs d'audit `decoupling_strategy.md` / `packaging_playbook.md` qui
> sont l'archive de la décision.

### Défauts trouvés en reconstruisant (août 2026)

Réécrire plutôt que recopier a fait remonter ces défauts d'`aurora-editorial`.
Aucun n'est « à corriger » : chacun l'est **dans le code reconstruit**, au
commit qui réécrit la pièce concernée. La liste sert de mémoire — plusieurs
relèvent du même motif et le motif se répétera sur ce qui reste à faire.

**Le motif dominant — typage sur la classe concrète là où la convention
d'extensibilité impose l'interface.** Six occurrences sur le seul domaine
Post. À chaque fois l'effet est le même : `resolve_target_entities` est
neutralisé et la substitution promise au client ne fonctionne pas.

| Défaut | Ce que ça donnait |
|---|---|
| `SettingsViewBuilder` générant une route absente | `/backend/configuration/settings` en 500 |
| `bin/make-frontend` générant un import et une fonction Twig absents | Tout module scaffoldé cassé au build et au rendu |
| Kit client épinglé sur `dev-split/core`, branche gelée | Tout nouveau projet démarrait sur un core périmé |
| `AbstractPostType::$supports` déclarant `excerpt` | Capacité annoncée que rien ne lit |
| Clés de contrainte nues (`post_types.errors.*`) | **Toutes** les erreurs de validation affichaient une clé brute |
| `applyInput()` posant le slug d'un type natif | Slug natif modifiable, doublon possible |
| `translate()` instanciant la classe de traduction concrète | Substitution client impossible |
| `isDescendantOf()` comparant en `===` seul | Proxy Doctrine ≠ entité chargée : garde anti-cycle contournable |
| `syncPostTypes()` ne touchant que le côté propriétaire | Liaison écrite en base mais renvoyée comme annulée |
| Auteur et auteur de révision typés sur `User` | `resolve_target_entities` neutralisé |
| `createPost(): Post` contredisant son propre docblock | Le hook d'extension était inutilisable |
| `PostVoter::supports()` sur `Post` concret | Entité substituée : aucun vote, donc refus silencieux |
| Publication programmée et purge sans écriture d'audit | Un post passe en ligne ou disparaît sans trace |
| Docblock du filtre par termes annonçant ET, code faisant OU | Documentation fausse |
| `BlocksRenderer::renderCallout()` lisant `text` et émettant `.callout-info` | Encart vide et non coloré, mais **seulement une fois publié** |

**Trois choses que j'ai cru être des défauts et qui n'en étaient pas** —
vérifiées avant de « corriger » : le titre absent de `search_content` (le
repository le cherche par son propre LIKE), la contrainte de route
`\d+|__id__` (les gabarits d'URL passent le placeholder au générateur), et
les dépendances `@editorjs/*` (importées par un composant générique de Core).

**Ce que les outils ne voient pas.** Les défauts les plus coûteux — page en
500, liste non restreinte à son auteur, gabarit d'URL cassé — n'ont été
trouvés qu'en manipulant l'application avec une vraie base. Ni PHPStan, ni
les 739 tests, ni la relecture ne les signalaient. Vérifier chaque domaine
contre le serveur qui tourne n'est pas du confort.

| Module | Statut |
|---|---|
| Editorial (CMS/Blog) | 🔄 En reconstruction dans Core — spec : `aurora-editorial` |
| GED (documents) | ✅ Core |
| Media (médiathèque) | ✅ Core — fusion vers GED planifiée, cf. [media-ged-merge](media-ged-merge.md) |
| ~~CRM (contacts, entreprises, affaires)~~ | Extrait, non ré-publié — `aurora-crm` |
| ~~ERP (produits)~~ | Extrait, non ré-publié — `aurora-commerce` |
| ~~Ecommerce (catalogue, panier, commandes)~~ | Extrait, non ré-publié — `aurora-commerce` |
| ~~Billing (factures, avoir, OCR, tiers)~~ | Extrait, non ré-publié — `aurora-billing` |
| ~~Photo (galeries client)~~ | Extrait, non ré-publié — `aurora-photo` |
| ~~Project (projets / tâches)~~ | Extrait, non ré-publié — `aurora-project` |
| ~~Planning / Agenda~~ | Extrait, non ré-publié — `aurora-planning` |
| ~~HR (fiches employés)~~ | Extrait, non ré-publié — `aurora-hr` |
| ~~Notes (Markdown + Block / EditorJS)~~ | Extrait, non ré-publié — `aurora-notes` |
| ~~Vault (Safe + PasswordGenerator)~~ | Extrait, non ré-publié — `aurora-tools` |
| ~~Assistant (Ollama / chat IA)~~ | Extrait, non ré-publié — `aurora-assistant` |
| ~~PersonalFinance (Spendly)~~ | Extrait, non ré-publié — `aurora-personal-finance` |
| ~~PdfForm (formulaires PDF)~~ | Absorbé dans Welding (sprint 6, mai 2026), puis extrait en client `aurora-welding` |
| ~~Welding (workflows de soudure réglementée)~~ | Extrait en client `aurora-welding/` (mai 2026 — premier usage du playbook [dev/extracting_a_module.md](../dev/extracting_a_module.md)) |

---

## 🔴 Haute priorité

### Contrats / Abonnements
**Inspiré de :** Dolibarr — Module Contrats  
**Pourquoi :** Génère des factures récurrentes automatiquement. Indispensable pour les modèles SaaS, maintenance, abonnements.  
**Fonctionnalités cibles :**
- Contrats avec période, montant, renouvellement
- Génération automatique de factures récurrentes
- Alertes d'échéance
- Lien vers tiers (Billing)

---

## 🟡 Valeur selon le secteur

### Support / Tickets
**Inspiré de :** Dolibarr — Module Ticket  
**Pourquoi :** Helpdesk post-vente. Lié aux contacts CRM pour un suivi 360°.  
**Fonctionnalités cibles :**
- Tickets avec statut, priorité, catégorie
- Assignation à un membre de l'équipe
- Historique des échanges
- Lien vers contacts/commandes

---

### Ressources Humaines
**Inspiré de :** Dolibarr — Module RH  
**Pourquoi :** Gestion interne de l'équipe. Moins prioritaire pour les projets client.  
**Fonctionnalités cibles :**
- Fiches employés ✅ implémentées (entité `Employee` dans `src/Module/Hr/Employee/Entity/`, lien `User`, CRUD backend complet, synchronisation agence/service via `UserAgencyServiceUpdatingEvent`)
- Gestion des congés / absences
- Notes de frais
- Organigramme (lien avec le système Manager existant dans Users)

---

### Stock / Inventaire
**Inspiré de :** Dolibarr — Module Stock  
**Pourquoi :** L'ERP actuel gère les produits mais pas les mouvements de stock.  
**Fonctionnalités cibles :**
- Entrepôts / emplacements
- Mouvements d'entrée / sortie
- Seuil d'alerte stock bas
- Inventaire périodique
- Lien avec Ecommerce (décrémentation automatique à la commande)

---

## 🟢 Long terme

### Banque / Trésorerie
**Inspiré de :** Dolibarr — Module Banque  
**Pourquoi :** Rapprochement bancaire, suivi de la trésorerie réelle vs facturée.  
**Fonctionnalités cibles :**
- Comptes bancaires
- Import de relevés (CSV/OFX)
- Rapprochement avec les factures
- Tableau de bord trésorerie

---

### Expéditions / Livraisons
**Inspiré de :** Dolibarr — Module Expéditions  
**Pourquoi :** Complète le module Ecommerce avec la logistique.  
**Fonctionnalités cibles :**
- Bons de livraison
- Suivi de transporteur
- Lien avec les commandes Ecommerce
- Gestion des retours

---

### Emailing / Campagnes
**Inspiré de :** Dolibarr — Module Emailing  
**Pourquoi :** Exploiter la base de contacts CRM pour des campagnes ciblées.  
**Fonctionnalités cibles :**
- Listes de diffusion depuis les contacts CRM
- Éditeur d'email (blocs)
- Suivi des ouvertures / clics
- Désabonnement automatique

---

## Notes d'implémentation

- Tous les nouveaux modules doivent préfixer leurs tables en `core_`
- Les modules liés au CRM (Contrats, Tickets) doivent réutiliser les entités `CrmContact` et `CrmCompany` existantes
- Chaque module doit implémenter `ModuleInterface` et, pour être activable/désactivable depuis `/dev/dashboard/modules`, déclarer un case `ModuleParameterEnum` + un `<Module>Context` + `ModuleToggleProviderInterface` (cf. skill `/register-module-toggle`)
- Privilégier l'intégration dans le frontend via `FrontendInterface` si le module a une partie publique
