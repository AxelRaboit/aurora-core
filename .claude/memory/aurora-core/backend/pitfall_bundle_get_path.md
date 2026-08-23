# Piège : `AbstractBundle::getPath()` par défaut → racine projet

## Règle

Override `getPath()` dans tout bundle qui vit à `src/<Bundle>.php` et dont
le projet a un dossier `public/` à la racine.

```php
class AuroraBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return __DIR__;  // src/, pas la racine projet
    }
}
```

## Pourquoi

`AbstractBundle::getPath()` par défaut fait `dirname($file, 2)` :

- Bundle file at `src/AuroraBundle.php`
- `dirname(..., 2)` = **racine du projet**

Quand `assets:install` (déclenché par `composer install` via auto-scripts)
tourne :

1. Iterate sur les bundles enregistrés.
2. Pour chaque bundle, copie `<getPath()>/Resources/public` ou
   `<getPath()>/public` vers `public/bundles/<bundleName>/`.
3. Pour AuroraBundle : `getPath()` = projet root → `public/` = celui du
   projet (= la cible) → copie récursive infinie.

Symptômes observés (mai 2026) :

- `assets:install` timeout (5 min)
- `public/bundles/aurora/` à **7.9 GB** rempli de
  `bundles/aurora/bundles/aurora/bundles/aurora/...` jusqu'à ce que le
  filesystem refuse (filename too long).

## Comment l'appliquer

### Pour aurora-core (déjà fait)

Override dans `src/AuroraBundle.php`. Retourner `__DIR__` (= `src/`).
Pas de `src/public/` → `assets:install` ne copie rien → instantané.

### Pour tout futur bundle Aurora

Si tu crées un nouveau bundle qui suit la convention "src/Bundle.php +
project root contains public/", **toujours** override `getPath()` -
sinon même piège.

### Comment détecter le piège tôt

```bash
php -r '
require "vendor/autoload.php";
$bundle = new \YourBundle();
$path = $bundle->getPath();
echo "getPath: $path\n";
echo "public/ at this path: " . (is_dir("$path/public") ? "YES (DANGER)" : "no") . "\n";
'
```

Si "YES (DANGER)" → override getPath().

### Vérifications après override

```bash
time php bin/console assets:install public
# → "No assets were provided by any bundle." en <1s
```

## Source

Piège découvert le 8 mai 2026 sur aurora-core. Fix dans le commit
`fix(bundle): override getPath() to scope bundle to src/`.

## Voir aussi

- [`pitfall_resolve_target_entities.md`](pitfall_resolve_target_entities.md)
- Documentation Symfony : [AbstractBundle path resolution](https://symfony.com/doc/current/bundles.html#bundle-directory-structure)
