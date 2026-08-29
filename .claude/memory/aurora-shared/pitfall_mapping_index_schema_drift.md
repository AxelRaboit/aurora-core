---
name: pitfall_mapping_index_schema_drift
description: Un index (ou un type de colonne) créé par une migration mais non déclaré dans le mapping Doctrine est un diff PERMANENT de `schema:update`. Invisible sur une base de dev bruitée - se voit seulement sur un consommateur propre.
metadata:
  type: project
---

## Règle

Tout ce qu'une migration crée doit être **déclaré dans le mapping de l'entité**.
Un index, une contrainte unique, un type de colonne : si la migration le pose et
que l'attribut `#[ORM\Index]` / `#[ORM\UniqueConstraint]` / le `type:` de la
colonne ne le mentionne pas, `doctrine:schema:update --dump-sql` proposera de le
supprimer ou de le recréer **à chaque exécution, pour toujours**.

Ce n'est pas un avertissement passager qui disparaîtra à la prochaine migration.
C'est un écart structurel : Doctrine compare la base au mapping, pas aux
migrations. La migration n'est qu'un moyen d'arriver à l'état ; le mapping est la
définition de l'état.

```php
// La migration fait ceci :
// CREATE INDEX idx_planning_event_series ON core_planning_events (series_id, start_at);

// Donc l'entité doit dire ceci - sinon diff permanent :
#[ORM\Index(name: 'idx_planning_event_series', columns: ['series_id', 'start_at'])]
class PlanningEvent { … }
```

## Pourquoi

**Why:** parce que le symptôme est **invisible là où on travaille**. Une base de
dev accumule du bruit - séquences orphelines, tables de modules archivés, essais
manuels - et `schema:update --dump-sql` y sort trente lignes en permanence. Un
index manquant est une ligne de plus dans ce bruit : personne ne la lit.

Elle devient visible sur une base **propre**, c'est-à-dire chez un consommateur
qui vient de faire `make aurora-update` (voir
[[process_check_aurora_client_sync]]). Là, `schema:update` doit sortir **zéro
ligne**, et tout ce qui sort est un vrai écart.

Ce piège s'est produit **deux fois dans la même session** sur le module Planning
- `uniq_planning_source`, puis `idx_planning_event_series` - et les deux fois il
a fallu aller le chercher sur aurora-client pour le voir. La deuxième fois après
avoir déjà appris la leçon la première : c'est ce qui justifie une mémoire.

À ne pas confondre avec [[pattern_migration_drift_detection]], qui détecte des
migrations **non exécutées** sur la base de dev. Ici les migrations sont passées ;
c'est le mapping qui est incomplet.

## Comment l'appliquer

### Après avoir écrit une migration à la main

```bash
# Sur la base de dev : bruyant, mais on cherche ses propres tables dedans.
php bin/console doctrine:schema:update --dump-sql | grep -i '<sa_table>'
```

### Avant de considérer un chantier terminé - le vrai contrôle

```bash
cd "$AURORA_CLIENT_DIR"
make aurora-update
php bin/console doctrine:schema:update --dump-sql
# Attendu, littéralement : "Nothing to update"
```

Une seule ligne qui sort ici est un écart à corriger, pas à noter pour plus tard.

### Ce qui est déjà tombé dans le panneau

| Écart | Cause |
|---|---|
| `uniq_planning_source` | `UniqueConstraint` créée en migration, absente de l'entité |
| `idx_planning_event_series` | index de recherche créé en migration, absent de l'entité |
| `exdates` en `TEXT` vs `json` | migration en `TEXT`, mapping en `type: Types::JSON` |
| index partiel (`WHERE …`) | Doctrine ne sait pas les déclarer → ne jamais en créer en migration |

Le dernier cas est une contrainte de plus : un index **partiel** n'est pas
exprimable dans le mapping Doctrine. Donc on ne l'écrit pas en migration, ou on
accepte un diff permanent - et on ne l'accepte pas.
