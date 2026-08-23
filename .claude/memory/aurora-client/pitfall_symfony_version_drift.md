# Piège : dérive des composants Symfony vers le major suivant (manque `extra.symfony.require`)

## Symptôme

La debug toolbar (ou `php bin/console --version`) affiche **Symfony 8.0.x**
alors que le `composer.json` du client vise 7.4 (`symfony/runtime: "7.4.*"`).
`composer.lock` est un **mélange** : la plupart des composants en 7.4.x mais
~25 paquets (dont `symfony/http-kernel`, `monolog-bridge`, `security-http`)
en 8.0.x. C'est le `http-kernel` qui pilote le numéro affiché (`Kernel::VERSION`).

## Cause

Le client n'a **pas** de garde-fou `extra.symfony.require` dans son
`composer.json` racine, et ne pinne explicitement que quelques composants
(`runtime`, `browser-kit`, `css-selector`, `web-profiler-bundle`).

aurora-core, lui, a bien `extra.symfony.require: "7.4.*"` + chaque composant
pinné - **mais ce réglage ne vaut que pour l'install racine d'aurora-core**,
pas quand il est tiré comme dépendance. Côté client, aurora-core n'impose que
ses propres `require` (ex: `framework-bundle: 7.4.*`). Or `http-kernel` n'est
pas une dépendance directe d'aurora-core (transitif), et `framework-bundle`
7.4 tolère `http-kernel ^7.4|^8.0`. Sans garde-fou racine côté client, Flex
résout au plus haut → 8.0.

## Règle

**Tout client Aurora doit avoir le garde-fou dans son `composer.json` racine** :

```json
"extra": {
    "symfony": {
        "require": "7.4.*"
    }
}
```

Il contraint **tous** les `symfony/*` (y compris transitifs) à la branche
ciblée. C'est déjà présent dans le template aurora-client (composer.json),
donc tout nouveau clone l'hérite. Le vérifier si un client a été créé avant
ce fix ou scaffolddé à la main.

## Correctif sur un client déjà dérivé

```bash
# 1. ajouter le bloc extra.symfony.require ci-dessus dans composer.json
# 2. redescendre toute la stack
composer update "symfony/*" --with-all-dependencies
# 3. vérifier : plus aucun symfony/* en v8.x, version attendue
php bin/console --version
```

## Vérification rapide

```bash
# compter les symfony/* qui ont dérivé vers le major suivant
php -r '$l=json_decode(file_get_contents("composer.lock"),true);
$n=0; foreach(array_merge($l["packages"],$l["packages-dev"]??[]) as $p){
  if(str_starts_with($p["name"],"symfony/") && str_starts_with($p["version"],"v8")) $n++;
} echo "symfony/* en 8.x: $n\n";'
```

Doit retourner `0`. Une valeur non nulle = garde-fou manquant ou contourné.
