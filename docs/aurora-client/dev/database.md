# Base de données - Migrations, fixtures, séquences

## Migrations

Aurora-client gère ses propres migrations Doctrine dans `migrations/`.
Les migrations Aurora-core sont jouées séparément (elles vivent dans
`vendor/axelraboit/aurora/migrations/`).

### Flux standard

```bash
# 1. Modifier une entité (ajouter/supprimer un champ, une relation…)

# 2. Générer la migration
make migration          # php bin/console doctrine:migrations:diff
# → crée migrations/VersionYYYYMMDDHHMMSS.php

# 3. Vérifier la migration générée avant de l'appliquer
# (toujours relire - Doctrine peut générer des ALTER TABLE non désirés)

# 4. Appliquer
make migrate            # php bin/console doctrine:migrations:migrate

# 5. Valider
make schema-validate    # doctrine:schema:validate
```

### Rollback

```bash
make migrate-prev       # rejoue la migration précédente (DOWN)
```

### Migration vide (scripts SQL manuels)

```bash
make migration-generate # crée une migration vide - à remplir à la main
```

Utile pour : copier des données, renommer une colonne, initialiser une séquence.

---

## Migrations Aurora vs Client

Aurora-core inclut ses propres migrations dans `vendor/axelraboit/aurora/migrations/`.
Elles sont configurées séparément dans `config/packages/doctrine_migrations.yaml` :

```yaml
doctrine_migrations:
    migrations_paths:
        'DoctrineMigrations': '%kernel.project_dir%/migrations'
        'AuroraMigrations': '%kernel.project_dir%/vendor/axelraboit/aurora/migrations'
    connection: default
```

`make migrate` joue les deux ensembles dans l'ordre. Il ne faut **jamais**
modifier les migrations Aurora sous `vendor/`.

### ⚠️ DB fresh : `make migrate` ne marche pas, utiliser `make install-dev` (ou `make fixtures`)

**Symptôme** : sur une DB *vierge* (fresh dev clone, CI runner, nouveau
poste), `make migrate-f` plante au cours de la chaîne avec un message du
genre `relation "core_<table>" does not exist` ou `index "idx_xxx" does
not exist`.

**Cause** : Doctrine Migrations 3.x ne mélange **pas** strictement par
version timestamp à travers les namespaces. Il traite les namespaces
dans leur ordre de déclaration. Quand une `ClientMigrations\Version20260508123924`
(extension d'une table Aurora) s'exécute avant
`DoctrineMigrations\Version20260508122957` (création de la table -
version timestamp plus petite, devrait passer avant), ça plante.

**Solution** : utiliser **`make install-dev`** (full reset depuis
zéro) ou **`make fixtures`** (drop DB + reload data). Les deux
contournent le problème en interne via `schema:create + version
--add --all` au lieu de `migrations:migrate`.

```bash
make install-dev   # full reset : composer + pnpm + drop DB + schema:create + fixtures + Vite
# ou
make fixtures      # drop DB + schema:create + fixtures (sans toucher aux deps ni lancer Vite)
```

**Procédure manuelle équivalente** (si tu veux comprendre, ou setup
juste la DB sans installer le reste) :

```bash
# 1. Drop + recreate la DB si elle existe déjà
php bin/console doctrine:database:drop --force --if-exists
php bin/console doctrine:database:create

# 2. Schema depuis les annotations d'entité (vendor + client)
php bin/console doctrine:schema:create

# 3. Init la metadata storage de doctrine-migrations
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction

# 4. Marquer toutes les migrations comme appliquées (sans les ré-exécuter)
php bin/console doctrine:migrations:version --add --all --no-interaction

# 5. Vérifier que tout est aligné
php bin/console doctrine:schema:validate
```

À partir de là, `make migrate` fonctionne **normalement** pour les
migrations à venir - vous n'ajoutez qu'une seule migration par fois,
pas un mélange historique entier.

> ⚠️ Cette approche fonctionne **uniquement** parce que les migrations
> sont structurelles (CREATE / ALTER / RENAME) - pas de migration de
> données qui modifie des rows. Si votre projet ajoute une migration de
> données (`UPDATE …` ou `INSERT …`), elle sera *sautée* par cette
> méthode. Dans ce cas, soit exécuter manuellement la donnée concernée,
> soit déclencher la donnée via les fixtures.

> Pour la CI, ces mêmes étapes sont déjà intégrées au workflow GitHub
> Actions par défaut. Cf.
> [`../deployment/github_actions_ci.md`](../deployment/github_actions_ci.md) §2.

---

## Fixtures

Les fixtures permettent de charger des données de développement ou de démo.

```bash
make fixtures           # reset DB + migrations + fixtures complètes
make demo               # fixtures de démo (données réalistes, pour démos produit)
make fixtures-load      # fixtures sans reset de la DB
make fixtures-append    # ajouter des fixtures sans vider les tables existantes
```

Les fixtures vivent dans `vendor/axelraboit/aurora/src/DataFixtures/` (Aurora)
et dans `src/DataFixtures/` (client, si créées).

---

## Séquences

Aurora utilise PostgreSQL `SEQUENCE` pour toutes les PKs et les références
métier (ex: `FAC-2026-0001`, `ORD-000001`).

### Séquences PK

Nommées `seq_<table>_id`. Créées automatiquement par les migrations Doctrine.

Après un import de données ou un `TRUNCATE` :

```bash
make sync-sequences     # aurora:sequences:resync - remet toutes les séquences au MAX(id)+1
```

### Séquences de références métier

Configurées dans **Paramètres → Séquences** dans l'interface admin.
Les préfixes sont définis dans `SequencePrefixEnum` (aurora-core).

Pour des entités client qui ont besoin de références numérotées :

```php
// src/Sequence/ClientPrefixEnum.php
namespace App\Sequence;

enum ClientPrefixEnum: string
{
    case TrackingProject = 'TRKP';
}
```

```php
// src/Sequence/ClientSequencePrefixProvider.php
namespace App\Sequence;

use Aurora\Core\Sequence\SequencePrefixProviderInterface;

final class ClientSequencePrefixProvider implements SequencePrefixProviderInterface
{
    public function values(): array
    {
        return array_column(ClientPrefixEnum::cases(), 'value');
    }

    public function name(): string { return 'Aurora Client'; }
}
```

Enregistrer dans `config/services.yaml` :

```yaml
App\Sequence\:
    resource: '../src/Sequence/'
```

L'auto-configuration détecte l'interface et enregistre le provider.
Aurora lèvera une `LogicException` au boot si un préfixe entre en collision
avec un préfixe Core.

Voir la liste des préfixes réservés Aurora dans
[`../aurora-core/dev/extending_aurora.md`](../aurora-core/dev/extending_aurora.md).

---

## ApplicationParameters

Aurora expose des paramètres d'application configurables depuis l'admin
(Paramètres → Général) via `ApplicationParameterEnum`.

Pour accéder à un paramètre depuis le code :

```php
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

// Dans un service
$value = $this->settingRepository->getOrDefault(ApplicationParameterEnum::SomeSetting);
// ou, sans fallback automatique sur le defaultValue de l'enum :
$value = $this->settingRepository->get(ApplicationParameterEnum::SomeSetting->getKey());
```

`SettingRepository` est la classe canonique pour lire un paramètre. Méthodes utiles :
- `getOrDefault(ApplicationParameterEnumInterface): string` - retourne la valeur stockée ou le `getDefaultValue()` de l'enum
- `get(string $key, ?string $default = null): ?string` - lookup brut
- `getBoolean(string $key, bool $default = false): bool` - typed accessor

Après modification de `ApplicationParameterEnum` (ajout d'une nouvelle valeur) :

```bash
make sync-params    # aurora:application-parameter - crée les entrées manquantes en DB
```

---

## Résumé des commandes DB

| Commande | Description |
|---|---|
| `make migration` | Génère une migration depuis les changements d'entité |
| `make migrate` | Applique les migrations en attente |
| `make migrate-prev` | Rollback de la dernière migration |
| `make migration-generate` | Crée une migration vide (script SQL manuel) |
| `make schema-validate` | Valide que le schéma Doctrine correspond à la DB |
| `make fixtures` | Reset complet DB + fixtures |
| `make demo` | Fixtures de démo |
| `make sync-sequences` | Resync les séquences PK après import |
| `make sync-params` | Sync les ApplicationParameters |
