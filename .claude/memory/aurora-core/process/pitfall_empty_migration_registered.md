---
name: pitfall-empty-migration-registered
description: Écrire le contenu d'une migration Doctrine **avant** son premier `migrations:migrate` - sinon le template auto-généré vide est marqué comme exécuté et il faut une migration de rattrapage.
metadata:
  type: feedback
---

**Règle** : la séquence correcte pour une migration Doctrine est :
1. `php bin/console doctrine:migrations:generate` (ou `diff`)
2. **Écrire** le contenu de `up()` (et `down()`) dans le fichier
3. **Ensuite seulement** `php bin/console doctrine:migrations:migrate`

**Why** : le runner enregistre la migration dans
`doctrine_migration_versions` dès qu'il l'a exécutée, peu importe que
`up()` soit vide ou pas. Si tu lances `migrate` sur le template
auto-généré (où `up()` contient juste `// this up() migration is
auto-generated, please modify it to your needs`), la migration passe
silencieusement (rien à faire = pas d'erreur) et est marquée
exécutée. Si tu écris le vrai contenu après coup, **il ne sera jamais
appliqué** - tu te retrouves avec un schéma desync et un
`doctrine:schema:validate` qui rapporte des objets fantômes.

**Comment l'avoir flairé** : `doctrine:schema:validate` vérifie le
schéma réel contre les mappings d'entités. Si après une migration
tout devrait être propre mais le validate rapporte des tables /
contraintes orphelines, le suspect #1 est une migration vide
marquée comme exécutée.

**Comment l'avoir réparé** : générer une migration de rattrapage avec
le vrai contenu, l'appliquer normalement. Vu en pratique le 2026-05-30
sur Phase 5 du merge Media → GED : `Version20260530082245` avait
été lancée vide, j'ai dû ajouter `Version20260530083658` avec les
`DROP TABLE IF EXISTS core_media*` réels.

**How to apply** : toujours rédiger la migration **dans le même flux
mental** que sa génération. Le pattern qui marche :

```bash
php bin/console doctrine:migrations:generate
# → écrire immédiatement le contenu du fichier (Edit/Write)
# → relire le fichier (Read) pour s'assurer que c'est bien sauvé
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate  # ← vérification finale
```

Et donc : **ne pas faire `migrate` puis revenir éditer le fichier**.
Si l'erreur est déjà commise, créer une migration de rattrapage.
