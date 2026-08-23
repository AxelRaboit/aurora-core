# Philosophie d'Aurora Core

## Ce qu'est Aurora Core

Aurora Core est un **bundle Symfony distribué** (`axelraboit/aurora`) qui fournit
une plateforme d'application métier complète : modules Editorial, CRM, ERP,
Ecommerce, Billing, Photo, GED, Planning, Project - le tout sur une infrastructure
partagée (authentification, media, audit, séquences, thème, i18n).

L'objectif n'est pas de fournir une application finie. C'est de fournir une
**base solide, extensible et non-forking** que des projets clients peuvent
consommer et personnaliser sans jamais toucher au code du bundle.

---

## Le principe fondateur : zéro fork

Le problème classique d'un framework ou d'un CMS "tout-en-un" : dès qu'un
client veut personnaliser quelque chose, il doit modifier le code source. À la
prochaine mise à jour, il doit réconcilier ses modifications avec les changements
upstream. C'est ingérable à l'échelle.

Aurora résout ce problème en s'inspirant de **Sylius** : chaque entité, chaque
service, chaque composant Vue expose des **points d'extension typés** que le
client peut brancher sans toucher à une seule ligne de vendor.

> **Règle d'or** : un projet client ne modifie jamais `vendor/axelraboit/aurora/`.
> Toute personnalisation passe par les points d'extension. Mettre à jour Aurora
> est alors un simple `composer update`.

---

## L'architecture en couches

Aurora est structuré en deux niveaux :

```
src/Core/      - Infrastructure partagée, indépendante de tout module métier
src/Module/    - Modules métier autonomes (Editorial, CRM, ERP, …)
```

**Core** contient tout ce qui pourrait exister dans un bundle Symfony générique :
utilisateurs, authentification, media, séquences, thème, menus, audit,
internationalisation. Un module ne doit jamais dépendre d'un autre module - il
ne dépend que de Core.

**Les modules** sont des domaines métier autonomes. Chacun implémente
`ModuleInterface` pour déclarer sa navigation et ses permissions, et peut
exposer une partie frontend publique via `FrontendInterface`. La dépendance entre
modules est unidirectionnelle et documentée (Ecommerce → Erp → Core, jamais
l'inverse).

---

## Le pattern d'extensibilité en 5 couches

Chaque entité Aurora avec une page backend CRUD suit un pattern en 5 couches.
Ce n'est pas une convention arbitraire - chaque couche répond à un besoin
d'extension précis.

### Couche 1 - Entité (`Interface + Abstract + Concrete`)

Un client qui veut ajouter une colonne `code` à `Agency` ne peut pas modifier
la table Aurora. Il doit substituer l'entité par la sienne. Pour que ça
fonctionne avec Doctrine (relations, repositories), Aurora expose :

- `AgencyInterface` - le contrat public (getters/setters) utilisé partout dans Aurora
- `AbstractAgency` - MappedSuperclass avec toutes les colonnes sauf l'id
- `Agency` - entité concrète avec son id + sa séquence

Le client étend `AbstractAgency`, déclare sa propre table, et déclare la
substitution via `resolve_target_entities`. Aurora n'a aucun `new Agency()`
direct - il passe par `$this->createAgency()`.

### Couche 2 - DTO + Factory

Le controller Aurora reçoit un `AgencyInputInterface` qu'il reconstruit via
`AgencyInputFactoryInterface`. Le client décore la factory avec `#[AsAlias]`
pour que sa propre factory soit injectée à la place. Le DTO client étend le DTO
Aurora et ajoute ses champs.

Sans cette couche, le client ne peut pas ajouter un champ au formulaire sans
forker le controller.

### Couche 3 - Manager

Le Manager orchestre le cycle de vie de l'entité (persist, flush, audit). Il
expose des hooks `protected` :

- `createAgency()` - le client retourne sa propre classe
- `applyInput()` - le client appelle `parent::applyInput()` puis hydrate ses champs
- `auditPayload()` - le client splat-merge les champs supplémentaires dans le log

Sans ces hooks, le client doit copier toute la logique de persist + audit pour
ajouter un seul setter.

### Couche 4 - Serializer

Le Serializer produit le payload JSON consommé par les composants Vue. Le client
étend le Serializer Aurora et override `serialize()` pour ajouter ses champs au
tableau retourné par `parent::serialize()`.

### Couche 5 - Vue (slots + extraFields)

Le composant Vue Aurora expose des **slots scoped** (`extra-headers`,
`extra-cells`, `extra-form-fields`) et une prop `extraFields`. Le client passe
ses colonnes et ses champs de formulaire via ces slots sans modifier le composant
Aurora.

---

## Le système de modules

Chaque module est une unité autonome qui :

1. Implémente `ModuleInterface` → déclare ses entrées de navigation et ses permissions
2. Est taggé `aurora.module` dans `services.yaml`
3. Possède ses propres entités, managers, controllers, templates, assets Vue et traductions

Le `ModuleRegistry` collecte tous les modules taggés et les intègre dans la
sidemenu admin, le système de permissions et le routing. Ajouter un module ne
nécessite aucune modification du code Aurora - juste le tag.

### Les permissions

Aurora utilise trois rôles plats (`ROLE_DEV`, `ROLE_ADMIN`, `ROLE_USER`) et un
système de **privileges** fins sous forme de chaînes (`crm.contacts.view`).
`ROLE_DEV` bypass tout, `ROLE_ADMIN` a tout, `ROLE_USER` n'a que ce qui lui
est explicitement accordé.

Chaque module déclare ses permissions via `NavPermission`. Le `ModulePermissionVoter`
résout `#[IsGranted('crm.contacts.view')]` dynamiquement, sans configuration
supplémentaire.

---

## Les séquences

Aurora utilise PostgreSQL `SEQUENCE` pour tous les PKs et les références métier
(ex: `FAC-2026-0001`). Toutes les séquences Aurora sont préfixées `seq_core_*`
pour éviter les collisions avec des entités client homonymes. Les préfixes de
références sont configurables dans l'admin et définis dans `SequencePrefixEnum`.

Si un client réutilise un préfixe Aurora, une `LogicException` est levée au boot,
le conflit est détecté immédiatement.

---

## Ce qu'Aurora Core n'est pas

- **Pas un CMS clé-en-main** : il ne s'installe pas seul et ne tourne pas sans
  un projet client hôte.
- **Pas un micro-framework** : il embarque des modules métier complets, pas
  seulement de l'infrastructure.
- **Pas un monolithe figé** : chaque entité, service et composant est extensible
  sans fork.
- **Pas opinionated sur le métier du client** : Aurora fournit les briques,
  le client assemble selon son domaine.
