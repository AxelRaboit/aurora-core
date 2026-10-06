---
name: Piège - `migrations:execute --write-sql` exécute aussi la migration
description: Pour lire le SQL d'une migration sans la jouer, `--dry-run` ; `execute --up --write-sql` a joué la migration sur aurora_dev en écrivant le fichier
type: feedback
---

## Règle

Pour relire le SQL qu'une migration va jouer, **jamais**
`doctrine:migrations:execute '<Version>' --up --write-sql=<fichier>`. Le
6/10/2026, cette commande a écrit le fichier **et** exécuté la migration sur
`aurora_dev` (version enregistrée dans `doctrine_migration_versions`, tables
supprimées), alors que la documentation de Doctrine dit que l'option empêche
l'exécution.

## Pourquoi

C'était la migration irréversible qui fusionne les présentations dans les
livrables (`Version20261006140000`, `down()` lève une exception) : les
comptes « avant » n'avaient pas encore été relevés. Rien n'a été perdu parce
que la migration était juste et que les comptes avaient été pris plus tôt,
mais une migration fausse aurait laissé une base à reconstruire.

## Comment l'appliquer

- Relire le SQL : `php bin/console doctrine:migrations:migrate --dry-run`
  (ou lire les `addSql` dans le fichier), jamais `execute --write-sql`.
- Avant une migration de données, relever les comptes **avant** toute commande
  de migration, et prouver la base par `current_database()`
  (cf. la mémoire user « Jamais de drop sur une base calculée »).
- Tester une requête risquée sur des tables `TEMP` du même nom dans une
  transaction `BEGIN … ROLLBACK` via `psql` : `pg_temp` passe avant `public`,
  la vraie table n'est pas touchée.
