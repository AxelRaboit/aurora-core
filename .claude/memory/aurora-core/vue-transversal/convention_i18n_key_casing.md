---
name: Convention casse des clés de traduction (snake_case, jamais camelCase)
description: Toutes les clés de traduction (YAML + références code) sont en snake_case, sans exception. Le camelCase est interdit et casse en silence (vue-i18n/Symfony font un lookup exact, aucune erreur, clé brute affichée).
type: feedback
---

## Règle

**Toutes les clés de traduction sont en `snake_case`, sans exception** - qu'elles
soient construites par le code (enums, ids système) ou nommées manuellement pour
l'UI. Le `camelCase` dans une clé i18n est **interdit** (CLAUDE.md §4).

> ⚠️ Cette mémoire **annule** une ancienne version qui prétendait que le
> `camelCase` pour les libellés UI était intentionnel. C'était faux : ces clés
> camelCase étaient cassées en silence (voir piège).

## Pourquoi

- Clés construites par le code = à partir de valeurs d'enum (lowercase/snake) →
  `'backend.pdfform.templates.status_'.$value` = `status_draft`. Naturellement snake.
- Préfixe dynamique (`sprintf('backend.menus.target_types.%s', $value)`) ou
  concaténation : le **segment fixe doit aussi être snake_case** (`target_types`,
  pas `targetTypes` ; `field_type`, pas `fieldType`).
- **Piège (cassé en silence)** : `src/Core/assets/i18n.js` = vue-i18n vanilla
  **sans `messageResolver`** ; Symfony translator idem. Lookup **exact** : une
  réf camelCase non-matchante n'erre pas - elle affiche la clé brute dans l'UI.
  La dérive passe inaperçue jusqu'à ce qu'on regarde l'écran.

## Comment l'appliquer

- YAML : tout segment de clé en `snake_case`.
- Builders dynamiques d'enum (`getLabelKey()`, `labelKey()`) : préfixe littéral snake.
- **Audit** :
  - YAML : grep segments matchant `[a-z0-9][A-Z]`.
  - Code (`src/`, `templates/`, `tests/`) : grep littéraux `'backend…'`/`'frontend…'`
    (clés complètes + préfixes sprintf/concat) avec `[a-z0-9][A-Z]`. Exclure les
    faux positifs non-i18n commençant par `nav`/`mail`/`email` mais qui sont des
    vars JS / champs d'entité (`navFilter`, `mailpitUrl`, `navSectionColors`).

## Référence

Doc : `docs/aurora-shared/translations.md` § "Casse des clés - toujours snake_case".
Mémoire shared jumelle : `aurora-shared/convention_i18n_key_casing.md`.
