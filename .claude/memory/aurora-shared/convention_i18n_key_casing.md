---
name: convention_i18n_key_casing
description: Toutes les clés de traduction (YAML + références code) sont en snake_case, sans exception. Le camelCase est interdit et casse en silence (vue-i18n/Symfony font un lookup exact, aucune erreur, clé brute affichée).
metadata:
  type: feedback
---

## Règle

**Toutes les clés de traduction sont en `snake_case`, sans exception** - qu'elles
soient construites par le code (enums, ids système) ou nommées manuellement pour
l'UI. Le `camelCase` dans une clé i18n est **interdit**. C'est la convention
d'identifiant interne du projet (CLAUDE.md §4 : route, setting, colonne DB,
i18n → `snake_case` ; cf. [[convention_naming]]).

> ⚠️ Cette mémoire **annule** une ancienne version qui prétendait que le
> `camelCase` pour les libellés UI nommés à la main était intentionnel. C'était
> faux : ces clés camelCase étaient cassées en silence (voir piège).

## Pourquoi

- Les clés construites par le code le sont à partir de valeurs d'enum
  (toujours lowercase/snake) → `'backend.pdfform.templates.status_'.$value`
  donne `status_draft`. Naturellement snake.
- Un préfixe dynamique (`sprintf('backend.menus.target_types.%s', $value)`) ou
  une concaténation doit avoir son **segment fixe en snake_case** lui aussi
  (`target_types`, pas `targetTypes` ; `field_type`, pas `fieldType`).
- **Piège (cassé en silence)** : `src/Core/assets/i18n.js` utilise vue-i18n
  **vanilla, sans `messageResolver`** ; le translator Symfony fait pareil. Lookup
  **exact** : une référence camelCase qui ne matche pas le YAML snake_case ne
  lève aucune erreur - elle affiche la clé brute dans l'UI. Une dérive passe
  donc inaperçue jusqu'à ce qu'on regarde l'écran concerné.

## Comment l'appliquer

- YAML : tout segment de clé en `snake_case` (`search_placeholder`,
  `delete_confirm`, `field_count`, `not_numeric`, `unknown_field`).
- Builders dynamiques d'enum (`getLabelKey()`, `labelKey()`) : le préfixe
  littéral en snake_case.
- **Audit des dérives** :
  - YAML : grep des segments de clé matchant `[a-z0-9][A-Z]`.
  - Code (`src/`, `templates/`, `tests/`) : grep des littéraux `'backend…'` /
    `'frontend…'` (clés complètes **et** préfixes sprintf/concat) contenant
    `[a-z0-9][A-Z]`. Exclure les faux positifs non-i18n qui commencent par
    `nav`/`mail`/`email` mais sont des vars JS / champs d'entité (`navFilter`,
    `mailpitUrl`, `navSectionColors`, `emailVerificationToken`).
  - Vérifier que chaque clé référencée résout bien à une clé YAML existante.

## Référence

Doc complète : `docs/aurora-shared/translations.md` § "Casse des clés - toujours snake_case".
