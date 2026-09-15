---
name: Studio - espaces clients
description: Le sous-module CustomerSpace, la règle du docblock Studio qu'il a fallu amender, et le pari "une entité, deux vues" pour la suite
type: project
---

## Règle

Un **espace client** (`CustomerSpace`, sous `src/Module/Studio/CustomerSpace/`)
est le contenant du travail mené pour un client. Il porte un nom lisible, une
relation **obligatoire** vers `Customer`, un statut actif/archivé, une couleur
de palette, un fuseau horaire, et une liste de membres (`CustomerSpaceMember`).

**Plusieurs espaces peuvent nommer le même client** : `ManyToOne`, pas
`OneToOne`. Un client avec deux marques fait tourner deux calendriers, et un
client qui signe pour un site puis pour du social a deux chantiers de rythmes
différents. La contrainte inverse est la moins chère à poser et la plus chère à
retirer.

## Pourquoi la règle du module a dû bouger

Le docblock de `StudioModule` disait : *"Studio holds what is sold and what is
delivered. Not the tools it is made with."* Un espace client n'est ni vendu ni
livré : c'est la **surface sur laquelle on livre**. La règle a été amendée dans
le docblock, en le disant.

L'alternative - un module `Workspace` à part - a été écartée pour la raison
exacte qui avait déjà coûté le renommage d'Accounting en Studio : *"a module may
not hold a relation to another module's entity"*. Un espace sans relation
Doctrine vers `Customer` n'est pas un espace client.

La frontière que la règle refuse toujours est la même : **un outil appartient à
son module**. C'est pourquoi la suite annonce ses dates à Planning au lieu de
dessiner son calendrier, et range ses fichiers dans la GED au lieu d'en tenir
un second.

## Le pari pour la suite : une entité, deux vues

Le calendrier éditorial et le tableau kanban ne sont pas deux fonctionnalités :
ce sont deux lectures des mêmes publications. `SpaceContentItem` portera un
statut (donc une colonne) et une date planifiée (donc un créneau). Deux
composants Vue, une seule entité, zéro double saisie par construction.

Ce qui en découle, vérifié dans le code :

- **Ne pas réécrire de moteur de calendrier.** `CalendarMonth.vue` est purement
  présentationnel (props `cells`/`events`, aucun appel réseau) et `monthGrid.js`
  n'a aucun import. À promouvoir vers `src/Core/assets/shared/components/calendar/`
  plutôt qu'à importer depuis `@planning` : il n'existe aujourd'hui **aucun**
  import Vue croisé entre modules, et Studio doit continuer à se construire sans
  Planning.
- **Un seul `sourceType` pour tous les espaces**, pas un par espace.
  `ModuleCalendarProvider::forSource()` crée un calendrier `Shared` sans
  propriétaire, et `PlanningRepository::findVisibleTo()` renvoie tout ce qui est
  partagé à tout le monde : quinze clients feraient quinze calendriers dans la
  barre latérale de chaque membre de l'équipe.
- **Il manque une couleur sur `EntityScheduledEvent`.** `PlanningEvent` sait
  porter la sienne (`getEffectiveColourSlot()`), mais l'événement de core ne la
  transporte pas, donc tous les espaces arriveraient de la même couleur dans
  l'agenda interne. C'est la seule modification de `Core/Scheduling` que ce
  chantier réclame.

## Pièges rencontrés en écrivant le lot 1

- **`doctrine:migrations:diff` est inutilisable sur ce dépôt.** La base de dev
  garde les tables des modules retirés (Billing, Crm, Ecommerce, Assistant…), et
  le diff propose de toutes les supprimer : 100 Ko de destruction autour de deux
  `CREATE TABLE`. Générer, extraire les seules lignes qui nomment les nouvelles
  tables, écrire la migration à la main.
- **Les fichiers fr et en ne rangent pas leurs clés dans le même ordre.**
  Ancrer une insertion sur une ligne dont les voisines diffèrent d'un fichier à
  l'autre a orphelinisé `customers.errors.has_contracts` dans le bloc `spaces`.
  `TranslationConsistencyTest::testFrEnKeyParity` l'a attrapé.
- **`AuditActionLabelTest` échoue dès qu'un Manager émet une action sans
  libellé.** Trois clés (`customer_space.created/updated/deleted`) dans les deux
  langues, dans `backend.audit.actions.studio`.
- **`status:` ne peut pas être à la fois un libellé de champ et un bloc.** Le
  sous-bloc est `statuses:`, et l'enum pointe dessus.
- **L'espagnol ne couvre que le public.** `messages.es.yaml` ne contient que ce
  que lit un client ; le back-office reste en repli français (cf.
  [[aurora-three-locales]]). Rien à y ajouter pour un écran d'administration.

## État

Lot 1 livré : entités, dépôts, DTO, Manager, Serializer, contrôleur, écran de
liste, bascule `StudioSpaces` (en cascade sous `StudioCustomers`), quatre
permissions `studio.spaces.*`, migration, 10 tests d'intégration.

Supprimer un client qui a encore un espace est refusé en toutes lettres par
`CustomerManager::delete`, avant que la clé étrangère `RESTRICT` ne réponde par
un 500 - le contrat est vérifié en premier, parce qu'un espace se supprime et
un contrat signé non.

Reste : la page à onglets de l'espace, `SpaceContentItem` et ses deux vues,
l'accès client par lien signé, l'onglet Fichiers adossé à la GED, puis le
miroir Google Drive.

## Liens

- [[architecture_module_parameter_enum]] - corrigée pendant ce lot : elle
  décrivait des enums par module qui n'existent plus.
- [[project_planning_share_link_write_access]] - les deux prérequis (colonne de
  droit, limite de débit) à traiter avant d'ouvrir l'écriture à un invité, ce
  qu'une validation client sera.
