# Convention d'extensibilité - résumé exécutif

## Règle

Toute entité Aurora avec une page backend CRUD doit suivre le pattern Sylius en
5 couches, pour qu'un client puisse étendre **sans forker** aurora-core.

## Pourquoi

Sans ce pattern, un client qui veut ajouter `code` à `Agency` doit dupliquer
6 fichiers (entity, DTO, Manager, Serializer, Vue, Twig). À chaque mise à
jour d'Aurora il doit réconcilier ses copies. Avec ce pattern, le client
écrit uniquement le **delta** (les setters de son champ, l'appel
`parent::applyInput()`, le slot Vue qui ajoute son input).

## Comment l'appliquer

Les 5 couches :

1. **Entity** : `<Name>Interface` + `Abstract<Name>` (MappedSuperclass) +
   concrete `<Name>` non-`final`. Sequence `seq_core_<entity>_id`. Inscrire
   dans `AuroraBundle::$resolve_target_entities`.

2. **DTO** : `<Name>InputInterface` + `<Name>InputFactoryInterface` +
   `<Name>InputFactory` (`#[AsAlias]`) + `<Name>Input` non-`final`,
   `class { public readonly … }` (PAS `readonly class`).

3. **Manager** : `<Name>ManagerInterface` dans `Manager/` (jamais
   `Contract/` pour les Managers instrumentés). `<Name>Manager` non-`final`,
   props `protected readonly`, `#[AsAlias]`. Trois familles de hooks :
   instanciation (`create<X>()`), hydratation (`applyInput()`), audit
   (`auditCreated/Updated/Deleted` + `auditPayload`).

4. **Serializer** : `<Name>SerializerInterface` + `<Name>Serializer`
   non-`final` + `#[AsAlias]`.

5. **Vue** : `<Plural>App.vue` avec props `extraFields` + slots
   `extra-headers`/`extra-cells`/`extra-form-fields` ; composable
   `useXxxForm.js` unifié create+edit avec option `extraFields`.

**Doc canonique** : [`docs/aurora-core/dev/entity_extensibility_convention.md`](../../docs/aurora-core/dev/entity_extensibility_convention.md).

## Variantes acceptées

1. **Manager à hooks multiples** (User, Menu pré-DTO, Order, Billing) - pas
   d'`applyInput`, méthodes spécialisées customisables individuellement.
2. **Composables Vue séparés** `useXxxCreate` + `useXxxEdit` (User
   invite/edit, Theme) - quand les forms n'ont rien en commun.
3. **Editor full-page** (Post) - au lieu de modal, slot placé sémantiquement
   près d'un panel proche par fonction.

Ce sont des **vraies contraintes structurelles**, pas des écarts à la
discrétion. Toute nouvelle variante doit être discutée avant d'être ajoutée.

## État

✅ Rollout terminé sur 24 entités (+ Planning/PlanningEvent). Doc tracker supprimé.
