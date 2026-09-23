# Un gestionnaire `async` qui échoue peut vider tout un écran

## Règle

Une fonction `async` branchée sur un `@click` doit être appelée avec ses
propres arguments - `v-on:click="ouvrir()"`, pas `v-on:click="ouvrir"` - dès
qu'elle prend un paramètre. Branchée nue, elle reçoit le `PointerEvent` à la
place, et **une promesse rejetée dans un gestionnaire d'événement remonte
jusqu'au `errorCaptured` le plus proche** : sur une page qui en a un, l'écran
entier est remplacé par l'état d'erreur pour une faute qui n'a rien cassé.

Un paramètre qui a une valeur par défaut ne protège de rien : l'événement est
un argument, donc la valeur par défaut ne s'applique pas.

## Pourquoi

Le 23/09/2026, cliquer la loupe de la bibliothèque des notes vidait la page.
`useFoldable().reveal(selector = "input")` était branché nu sur le clic, donc
`selector` valait un `PointerEvent` ; `querySelector` refuse autre chose
qu'une chaîne en levant, la promesse partait en échec, Vue la remontait, et le
`onErrorCaptured` de `MarkdownNotesApp` - posé quelques heures plus tôt pour
qu'une page qui casse le dise - affichait son écran d'erreur à la place de
tout. Le repli du tri, écrit pareil, faisait la même chose.

Les tests de composant ne l'ont pas vu : montés seuls, sans parent qui
capture, l'échec se contentait de partir dans la console pendant que le DOM
s'affichait correctement. **La frontière d'erreur transforme un bruit en
panne visible**, ce qui est son travail, mais rend aussi indispensable de
tester ce que la page fait d'un rejet.

## Comment l'appliquer

- Appeler explicitement : `v-on:click="openSearch()"`. Si le gestionnaire veut
  l'événement, il le prend en premier paramètre et le nomme.
- Dans un composable dont la signature invite au branchement nu, ignorer un
  argument d'un autre type que celui attendu plutôt que de laisser lever :
  `const css = "string" === typeof selector ? selector : "input";`.
- Couvrir par un test qui passe un `MouseEvent` et attend une promesse
  résolue : c'est le cas réel, pas un cas tordu.
- Et se souvenir du symptôme : **un écran qui se vide sans un mot au clic sur
  un bouton est presque toujours une exception remontée**, pas une donnée
  manquante. Voir aussi `backend/pitfall_array_hydration_dates.md`, l'autre
  façon de vider une page le même jour.
