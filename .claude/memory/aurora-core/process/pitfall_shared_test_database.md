# Piège : deux worktrees, une seule base de test

**Règle.** Un worktree qui va lancer les tests doit avoir **sa propre base de
test**, déclarée dans son `.env.test.local` (non versionné) :

```
DATABASE_URL="postgresql://axelraboit@127.0.0.1:5432/aurora_test_<worktree>?serverVersion=17&charset=utf8"
```

puis `make db-test` pour la créer et la migrer.

**Pourquoi.** `.env.test` pointe tous les checkouts vers la même base. Deux
suites qui tournent en même temps sur `aurora_test` se détruisent leurs
fixtures entre elles, et l'échec ne ressemble pas du tout à sa cause : on
obtient des dizaines de `Failed asserting that 302 is identical to 200`,
c'est-à-dire des redirections de connexion, réparties dans des modules qui
n'ont aucun rapport avec ce qu'on est en train d'écrire. Constaté : 51 erreurs
et 43 échecs sur un worktree pendant qu'un autre lançait `make ft`, puis 0 des
deux côtés une fois les bases séparées.

**Comment l'appliquer.**

1. Avant de croire à une régression massive, vérifier qu'aucune autre suite ne
   tourne : `ps aux | grep phpunit`.
2. Si le worktree partage encore `aurora_test`, lui donner sa base et relancer
   avant d'analyser quoi que ce soit.
3. Un échec isolé et reproductible peut aussi venir de `var/cache/test/pools` :
   les compteurs de limitation de débit y survivent d'un run à l'autre, et
   `SignatureChallengeTest` échoue alors seul, hors de toute concurrence.
   `php bin/console cache:clear --env=test` suffit.

Voir [process_make_ft_before_commit.md](process_make_ft_before_commit.md) pour
la porte elle-même.
