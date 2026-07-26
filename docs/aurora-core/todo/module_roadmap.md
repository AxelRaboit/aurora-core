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

| Module | Statut |
|---|---|
| Editorial (CMS/Blog) | ✅ Core |
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
