# Aurora — TODO technique

Tâches techniques identifiées mais non encore implémentées, organisées par module
puis par topic.

## Index

### Architecture / refactos

- [Fusion Media → GED](media-ged-merge.md) — supprimer `/backend/media/media` et
  faire de `Document` (GED) l'unique entité fichier. Plan en 5 phases
  (parité de rendu sur Document → migration des consommateurs FK → contenu
  embarqué → picker unifié → suppression de Media). **Décision prise, à
  démarrer.**

### Éditorial

- [Grille de contenu 48 colonnes](content-grid-48.md) — des zones
  redimensionnables et déplaçables sur la grille que la bannière utilise déjà,
  avec du texte Editor.js, une autre publication, un média ou une URL vidéo
  dans chacune. Décisions structurantes prises (48 colonnes, pas d'aimantation
  variable, `{base, md, lg}`). **5 étapes sur 6 livrées** — contrat, rendu
  public, quatre types de zone, éditeur, aperçu. Restent le sort de `blocks`,
  qui cohabite, et **un chantier d'ergonomie sur le réglage de largeur** : les
  curseurs fonctionnent mais parlent en « 24 colonnes sur 48 », ne montrent
  rien pendant le geste et sont difficiles à viser.

### Frontend / Vue

- [Audit `translateServerErrors`](translate_server_errors_audit.md) — ~18
  fichiers Vue bindent `data.errors` à `:error` sans passer le payload
  par `translateServerErrors`, ce qui affiche une clé i18n brute sous le
  champ. Mix de vrais bugs et de cas légitimes à trier (toast-only,
  composables génériques).

### Roadmap modules

Liste des modules à venir, classés par priorité et impact.

- [Roadmap modules](module_roadmap.md)

### ~~Welding — workflows de soudure réglementée~~ — ✅ V1 livrée puis extrait en client (mai 2026)

V1 livrée dans aurora-core (sprints -1 à 5 + post-V1 sprints 6-10), puis
le module entier a été extrait vers le projet client `aurora-welding`
(spécifique soudure réglementée — nucléaire RCC-M, ASME III, ISO 15614).
La doc Welding et le backlog V2 vivent maintenant dans
`aurora-welding/docs/welding/README.md`. Procédure d'extraction
généralisée :
[`../dev/extracting_a_module.md`](../dev/extracting_a_module.md).

### ~~Ecommerce — gaps vs Sylius~~ — ✅ extrait, cf. aurora-commerce

Aurora a été recentré sur Core + Editorial (CMS façon WordPress) ; les
modules Ecommerce/Erp ont été retirés du monorepo. Le code et le gap
analysis vs Sylius (catalogue, tarification, livraison, promotions,
client, paiement, stock) restent figés dans le repo
[`aurora-commerce`](https://github.com/AxelRaboit/aurora-commerce),
non ré-publié depuis aurora-core. Procédure générale d'extraction :
[`../dev/extracting_a_module.md`](../dev/extracting_a_module.md).
Pour retrouver le commit exact du retrait (tag `pre-simplify-editorial-only`
+ liste des commits) et réintégrer un jour, voir
[`module_roadmap.md`](module_roadmap.md#état-actuel).

### ~~PersonalFinance (Spendly)~~ — ✅ livré

Le port complet du projet Spendly vers `src/Module/PersonalFinance/` est
terminé (V1 sealed mai 2026 + V2 complète mai 2026 incluant les sessions
Excel export/import, BudgetPreset, Reset mois, tracking modes des Goals).
Le scaffold de planning sous `spendly/` a été supprimé une fois le port
clos. État détaillé + historique des sessions dans la mémoire
`project_personal_finance_port_status.md`.

## Convention

- Un fichier par **topic** cohérent (ex : tous les TODOs catalogue dans
  `ecommerce/catalogue.md`).
- Chaque TODO contient :
  - **Contexte** — pourquoi c'est important / quel manque ça comble
  - **Direction d'implémentation** — esquisse de la solution (entités, manager, hooks…)
  - **Pointeurs code** quand pertinent
- Une fois implémenté → supprimer l'entrée (le commit/CHANGELOG fait foi).
- Quand un nouveau module accumule des TODOs → créer `todo/<module>/<topic>.md`
  + ajouter une section dans cet index.
