# Installer des modules Aurora à la carte (sans Packagist)

> ⚠️ **Obsolète - les packages modules ne sont plus maintenus.**
> Les dépôts `aurora-assistant`, `aurora-personal-finance`, `aurora-billing`,
> `aurora-crm`, `aurora-planning`, `aurora-commerce`, `aurora-photo`,
> `aurora-project`, `aurora-notes`, `aurora-hr`, `aurora-tools`, ainsi que
> `aurora-editorial` et le client `aurora-welding`, sont **archivés en lecture
> seule sur GitHub**. Ne pas s'y brancher : un `composer require` continuerait
> de fonctionner, mais figerait le projet sur du code qui ne recevra plus rien.
>
> Un projet consommateur ne requiert plus que `axelraboit/aurora` (le core).
> La mécanique décrite ci-dessous reste valable **comme référence** si des
> packages à la carte reviennent un jour ; elle ne décrit plus l'état actuel.

Chaque module Aurora est un package Composer indépendant
(`axelraboit/aurora-<module>`) hébergé sur son propre repo GitHub. Un client
n'installe **que** ce dont il a besoin. Aucune publication Packagist requise :
on passe par des dépôts VCS.

> Côté client, l'install se résume à 4 points (dont 2 sont auto-découverts).

> 🧰 **Kit copier-coller** : un template prêt à l'emploi vit dans aurora-core à
> `vendor/axelraboit/aurora/.claude/client_template/` -
> `composer.json` (dépôt VCS de core déjà listé + require à la carte),
> `config/bundles.php`, `config/routes.yaml`,
> `config/packages/messenger.yaml`. Copie-les, puis tu ne touches plus que
> la section `require`/`repositories` pour ajouter un module.

> ⚠️ **Modules externes figés** : le recentrage d'Aurora (juillet 2026) a
> sorti 11 modules du monorepo (Tools, Crm, Billing, Photo, Project, Hr,
> Notes, PersonalFinance, Planning, Assistant, Commerce). Leurs repos
> GitHub existent toujours et s'installent comme décrit ici, mais ils ne
> sont plus maintenus depuis aurora-core et restent figés à leur dernier
> état. L'outillage d'extraction (`bin/split-modules.sh`) a été retiré :
> aucun nouveau package ne sera découpé. Cf. mémoire
> `architecture/project_monorepo_split_chantier.md`.
>
> **Editorial** faisait partie de ces packages extraits ; il est en cours
> de réintégration dans aurora-core comme module natif, livré par défaut.
> Un client n'a donc plus à le requérir séparément.

## 1. Déclarer les dépôts VCS + requérir les packages

Dans le `composer.json` du client :

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "axelraboit/aurora": "^0.6",
        "axelraboit/aurora-tools": "dev-master"
    },
    "repositories": [
        { "type": "vcs", "url": "git@github.com:AxelRaboit/aurora-core.git" },
        { "type": "vcs", "url": "git@github.com:AxelRaboit/aurora-tools.git" }
    ]
}
```

> Sans Packagist, **chaque** package installé a besoin de son entrée
> `repositories` (Composer n'utilise que les `repositories` du projet racine).
> C'est la seule liste à maintenir par module.

`composer update axelraboit/*` - terminé pour la partie packages.

## 2. Bundles - auto-découverts (zéro édition par module)

Le `config/bundles.php` du client spread les bundles de **tous** les packages
`aurora-*` installés. Installer/désinstaller un module = `composer require/remove`,
rien à toucher ici :

```php
// config/bundles.php
return [
    Aurora\AuroraBundle::class => ['all' => true],
    ...Aurora\Core\Bundle\AuroraModuleBundles::all(\dirname(__DIR__)),
    // ... bundles framework ...
];
```

`AuroraModuleBundles::all()` scanne `vendor/axelraboit/aurora-*/composer.json`
et lit `extra.aurora.bundles` (array, ex. `aurora-commerce`) ou
`extra.symfony.bundle`. `aurora-core` est ignoré (son bundle est listé à part).

## 3. Routes - auto-découvertes (une seule entrée)

```yaml
# config/routes.yaml
aurora:
    resource: '../vendor/axelraboit/aurora/src/'
    type: attribute

aurora_modules:
    resource: .
    type: aurora_modules   # loader fourni par aurora-core
```

`aurora_modules` (cf. `Aurora\Core\Routing\AuroraModuleRouteLoader`) importe les
contrôleurs de chaque package `vendor/axelraboit/aurora-*` installé. Une entrée,
quel que soit le nombre de modules.

## 4. Assets Vue - automatiques

Rien à faire : le build Vite d'aurora-core (`vite-plugin-aurora-modules.js` +
`aliases.js` vendored-aware) découvre les composants Vue des packages installés
sous `vendor/axelraboit/aurora-*`. `make build` côté client les bundle comme s'ils
étaient first-party. Voir Gate 2 (option B) dans
`docs/aurora-core/dev/audit/packaging_playbook.md`.

## Transport `async` (modules avec jobs longs)

Certains modules routent des messages longs en async via leur bundle (ex.
`aurora-billing` → `ProcessOcrJobMessage: async`). Le client doit **définir le
transport `async`** dans son `config/packages/messenger.yaml`, sinon
`cache:clear` échoue (« not a valid transport ») :

```yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
            sync: 'sync://'
            scheduler_main: 'schedule://main'
when@test:
    framework:
        messenger:
            transports:
                async: 'in-memory://'
```

Le **routing** des messages modules est déclaré par chaque bundle module
(prependExtension) - le client n'a que le transport à fournir.

## Désinstaller un module

`composer remove axelraboit/aurora-<module>` + retirer son entrée
`repositories`. Les bundles, routes et assets disparaissent automatiquement
(auto-découverte). Penser à gérer le schéma DB (migrations) si le module avait
des tables.

## Cas particulier : `aurora-commerce`

`Ecommerce` + `Erp` sont **inséparables** (les contrôleurs Ecommerce
dépendent du `ProductRepository` concret d'Erp) → un seul package
`axelraboit/aurora-commerce` qui embarque les deux (sous-dossiers `Ecommerce/`
+ `Erp/`, deux bundles déclarés via `extra.aurora.bundles`). On l'installe d'un
bloc.
