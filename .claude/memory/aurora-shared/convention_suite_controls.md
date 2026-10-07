---
name: convention_suite_controls
description: Les contrôles de la suite après l'audit UI du 07/10/2026 - une seule case, la règle case/interrupteur/filtre/tri, trois formats de date, montants et tailles dans la langue de la suite
metadata:
  type: feedback
---

## Règle

Décidé avec Axel le 07/10/2026 (audit UI de la suite, trois lots livrés
ensemble) :

- **Une seule case à cocher : `AppCheckbox`.** Sélection d'un tableau comprise
  (`ariaLabel` pour une case sans libellé, `indeterminate` pour « tout
  sélectionner » sur une sélection partielle). `CheckboxesAreTheHouseOnesTest`
  refuse un `<input type="checkbox">` écrit à la main hors des composants
  `form/toggle/` et des écrans visiteurs (`frontend/`, `public/`).
- **Quel contrôle pour un choix binaire :**
  - réglage qui agit tout de suite → `AppToggle` ;
  - choix d'un formulaire validé par Enregistrer, ou sélection multiple →
    `AppCheckbox` ;
  - périmètre d'une liste (actifs / archivés) → onglets ou filtre de la barre ;
  - ordre d'une liste → contrôle de tri dans la barre.
- **Filtres d'une liste dans `AppListToolbar`**, sans libellé, placeholder
  « Tous les … » ; plusieurs valeurs → une ligne de pastilles retirables sous
  la barre (modèle : `PostsApp` + `PostsListFilter`). La recherche ne descend
  pas sous 18 rem : les filtres passent à la ligne.
- **Onglets de portée ou d'étape** : le groupe segmenté
  (`rounded-lg border border-line bg-surface-2/40 p-0.5`), sur une ligne qui
  défile sur téléphone, compte en chiffre gris sans parenthèses (modèles :
  `StudioSectionTabs`, étapes de `ContractsApp`).
- **Dates** : trois formats de `useDateFormat` et pas d'autres -
  `formatDateShort` (« 7 oct. 2026 »), `formatDateTime` (« 7 oct., 09:17 »,
  l'année seulement si ce n'est pas l'année en cours), `formatDate`
  (« 7 octobre 2026 à 09:17 »). Montants : `useMoneyFormat`. Tailles :
  `useFileSize`. Jamais `Intl.*Format(undefined, …)` ni
  `toLocale*String(undefined, …)` : `FormatsFollowTheSuiteLanguageTest`.
- **Pluriels** : jamais « (s) ». vue-i18n « {count} note | {count} notes »
  (le français met 0 au singulier, `frenchPlural`) ; un message lu par PHP
  reçoit `%count%` en plus de `{count}`.
- **Couleur = état** : vert en ligne / validé, ambre à faire, rose bloqué ou
  refusé, gris brouillon ou archivé. Un type, un rôle, une catégorie ne
  prennent pas de couleur d'état.
- **Retour d'une fiche** : le nom de la liste parente (« Clients »), jamais
  « Retour ».

## Pourquoi

L'audit a compté deux dessins de case, trois contrôles pour la même idée,
sept formats de date et des montants « €750 » dans une suite en français :
chaque écran avait inventé les siens, et c'est ce qui faisait « outil maison ».

## Comment l'appliquer

Avant d'écrire un écran, reprendre la barre et les onglets d'un voisin (cf.
[[pattern_admin_list_toolbar]], [[convention_action_sheet]] pour le bouton
principal `primary: true`). Les trois tests nommés plus haut tiennent les
règles mécaniques ; le reste se vérifie à l'œil sur la démo.
