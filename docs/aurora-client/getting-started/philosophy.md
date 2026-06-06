# Philosophie d'Aurora Client

## Ce qu'est aurora-client

Aurora-client est le **template de départ** d'un projet client Aurora : un
squelette propre qui consomme `axelraboit/aurora` via Composer et dans lequel
on construit le domaine propre au projet. On clone le dépôt et on ajoute ses
modules et extensions par-dessus, sans toucher à `vendor/`.

> **Modules d'exemple dans cette doc.** Les guides ci-dessous s'appuient sur
> deux exemples génériques pour illustrer les patterns : un **module client
> from scratch** (`Tracking`) et une **extension d'entité Aurora** (`Agency`
> + champ `code`). Ce sont des supports pédagogiques — ils **ne sont pas
> forcément présents** dans le template ; les chemins `src/Module/Tracking/…`
> ou `src/Module/Platform/Agency/…` montrent simplement *où* ce code irait si
> tu suivais l'exemple.

---

## Ce qu'est un projet Aurora Client (en général)

Un projet Aurora Client est une **application Symfony** qui consomme
`axelraboit/aurora` comme package Composer. Aurora Core fournit la plateforme —
le client ne code que ce qui est propre à son métier.

La relation est asymétrique par design : Aurora Core est stable et versionné,
le client est agile et spécifique. Le client ne doit jamais avoir à choisir
entre "mettre à jour Aurora" et "conserver mes customisations" — les deux
doivent coexister naturellement.

---

## Les deux modes de travail

Un développeur dans un projet Aurora Client a fondamentalement deux façons
d'enrichir l'application. Le bon choix dépend du contexte.

---

### Mode 1 — Étendre un module Aurora existant

**Quand l'utiliser** : tu veux personnaliser quelque chose qu'Aurora fournit
déjà — ajouter un champ à `Agency`, changer le comportement du `DealManager`,
ajouter une colonne dans la liste des factures.

**Le principe** : Aurora expose des points d'extension typés à chaque couche.
Tu n'écris que le delta — les setters de ton champ, le hook `applyInput()`, le
slot Vue. Tu ne copies rien, tu n'overrides que ce qui diffère.

```
Aurora fournit :        Tu écris :
AgencyInterface    →    Agency (+ champ code)
AgencyInput        →    AgencyInput (+ propriété code)
AgencyInputFactory →    AgencyInputFactory (+ parsing code)
AgencyManager      →    AgencyManager (+ createAgency() + applyInput())
AgencySerializer   →    AgencySerializer (+ serialize() avec code)
AgenciesApp.vue    →    slot extra-form-fields (+ <AppInput> pour code)
```

Le contrat est simple : **ne jamais modifier `vendor/`**. Tout ce que tu écris
vit dans `src/` ou `assets/client/`. Une mise à jour Aurora (`make aurora-update`)
ne casse rien.

Pour le guide technique pas-à-pas : [../extending/extend_module.md](../extending/extend_module.md).

---

### Mode 2 — Créer un module entier from scratch

**Quand l'utiliser** : tu veux ajouter une fonctionnalité qui n'existe pas dans
Aurora et qui est propre au projet — un module de suivi de chantiers, un système
de réservations, un configurateur de produits.

**Le principe** : le module client suit exactement la même architecture que les
modules Aurora. Il implémente `ModuleInterface`, déclare ses permissions et sa
navigation, et s'intègre dans la sidemenu admin sans aucune modification d'Aurora.

```
src/Module/Tracking/
├── TrackingModule.php     # manifest : nav + permissions
├── Frontend/
│   └── TrackingFrontend.php  # (optionnel) entrypoint frontend public
└── Project/
    ├── Entity/Project.php
    ├── Dto/ProjectInput.php
    ├── Manager/ProjectManager.php
    ├── Repository/ProjectRepository.php
    ├── Serializer/ProjectSerializer.php
    └── Controller/Admin/ProjectsController.php
```

Le module utilise l'infrastructure Aurora (audit logger, séquences, PayloadValidator,
composants Vue partagés, système de permissions) sans avoir à la réimplémenter.

Pour le guide technique pas-à-pas : [add_module.md](add_module.md).

---

### Mode 3 — Les deux combinés

Les deux modes ne sont pas exclusifs. Un projet mature fait typiquement :

- **Quelques extensions d'entités Aurora** (Agency avec code client, Deal avec
  champs métier spécifiques, Invoice avec un numéro interne)
- **Un ou plusieurs modules client** pour les fonctionnalités propres au domaine
  (suivi de projets, gestion de chantiers, planning de ressources)
- **Quelques overrides de templates** Twig pour adapter le layout ou les labels

---

## Ce qu'on ne fait pas

### On ne fork pas Aurora

Modifier un fichier sous `vendor/axelraboit/aurora/` est interdit. C'est la
règle fondamentale. Si quelque chose dans Aurora ne peut pas être personnalisé
par les points d'extension existants, la solution est d'ajouter un point
d'extension dans Aurora (et de le pousser sur `develop`) — pas de copier le
fichier dans le projet client.

### On ne réimplémente pas ce qu'Aurora fournit

Aurora embarque : authentification, gestion des utilisateurs et des rôles,
upload de fichiers, internationalisation, système de menus, audit log, séquences
numérotées, thème et palette de couleurs, composants Vue partagés (`AppInput`,
`AppModal`, `AppButton`…). Le client n'a pas à réécrire ces briques — il les
consomme.

### On n'utilise pas les préfixes de séquences réservés

Aurora réserve des préfixes pour toutes ses références métier (`FAC`, `ORD`,
`PRJ`…). Les séquences client doivent utiliser des préfixes distincts (≥ 4
caractères, suffixe projet). Un conflit est détecté au boot.

---

## La mise à jour d'Aurora

`make aurora-update` met à jour le package Composer, joue les migrations,
resynchronise les permissions, le jsconfig, le CLAUDE.md, les symlinks de
documentation et de mémoire. La commande est conçue pour être lancée
fréquemment et sans risque — les fichiers propres au client (`src/`, `assets/client/`,
`.env.local`, `Makefile.local`, `CLAUDE.local.md`) ne sont jamais touchés.

La philosophie est qu'**une mise à jour d'Aurora ne doit jamais nécessiter
d'intervention manuelle** dans le code client. Si c'est le cas, c'est soit un
breaking change documenté dans `CHANGELOG.md`, soit un point d'extension
manquant à ajouter dans Aurora.

---

## Le contrat implicite

En échange de la discipline "zéro fork", le projet client bénéficie de :

- **Mises à jour sans douleur** — `make aurora-update` suffit
- **Modules métier gratuits** — chaque nouveau module Aurora est disponible
  immédiatement
- **Infrastructure maintenue** — sécurité, performances, compatibilité PHP/Symfony
  gérées dans Aurora
- **Documentation et mémoire synchronisées** — les docs et les mémoires Claude
  sont distribuées avec le package et toujours à jour
