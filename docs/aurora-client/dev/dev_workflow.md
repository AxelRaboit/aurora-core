# Workflow de développement

## Démarrage

```bash
make start          # serveur Symfony en arrière-plan (TLS) + Vite au premier plan
make start-d        # serveur Symfony seul, en arrière-plan
make dev            # Vite seul (serveur déjà lancé)
make start-no-tls   # serveur sans HTTPS (si problème de certificat)
make start-dev-worker   # worker Messenger, dans un terminal à part
```

PostgreSQL tourne sur ta machine : aucune de ces cibles ne démarre de
base de données. Installation initiale : [`../getting-started/joining_a_project.md`](../getting-started/joining_a_project.md).

Pour arrêter :

```bash
make stop           # arrête le serveur Symfony (Ctrl+C pour Vite et le worker)
```

---

## Commandes du quotidien

### Cache

```bash
make cc             # cache:clear (env dev)
make cc-prod        # cache:clear --env=prod + warmup
```

Indispensable après :
- Modification d'un service/tag dans `services.yaml`
- Ajout d'un `#[AsAlias]` ou `#[AsDecorator]`
- Modification de `config/packages/doctrine.yaml`

### Tests + qualité

```bash
make ft             # make fix + make test + make migrate-check - à lancer avant chaque commit
make test           # tests complets (PHP + JS)
make test-backend   # PHPUnit uniquement
make test-frontend  # Vitest uniquement
make stan           # PHPStan analyse statique
make fix            # composer validate + ESLint, Twig CS Fixer, Rector, PHP CS Fixer (en mode correction) + PHPStan
```

> **Règle** : `make ft` doit être vert avant chaque commit, sans exception.

### Base de données

```bash
make migration-diff # génère une migration depuis les changements d'entité, dans ClientMigrations
make migrate        # joue les migrations en attente
make migrate-prev   # rollback de la dernière migration
make schema-validate # valide que le schéma Doctrine correspond à la DB
make sync-params    # synchronise ApplicationParameter (séquences, params)
```

### Assets et i18n

```bash
make build          # build prod des assets
make translation           # régénère les JSONs vue-i18n depuis les YAMLs Symfony
```

Après modification d'un fichier `translations/messages.{fr,en}.yaml` :
```bash
make translation && make dev   # ou make build si en prod
```

### Debug

```bash
make sf CMD="debug:container DocumentCategoryManagerInterface"   # vérifier qu'un alias est bien câblé
make sf CMD="debug:router --show-controllers"          # lister toutes les routes
make sf CMD="debug:config framework"                   # inspecter une config bundle
make routes                                             # alias pour debug:router
make about                                              # résumé de l'app (PHP, Symfony, env)
```

---

## Workflow type : ajouter une feature

1. **Modifier le code** (entité, manager, vue…)
2. **Si schéma DB changé** : `make migration-diff`, relire le fichier, puis `make migrate`
3. **Si traductions ajoutées** : `make translation`
4. **Vérifier** : `make ft`
5. **Commit** : message en anglais, préfixe standardisé (`feat:`, `fix:`, `refactor:`, `docs:`)

---

## Workflow type : déboguer un service mal câblé

```bash
# 1. Le service est-il dans le container ?
make sf CMD="debug:container <NomDuService>"

# 2. L'alias pointe-t-il sur la bonne classe ?
make sf CMD="debug:container <NomInterface>"

# 3. Vider le cache si modif récente
make cc

# 4. Valider le schema si erreur Doctrine
make schema-validate
```

---

## Commandes moins fréquentes

```bash
make fixtures           # reset complet DB + fixtures de dev
make demo               # fixtures de démo ajoutées par-dessus (--append)
make fixtures-load      # fixtures ajoutées par-dessus les données (--append), dev uniquement
make fixtures-append    # la même chose, sans le garde-fou dev

make start-dev-worker   # worker Messenger (async + planificateur)
                        # nécessaire pour les publications programmées et les
                        # tâches du planificateur, les mails des formulaires,
                        # le déplacement des documents de la médiathèque,
                        # le récapitulatif de l'espace client

make aurora-update      # mettre à jour aurora-core (voir update_aurora.md)
```

---

## Variables Makefile utiles

```bash
make sf CMD="cache:clear --env=prod"   # passer n'importe quelle commande Symfony
# (`make tag` n'existe plus : une release se publie en mergeant develop
#  sur master, le workflow Release s'occupe du tag et des notes.)
```
