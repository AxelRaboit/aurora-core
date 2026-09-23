# Une date en hydratation tableau part en objet dans le JSON

## Règle

Une requête qui rend des tableaux (`->getArrayResult()`, `->select('e.id', 'e.updatedAt')`)
rend les colonnes date comme des **`DateTimeImmutable`**, pas comme des chaînes.
`json_encode` les écrit alors `{"date": "...", "timezone_type": 3, "timezone": "UTC"}`,
que le navigateur ne sait pas lire comme une date.

Tout tableau destiné à un composant Vue formate donc ses dates avant de
partir : `$value->format(DateTimeInterface::ATOM)`, comme le font les
sérialiseurs.

## Pourquoi

Le 23/09/2026, la bibliothèque des notes s'est affichée en cadre vide, sans
un mot, sur l'instance d'Axel. `MarkdownNoteRepository::findFlatListForUser()`
hydrate en tableau ; ses dates partaient en objets ; la carte appelait
`Intl.DateTimeFormat` dessus, qui lève une `RangeError` ; **une exception
pendant le rendu emporte tout le composant**, et Vue rend du vide en
consignant l'erreur dans la console.

Le défaut dormait depuis longtemps : aucun écran n'affichait ces dates. Le
jour où la bibliothèque a montré « modifiée le » et proposé un tri par date,
il est sorti d'un coup. Le panneau du menu, nourri par les mêmes lignes mais
n'affichant aucune date, fonctionnait parfaitement à côté - ce qui a coûté
une heure de diagnostic.

## Comment l'appliquer

- Dans un repository qui rend des tableaux pour le front, mapper les dates en
  ATOM avant de retourner, et le dire dans l'annotation `@return` : écrire
  `createdAt: string` plutôt que `DateTimeImmutable` est la moitié du garde-fou.
- Couvrir par un test d'intégration qui lit l'endpoint et `assertIsString()`
  sur la date : c'est un contrat, pas un détail de sérialisation.
- Côté Vue, ne jamais laisser un formatage de date se produire sans garde :
  `Number.isFinite(Date.parse(value))` avant `format()`. Un affichage qui
  lève fait disparaître l'écran, pas la ligne.
- Le corollaire général : **un champ que personne n'affiche cache sa forme**.
  Quand un écran commence à lire un champ jusque-là ignoré, vérifier ce que
  le serveur en envoie vraiment, pas ce que son nom laisse croire.
