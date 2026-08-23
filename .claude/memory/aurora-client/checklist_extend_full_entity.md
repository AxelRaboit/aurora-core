# Checklist : étendre une entité Aurora de bout en bout

Pour ajouter un champ `code` sur `Agency` (ou n'importe quelle entité),
voici les étapes ordonnées. Chaque étape pointe vers le pattern détaillé.

## 1. Entité (Couche 1)

→ [pattern_extend_entity.md](pattern_extend_entity.md)

- [ ] Créer `App\Module\<Mirror>\<Name>\Entity\<Name>` qui étend
      `Aurora\…\Abstract<Name>` et `implements <Name>Interface`.
      Le chemin miroir reprend le namespace Aurora :
      `Aurora\Module\Platform\Agency` → `src/Module/Platform/Agency/Entity/`.
- [ ] Ajouter colonnes Doctrine + getters/setters pour les champs custom.
- [ ] Sequence client : `seq_app_<entity>_id` (préfixe `app_` pour éviter
      collision avec `seq_core_*` Aurora).
- [ ] Inscrire dans `config/packages/doctrine.yaml` →
      `resolve_target_entities`.
- [ ] Si finder methods custom : créer `App\Module\<Mirror>\<Name>\Repository\App<Name>Repository`
      qui étend Aurora repo, déclarer `repositoryClass` dans l'entité
      → [pattern_extend_repository.md](pattern_extend_repository.md).
- [ ] Migration Doctrine : `php bin/console doctrine:migrations:diff` +
      relire la migration générée.

## 2. DTO Input (Couche 2)

→ [pattern_extend_dto.md](pattern_extend_dto.md)

- [ ] Étendre `Aurora\…\<Name>Input` avec les nouveaux champs `public
      readonly`.
- [ ] Étendre `Aurora\…\<Name>InputFactory`, override `fromArray()`.
- [ ] `#[AsAlias(<Name>InputFactoryInterface::class)]` sur la factory
      étendue.

## 3. Manager (Couche 3)

→ [pattern_extend_manager.md](pattern_extend_manager.md)

- [ ] Étendre `Aurora\…\<Name>Manager`, `#[AsAlias(<Name>ManagerInterface::class)]`.
- [ ] **Override `create<X>()`** pour retourner `new App\Module\<Mirror>\<X>\Entity\<X>()`
      (sinon les champs custom sont perdus -
      [pitfall_create_hook_required.md](pitfall_create_hook_required.md)).
- [ ] Override `applyInput()` avec `parent::applyInput()` AVANT (sinon
      les champs Aurora ne sont pas hydratés -
      [pitfall_call_parent_apply_input.md](pitfall_call_parent_apply_input.md)).
- [ ] Si tu veux que `code` apparaisse dans les audit logs : override
      `auditPayload()` avec `[...parent::auditPayload($entity), 'code' =>
      $entity->getCode()]`.
- [ ] Variante User-style (Manager à hooks multiples - User, Order,
      Invoice, Tiers, OcrJob, Comment) : pas d'`applyInput()`. Override les
      méthodes publiques métier directement.

## 4. Serializer (Couche 4)

→ [pattern_extend_serializer.md](pattern_extend_serializer.md)

- [ ] Étendre `Aurora\…\<Name>Serializer`, `#[AsAlias(<Name>SerializerInterface::class)]`.
- [ ] Override `serialize()` avec spread `[...parent::serialize($entity),
      'code' => $entity->getCode()]`.

## 5. Vue + Twig (Couche 5)

→ [pattern_extend_vue.md](pattern_extend_vue.md) + [pattern_override_twig.md](pattern_override_twig.md)

- [ ] Créer wrapper Vue `App<Plural>App.vue` qui consomme le composant
      Aurora avec prop `extraFields` :
      ```js
      const extraFields = {
          code: { default: '', fromEntity: (entity) => entity.code ?? '' },
      };
      ```
- [ ] Utiliser les slots `extra-headers` / `extra-cells` /
      `extra-form-fields` pour injecter le champ.
- [ ] Override Twig admin (si nécessaire) pour pointer le mount Vue vers
      le wrapper client : `templates/<Module>/backend/<plural>/index.html.twig`.

## 6. Validation finale

- [ ] `php bin/console doctrine:schema:validate` → schéma cohérent.
- [ ] `php bin/console debug:container | grep <Name>Manager` → l'alias
      pointe vers la classe étendue client.
- [ ] Test manuel admin : créer / éditer une instance, vérifier que le
      `code` est sauvegardé.
- [ ] Test unitaire Manager : `testCreateHydratesAuroraFieldsAndCustomFields`.
- [ ] `php bin/phpunit` → tous les tests passent.

## Cas spéciaux

### Variante "Manager à hooks multiples"

Pour User, Order, Invoice, Tiers, OcrJob, Comment : l'étape 3 saute
`applyInput`. Override `create<X>()` et les méthodes publiques métier
spécifiques que tu veux customiser (avec `parent::xxx()` AVANT).

### Cascade child sans page admin propre

Si tu étends une entité cascade (ex: `OrderLine` géré par `OrderManager`),
tu n'as pas de Vue / DTO / Manager dédié à ajouter - juste l'entité
(étape 1) et override `createOrderLine()` dans ton `App\Module\Ecommerce\Order\Manager\OrderManager`
étendu.

### Tu veux ajouter un nouveau finder, pas étendre

Va directement à [pattern_extend_repository.md](pattern_extend_repository.md).
Pas besoin d'étendre l'entité.
