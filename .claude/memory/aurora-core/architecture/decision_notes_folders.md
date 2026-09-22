# Notes : un dossier est une entité, une note est une feuille

## Règle

Dans `src/Module/Notes`, le rangement et le contenu sont **deux objets**.
`NoteFolder` (`src/Module/Notes/Folder/`) porte l'arborescence ; `MarkdownNote`
porte un `folder_id` et ne contient plus rien. Toute nouvelle fonction du
module se pose la question dans ces termes : est-ce que cela range, ou est-ce
que cela se lit ?

Deux corollaires qui ne se devinent pas :

- **Le nom d'un dossier est chiffré** (`EncryptedTextType`), comme le titre
  d'une note. Donc aucun `ORDER BY` ni aucun `LIKE` ne le touche : le tri de
  l'arbre et de la bibliothèque se fait en PHP ou dans le navigateur, jamais
  en SQL. Même contrainte que pour les notes, pour la même raison.
- **Un dossier ne se partage pas.** Un lien public porte une note, plus les
  notes qu'elle cite si on le demande. Le commutateur « inclure les
  sous-notes » a été retiré avec l'arbre de notes qu'il désignait.

## Pourquoi

Avant le 22/09/2026, une note qui avait des enfants tenait lieu de dossier.
Chaque écran devait deviner lequel des deux il regardait, l'export inventait
une convention (un `.md` et un répertoire du même nom) pour dire qu'un objet
était les deux à la fois, et le carnet n'avait aucune page pour se regarder :
`/backend/notes/markdown` renvoyait vers la première note.

Le nom chiffré n'était pas le choix de départ. Il s'est imposé à la migration :
le chiffré d'Aurora est autonome (nonce + message, en base64, une seule clé),
donc `INSERT ... SELECT n.title` copie un titre de note dans un nom de dossier
sans jamais déchiffrer. La conversion tient ainsi dans une migration
transactionnelle, sans conteneur Symfony ni commande à lancer entre deux
migrations - ce qui aurait été un déploiement en deux temps sur la prod.

## Comment l'appliquer

- Une requête qui range, filtre ou compte des dossiers passe par
  `NoteFolderRepository`, **toujours** avec l'utilisateur : un dossier
  appartient à son auteur comme une note.
- Un déplacement passe par `NoteFolderManager::move()`, qui refuse le cycle et
  la profondeur au-delà de `NoteFolderHierarchy::MAX_DEPTH` (8). Ne pas
  refaire ce contrôle dans un contrôleur : c'est un invariant de la donnée.
- La corbeille suit `pattern_trash_soft_delete` : un dossier supprimé emporte
  ses sous-dossiers et ses notes avec `trashed_with_folder_id`, et la
  restauration rend la branche. Deux sources alimentent l'écran global,
  `NotesTrashSource` et `NoteFoldersTrashSource`.
- Le `down()` de `Version20260922210000` ne rend pas exactement ce qu'il a
  pris : un dossier vide redevient une note ordinaire, que le `up()` ne saura
  plus reconnaître. C'est écrit dans la migration ; ne pas s'en servir comme
  d'un aller-retour sûr des semaines après coup.

Voir `docs/aurora-core/dev/entity_extensibility_convention.md` §4.bis.3 pour
les slots de la bibliothèque et du panneau.
