---
name: Studio - espaces clients
description: Le sous-module CustomerSpace, la règle du docblock Studio amendée, le pari "une entité, deux vues", la coquille /workspace et la boucle de validation client
type: project
---

## Règle

Un **espace client** (`CustomerSpace`, sous `src/Module/Studio/CustomerSpace/`)
est le contenant du travail mené pour un client. **Plusieurs espaces peuvent
nommer le même client** : `ManyToOne`, pas `OneToOne`. Un client avec deux
marques fait tourner deux calendriers.

Son contenu vit dans `SpaceContent/`, son accès client dans `SpaceAccess/`.

## Les trois décisions qui structurent tout

**1. Studio tient aussi la surface de livraison.** Le docblock de `StudioModule`
disait « what is sold and what is delivered. Not the tools ». Un espace n'est ni
vendu ni livré : c'est la surface sur laquelle on livre. La règle est amendée
dans le docblock même, en disant pourquoi. La frontière qu'elle refuse ne bouge
pas : un outil appartient à son module, donc l'espace annonce ses dates à
Planning au lieu de dessiner un calendrier.

**2. Une entité, deux vues.** `SpaceContentItem` porte une étape (la colonne) et
une date nullable (le créneau). Le tableau et le calendrier ne possèdent rien.
Une idée sans date vit sur le tableau et rejoint le mois quand on la programme.

**3. La réponse du client est un avis, pas une machine à états.** Valider ne
déplace pas la carte. Un client qui clique par erreur aurait sinon programmé une
publication.

## La coquille `/workspace`, et le piège qu'elle contient

Un espace s'ouvre dans son propre gabarit, sans menu latéral, à une adresse hors
`/backend` - une coquille autonome à une adresse d'administration reste une page
d'administration pour qui lit la barre du navigateur.

**Ce qu'il ne faut pas perdre en sortant de `/backend` :**

- le **pare-feu** `admin` est un motif de chemin. `security.yaml` porte donc
  `^/(backend|dev|workspace)`, sinon aucune identité backend n'est restaurée et
  un membre de l'équipe arrive en visiteur anonyme, sans erreur ;
- la règle **`access_control`**. `/backend` a `ROLE_USER` ; `/workspace` est
  sorti sans, et le fourre-tout `^/` l'aurait rendu public le jour où une action
  y arrive sans `#[IsGranted]` ;
- les **globales JS**. Le layout backend pose `window.__isAdmin__`,
  `__isDev__`, `__privileges__`. La coquille ne les posait pas, donc
  `usePrivileges()` répondait « non » à tout et un administrateur voyait un
  tableau sans aucun bouton, en silence. Elles sont dans
  `@Shared/components/backend_globals.html.twig`, incluses par les deux.

`AdminFirewallCoversStaffRoutesTest` vérifie les deux premiers points pour les
trois préfixes réservés à l'équipe. C'est le genre d'erreur qu'une suite verte
ne voit pas : le client de test s'authentifie lui-même.

`security.yaml` est **écrasé chez les clients** par `make sync-security`, donc
le motif se propage tout seul - à condition de citer la cible dans « Dans
aurora-client ».

## L'accès client

Schéma des contrats repris tel quel : sélecteur public + SHA-256 du secret,
expiration obligatoire, révocation. L'adresse n'existe en clair que dans la
réponse qui la crée.

**Deux droits distincts sur le lien**, `canComment` et `canApprove`, chacun
arrivé **avec** l'écriture qu'il gouverne. Une colonne représentant une
permission que personne n'applique est un interrupteur qui ne fait rien
(cf. [[project_planning_share_link_write_access]]). La route est limitée en débit
par `space_guest_write`, **déclaré dans les deux dépôts**
(cf. [[pitfall_rate_limiter_client_config]]).

## Verdict et mots : deux durées de vie

Un verdict est un **état** et se réinitialise quand le titre ou le texte change -
une validation porte sur une formulation. Des mots sont des **événements** et se
gardent.

C'est un défaut vécu : les mots vivaient d'abord dans `approval_note`, sur le
verdict, donc la phrase du client disparaissait exactement pendant que le studio
l'appliquait. Ils sont maintenant des `SpaceContentComment`, un fil partagé où
chaque côté lit l'autre.

**L'auteur d'un message est stocké deux fois.** Les relations (`author_user_id`,
`author_link_id`) sont en `SET NULL` parce que retirer une personne ne doit pas
retirer ce qu'elle a dit ; `author_label` et `from_client` sont écrits une fois à
la publication, parce qu'un message dont l'auteur est devenu nul est un message
que personne n'a écrit.

Un message du client ne se supprime pas.

## Pièges rencontrés, tous vérifiés

- **`doctrine:migrations:diff` est inutilisable ici.** La base de dev garde les
  tables des modules retirés, et le diff propose de toutes les supprimer.
  Générer, extraire les seules lignes utiles, écrire la migration à la main.
- **fr et en ne rangent pas leurs clés dans le même ordre.** Ancrer une insertion
  sur une ligne dont les voisines diffèrent orpheline une clé.
  `TranslationConsistencyTest` l'attrape.
- **L'espagnol porte tout ce qu'un client lit.**
  `CustomerFacingLocaleTest` échoue sinon. Vaut pour `studio.public.*` **et**
  pour toute clé de Core rendue sur une page publique.
- **Une clé sous `backend.` sur une page client** est un namespace qui ne veut
  plus rien dire : les mots d'un composant partagé vont dans `shared.*`.
- **`AuditActionLabelTest`** échoue dès qu'un Manager émet une action sans
  libellé, dans les deux langues.
- **`status:` ne peut pas être un libellé et un bloc** dans le même YAML.
- **Un `RESTRICT` entre deux enfants d'un même parent** casse la suppression du
  parent : la cascade atteint les deux sans ordre garanti. La règle « on ne
  supprime pas une colonne pleine » vit donc dans le Manager.
- **Un champ de date natif** prend son format du système, pas de l'application.
  `AppDatePicker` avec `enable-time` rend le `Y-m-d\TH:i` attendu.

## État au 15/09/2026

Livré, non poussé, sept versions locales de 0.9.176 à 0.9.182 : l'espace et son
équipe, le tableau dans sa coquille, le calendrier et la remontée des dates vers
Planning, l'accès client en lecture, la validation, le fil d'échanges, la couleur
d'étape.

Reste : les pages de documentation et leurs captures (aucune pour l'instant),
l'onglet Fichiers adossé à la GED, puis le miroir Google Drive.

## Liens

- [[architecture_module_parameter_enum]] - corrigée pendant le lot 1 : elle
  décrivait des enums par module qui n'existent plus.
- [[project_planning_share_link_write_access]] - les deux prérequis à une
  écriture invitée, tenus ici.
- [[pitfall_rate_limiter_client_config]] - un limiteur se déclare dans les deux
  dépôts, sinon le conteneur client ne se construit plus.
