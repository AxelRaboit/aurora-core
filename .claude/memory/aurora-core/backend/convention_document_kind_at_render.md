# Convention : la nature d'un document se vérifie au rendu, via `MimeGroupEnum`

## Règle

Deux règles, qui vont ensemble.

**1. « Est-ce une image ? » ne s'écrit qu'à un endroit.**
`Aurora\Core\Storage\Enum\MimeGroupEnum` — `matches()` en PHP, `applyTo()` en
SQL, écrits l'un à côté de l'autre.

```php
use Aurora\Core\Storage\Enum\MimeGroupEnum;

if (!MimeGroupEnum::Image->matches($document->getMimeType())) {
    return null;
}
```

**Jamais** `str_starts_with($mime, 'image/')` inline, ni
`MimeTypeEnum::tryFrom($mime)?->isImage()` pour cette question-là :
`MimeTypeEnum` est une liste fermée de 6 types, donc il refuserait
`image/avif` — que la bibliothèque classe pourtant dans Images.
`MimeGroupEnum` reflète le `LIKE 'image/%'` de la requête, préfixe et non
liste.

Un mime `null` n'appartient à **aucun** groupe, pas même `Other` — c'est ce que
répond déjà le SQL, où chaque comparaison contre NULL vaut NULL.

**2. Le garde-fou est au rendu, pas dans le normaliseur.**
Un view builder qui transforme un `mediaId` en `<img>` vérifie la nature du
document au moment où il construit la vue. On ne refuse pas à la frontière
d'écriture.

## Pourquoi

**Le normaliseur ne peut pas répondre.** `GridNormalizer` (et ses jumeaux) n'a
pas de base de données, et il ne tourne pas qu'à l'écriture :
`GridViewBuilder::resolve()` l'appelle **à chaque rendu**. Lui injecter un
repository mettrait une requête derrière chaque page vue.

**Et surtout, la réponse change après l'écriture.** Un document dont le fichier
est remplacé rend fausse une disposition qui était valide le jour où elle a été
enregistrée. Une validation à l'écriture n'a rien à refuser ce jour-là. Seul le
rendu voit l'état courant.

**Deux définitions de « image » qui divergent** donnent un document que la
bibliothèque liste sous Images et qu'un renderer refuse de dessiner, sans rien
pour dire lequel des deux a tort.

## Comment l'appliquer

Dans un `mediaData()` (ou tout équivalent qui produit une URL d'image) :

```php
private function mediaData(?DocumentInterface $media, string $alt): ?array
{
    if (!$media instanceof DocumentInterface) {
        return null;
    }

    if (!MimeGroupEnum::Image->matches($media->getMimeType())) {
        return null;
    }

    $url = $this->documentUrlGenerator->variantUrl($media, 'large')
        ?? $this->documentUrlGenerator->publicUrl($media);

    // Un document peut n'avoir aucun fichier — la démo GED en garde trois
    // exprès. Sans ça : `<img src="">`, une image cassée et pas une absente.
    if (null === $url) {
        return null;
    }

    return ['url' => $url, /* … */];
}
```

Le template teste déjà la clé (`{% if zone.media %}`), donc `null` veut dire
« ne rend rien » sans ligne de Twig à ajouter.

**Reste à faire** : `BannerViewBuilder::mediaData()` porte exactement le même
défaut et n'a pas été corrigé — un fond de bannière qui disparaît se voit bien
plus qu'une zone de grille vide, la décision se prend pour elle-même.

## Source

Une zone média de la grille de contenu pointée sur `demo-video.mp4` publiait
`<img src="…/demo-video.mp4">` — image cassée, aucune erreur. Analyse complète
des trois options envisagées (null au rendu / `<video>` / refus à l'écriture)
dans [`docs/aurora-core/todo/content-grid-48.md`](../../../../docs/aurora-core/todo/content-grid-48.md),
section « une zone média ne rend que des images ».
