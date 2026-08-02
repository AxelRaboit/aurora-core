# Ne jamais éditer `vendor/` à la main

**Règle dure** : côté client, `vendor/axelraboit/aurora*` est en lecture seule.
Aucun `cp`, aucun `Edit`, aucun patch — même temporaire, même « juste pour
tester ».

## Pourquoi

Copier un fichier modifié de aurora-core dans le vendor du client raccourcit la
boucle de test : on voit l'effet sans passer par commit → push → `composer
update`. C'est précisément ce qui la rend dangereuse, parce que **l'état local
cesse de représenter ce que quiconque d'autre obtiendra** — CI comprise.

Constaté en session (2026-08-02, ajout du champ `description` sur
`PostTranslation`) :

- les 8 cibles du pipeline passaient en local, le vendor patché ayant
  `setDescription()` ;
- la CI du client échouait sur `Call to an undefined method
  PostTranslation::setDescription()`, Composer installant depuis le lock ;
- en débloquant le lock, il pointait encore sur le core de la **veille** : les
  copies manuelles masquaient une dérive bien plus large que le seul champ ;
- une copie mal ciblée avait en plus déposé un `PostSerializer.php` dans
  `Post/Service/`, d'où un `Cannot redeclare class` sans rapport avec le travail
  en cours.

Un vendor patché ne casse pas seulement la vérification : il la rend
**faussement rassurante**, ce qui est pire que pas de vérification du tout.

## Comment faire à la place

Quand une modification du client dépend d'une nouveauté de aurora-core ou d'un
module :

1. committer et pousser côté core / module ;
2. côté client : `composer update axelraboit/aurora axelraboit/aurora-<module>`
   (ou `make aurora-update` pour la mise à jour complète, symlinks compris) ;
3. relancer le pipeline **après** cette mise à jour, jamais avant ;
4. committer le `composer.lock` avec le changement client qui en dépend — sans
   ça, la CI construit contre l'ancienne version.

Corollaire : si le pipeline passe en local mais échoue en CI sur une méthode ou
une classe « inexistante », le premier réflexe est de comparer le lock au HEAD
publié — pas de chercher un bug dans le code.

Voir aussi [[process_check_aurora_client_sync]] pour le sens inverse (vérifier
que le client suit après une modif du core).
