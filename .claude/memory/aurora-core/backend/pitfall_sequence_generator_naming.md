# Piège : confusion entre SequenceGenerator et séquences Doctrine

## Règle

`SequenceGenerator` utilise une **table Doctrine** (`app_sequence_counters`), pas des séquences PostgreSQL. Les seules séquences PostgreSQL dans Aurora sont les séquences de PK d'entités (`seq_core_*_id`).

| Famille | Mécanisme | Exemple | Propriétaire |
|---|---|---|---|
| PKs entités Doctrine | Séquence PostgreSQL `seq_core_<entity>_id` | `seq_core_invoice_id` | Doctrine migrations |
| Références métier | Table `app_sequence_counters` | ligne `(prefix='FAC', year=2026)` | `SequenceGenerator` |

Schéma de la table : `app_sequence_counters(prefix VARCHAR(30), year INT, last_value INT)` avec PK composite `(prefix, year)`.

- Séquence **globale** : `year = 0` → `SequenceGenerator::next('LOG')` → `LOG-000032`
- Séquence **annuelle** : `year = YYYY` → `SequenceGenerator::nextYearly('FAC', 2026)` → `FAC-2026-0001`

L'incrément est atomique via un upsert PostgreSQL : `INSERT … ON CONFLICT DO UPDATE RETURNING`.

## Pourquoi

Avant mai 2026, `SequenceGenerator` créait des séquences PostgreSQL nommées `app_seq_<prefix>` ou `app_seq_<prefix>_<year>`. Doctrine générait des `DROP SEQUENCE` spurieux dans les diffs de migration, et la synchronisation après imports nécessitait une commande `findSafeStart`. La migration vers une table permet un contrôle total par Doctrine migrations, un `schema:validate` propre, et supprime le besoin de `schema_filter` dans `doctrine.yaml`.

## Comment l'appliquer

- **Ajouter un nouveau préfixe métier** : aucune action nécessaire - la ligne dans `app_sequence_counters` est créée automatiquement au premier appel de `next()` ou `nextYearly()`.
- **Inspecter les valeurs courantes** : `SELECT * FROM app_sequence_counters ORDER BY prefix, year;`
- **Après data imports / fixtures** : réinitialiser les PK sequences via `make sync-sequences` (cible uniquement les `seq_core_*_id`, sans rapport avec `app_sequence_counters`).
- **Pas de `schema_filter`** dans `doctrine.yaml` - supprimé, plus nécessaire.

## Ce qu'il ne faut pas faire

- Ne pas chercher `app_seq_*` dans la base - ces séquences n'existent plus.
- Ne pas ajouter `schema_filter` pour exclure des séquences métier - la table est gérée nativement par Doctrine.
- Ne pas appeler `findSafeStart` - méthode supprimée.

## Source

Piège initial détecté le 9 mai 2026 (`app_seq_*` + `schema_filter`). Refactorisé vers `app_sequence_counters` lors du passage à une gestion 100 % Doctrine.
