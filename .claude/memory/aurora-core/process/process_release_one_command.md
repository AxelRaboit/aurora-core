---
name: process_release_one_command
description: La chaîne publier → propager → déployer tient dans `make release` ; ce que le script refuse, et pourquoi il refuse plutôt que de supposer.
metadata:
  type: project
---

## Règle

**La chaîne entière est `make release`** (`tools/release/release.sh`, depuis le
08/10/2026). Elle enchaîne : aurora-core vers `master` → attente du tag que le
workflow pose → `make aurora-update` sur aurora-client → `make ft` sur le
canari → commit du lock et push → publication d'aurora-client → `make
deploy-prod` sur le serveur → vérification que `VERSION` a bougé et que
l'application démarre.

```bash
make release DRY=1          # dit ce qu'il ferait, n'écrit rien - à lancer d'abord
make release                # la chaîne, sauvegarde de prod comprise
make release NO_BACKUP=1    # saute la sauvegarde (mot explicite exigé)
make release STOP_AT=core   # publie aurora-core et s'arrête
```

**Why:** six gestes manuels sur deux dépôts et un serveur, dont les deux qui
peuvent faire mal étaient invisibles dans une liste de commits - les migrations
qu'une fourchette porte, et si le canari a survécu au bump. Les deux sont des
portes maintenant. Une release à moitié faite est pire qu'une qui n'a jamais
commencé : un tag sans release devient une version installable pour tous les
consommateurs, et un serveur dont le canari n'est jamais passé transforme un
défaut du core en quelque chose qu'un client trouve.

**How to apply:** lancer `DRY=1` une première fois, les refus étant tout
l'intérêt. Le script s'arrête avec une raison sur : arbre sale, commits non
poussés, CI pas verte, changelog sans section close, tag déjà existant, bump
qui a touché autre chose que le lock, `make ft` rouge sur le canari, tag qui
n'apparaît pas, `VERSION` qui ne bouge pas, application qui ne démarre plus.
Rien après un refus n'est tenté.

`docs/aurora-core/dev/propagating_updates.md` garde les étapes à la main :
c'est ce que le script fait, et ce qu'il faut savoir refaire quand il s'arrête
quelque part.

## Le piège qui a motivé le script

Le 08/10/2026, la production servait la **v3.8.0** alors que la v4.0.0 était
publiée depuis l'après-midi : `aurora-client` avait été bumpé et poussé, mais
jamais déployé. Un déploiement n'appliquait donc pas les deux migrations de la
version en cours mais **quatre**, et franchissait une majeure au passage.

**Rien de tout ça n'était visible** sans aller lire
`/var/www/aurora-client/VERSION` sur le serveur. D'où la première et la
dernière ligne du script : dire ce que le serveur sert *avant*, et vérifier que
ça a bougé *après*. Un déploiement qui ne change pas `VERSION` n'a pas eu lieu.

Et **aurora-client a sa propre release**, qui calcule son numéro depuis les
commits (pas depuis un changelog, il n'en a pas). Le serveur ne déploie que des
tags de ce dépôt : publier aurora-core ne suffit pas, il faut publier le client
ensuite. C'est l'étape qu'on oublie, et celle qui explique le retard ci-dessus.
