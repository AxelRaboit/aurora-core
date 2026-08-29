---
name: convention-i18n-plurals
description: vue-i18n utilise la syntaxe pipe pour les pluriels (`'1 X | {count} Xs'`) - la syntaxe ICU `{count, plural, ...}` ne compile PAS
metadata:
  type: feedback
---

# Pluriels i18n - toujours utiliser la syntaxe pipe vue-i18n native

Le compilateur de messages vue-i18n par défaut (celui qu'Aurora utilise)
**ne supporte pas la syntaxe ICU plural**. Écrire `{count, plural, one
{…} other {# …}}` lève à l'exécution :

```
SyntaxError: Message compilation error: Invalid token in placeholder: 'count,'
```

## Comment l'appliquer

**Format du message** (YAML) :

```yaml
# ✅ Bon - pipe-separated
note_count: '1 note | {count} notes'
delete_confirm: 'Retirer "{tag}" de 1 note ? | Retirer "{tag}" de {count} notes ?'
rename_success: 'Aucune note mise à jour. | 1 note mise à jour. | {count} notes mises à jour.'
applied: '1 ligne appliquée au mois | {count} lignes appliquées au mois'

# ❌ Mauvais - ICU plural, ne compile pas
applied: '{count, plural, one {1 ligne appliquée au mois} other {# lignes appliquées au mois}}'
```

Les arms sont **pipe-séparées**, dans l'ordre :
1. zéro/un (si 2 arms : `one | other`, si 3 arms : `zero | one | other`)
2. La substitution se fait avec `{count}` (pas `#`)

**Appel JS** : passer le `count` en **3e argument** de `t()` (pas
seulement comme paramètre nommé) - c'est ce 3e arg qui sélectionne
l'arm :

```js
// ✅ Bon
t('personal_finance.budget_presets.applied', { count: n }, n)

// ❌ Mauvais - la sélection plural ne se déclenchera pas
t('personal_finance.budget_presets.applied', { count: n })
```

Quand on appelle avec 2 args, vue-i18n traite la clé comme un message
simple → l'arm "one" est rendue brute, sans selection.

## Pourquoi

Aurora utilise vue-i18n avec son compilateur par défaut (pas
`@intlify/message-compiler` en mode ICU). Le format pipe est la
convention native vue-i18n depuis sa v6, c'est ce que tout le module
Notes / Editorial utilise.

Le piège vient du fait que la syntaxe ICU **ressemble à un message
valide** côté YAML (lint OK, dump JSON OK, build Vite OK) - l'erreur
n'apparaît qu'au runtime quand `t()` est appelé sur la clé.

## Test garde-fou

`tests/Unit/Translation/TranslationConsistencyTest::testPlaceholderConsistency`
ne détecte pas le format ICU vs pipe (sa regex `\{([^}]+)\}` capture
juste le contenu des `{…}` et compare). Si on remarque qu'elle échoue
avec des diffs entre arms plural, le vrai problème est probablement
une clé en format ICU qui devrait être en pipe.

## Mémoires liées

- [[convention_i18n_source_files]] - où vivent les YAML PF
- [[convention_i18n_key_casing]] - naming des clés
