---
name: decision_suite_visual_redesign
description: Refonte visuelle de la suite (09-10/10/2026) - cadre aligné, menu sans icônes, fil d'Ariane dans le contenu, compteurs, une tuile, une carte et une palette de statuts.
metadata:
  type: project
---

## Règle

La refonte du 09/10/2026, faite d'après une maquette validée par Axel, a posé
quelques invariants. Les respecter dans tout écran neuf de la suite :

- **La barre du haut fait 60 px** (`h-15`, `--aurora-topbar` à 5.75rem en
  `lg`) et ses commandes sont des carrés encadrés de 36 px : `TOPBAR_BUTTON` /
  `TOPBAR_ICON` dans `suite/sidemenu/topbarButton.js`, partagés par la
  recherche, le thème, la cloche, le repli du menu, l'historique et la barre
  du téléphone. Remplace la règle « icône nue dans le cadre de l'app » du
  02/10 (choix d'Axel en validant la maquette). Avatar en `lg` (36 px), `soft`.
- **Le haut du menu est un seul bloc** (site, « Voir le site » en simple lien,
  filtre, interrupteur des descriptions) avec un seul filet dessous ; pied
  signé à gauche en `text-secondary`.
- **Commutateurs segmentés = `aurora-segmented`** (spacing.css) : bande
  `surface-2` sans bordure, 4 px de jeu, rayon 12 px. Les 21 copies de
  `rounded-lg border border-line bg-surface-2/40 p-0.5` y sont passées.
- **Encarts repliés encadrés** : une ligne dans une carte (`px-4 py-3`).
- **Le fil d'Ariane est dans le contenu** (10/10/2026), plus dans l'entête
  collant : `nav[data-breadcrumb]`, texte sur le fond de la page, `h-8`, qui
  défile. Seule la barre de titre colle. `--aurora-topbar` garde sa valeur
  (ce que la page perd en haut), voir `base.css`.
- **Le menu se lit sans plisser les yeux, et sans icônes** (10/10/2026) : le
  point de section et le nom portent la ligne. Libellés de section en
  `text-secondary`, entrées au repos en `text-primary/80`, descriptions en
  `text-secondary`. Les icônes restent dans la palette de recherche et les
  arbres de dossiers. Aucune couleur fixe : l'ancien survol émeraude de
  « Voir le site » ne suivait aucun thème.
- **Des compteurs dans le menu** (10/10/2026) : `NavItemCountProviderInterface`
  (Core/Module/Nav), auto-tagué, lu par `NavItemCounter` dans
  `SidemenuExtension` (`sidemenu_nav_sections(true)` dans le layout, et la vue
  de module). Comptés : publications (au périmètre de la liste,
  `PostAccessService::scopedAuthorId()`), types de contenu, taxonomies,
  documents, utilisateurs, et la corbeille (`TrashOverviewService::countAll`,
  mêmes sources et mêmes droits que l'écran ; chaque source implémente
  `CountableTrashSourceInterface` pour se compter en une requête). Mesuré le
  10/10 : 9 → 23 requêtes sur la liste des utilisateurs en dev, dont 9 pour la
  corbeille. Pas Studio : ses listes ont des périmètres (mes espaces,
  perso/partagés) qu'un compte global trahirait.
- **Un chiffre de tableau de bord = `AppStatTile`, un bloc = `AppSectionCard`.**
  La tuile s'aligne par sous-grille : la poser directement dans la grille.
- **Une couleur par statut de publication, une seule source** :
  `POST_STATUS_COLORS` et `POST_STATUS_CHART_SLOTS` dans `statusStyles.js`. Les
  copies locales de la liste, de l'éditeur et des révisions ont été retirées.
- **Les encarts « Comment ça marche » ouverts sont des cartes pleines**, plus des
  cadres pointillés (réservés aux zones de dépôt).
- La barre du haut porte un interrupteur clair / sombre, encadré comme ses
  voisines (`TOPBAR_BUTTON`).

## Pourquoi

Axel voulait une suite « encore plus pro ». La maquette montrait surtout de la
cohérence : chaque panneau du tableau de bord dessinait ses chiffres à sa façon,
la barre des statuts peignait une publication en jaune quand la liste la
montrait en vert, et les filets du menu et de la page tombaient à seize pixels
l'un de l'autre.

## Comment l'appliquer

- Nouveau panneau de tableau de bord : `AppStatTile` + `AppSectionCard`,
  jamais un `aurora-card` + `h3` écrit à la main.
- Nouvel endroit qui affiche un statut de publication : importer
  `POST_STATUS_COLORS`, ne pas recopier la table.
- Créneaux de graphique nommés : voir [[convention_chart_palette]] (mesurer les
  jointures sous les trois déficiences).
- **Palette des statuts de la maquette** : brouillon ambre, en revue ciel,
  programmée émeraude, publiée ardoise (`ink` : badge `bg-primary/10
  text-primary`, créneau `ink` = `--color-primary`), archivée gris. Violet
  refusé pour « programmée » (protanopie, sombre : 2,5 contre le bleu).
- **Légende des barres en grille** de deux colonnes, filetée.
- **Courbe annotée** : plugin Chart.js inline (`AppChart` accepte `plugins`)
  qui écrit le pic et le dernier mois.
