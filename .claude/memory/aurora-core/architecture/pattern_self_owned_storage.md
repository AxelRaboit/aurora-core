---
name: pattern-self-owned-storage
description: Convention des modules qui stockent leurs propres fichiers - 5 colonnes standard, UploadUrlGenerator, var/uploads/<area>/Y/m/, nom de fichier aleatoire ; les assets de contenu, eux, FK'ent le Document GED.
metadata:
  type: project
---

## Règle

Un module qui stocke des fichiers **internes au domaine** (PDFs générés,
exports, archives) **possède son propre stockage** sous
`var/uploads/<area>/Y/m/<aléatoire>.<ext>`.

**Why** : un module métier couplé au lifecycle d'une entité-fichier
partagée (rétention, droits, versioning) ne peut plus faire évoluer sa
politique seul.

**Mais c'est l'exception, pas la règle.** Un fichier de **contenu**
(image, pièce jointe, document administratif) FK'e
`Aurora\Module\Ged\Document\Entity\Document` - l'entité-fichier canonique,
conçue pour porter ce lifecycle partagé. Le self-owned ne concerne que les
fichiers dont aucun autre module ne doit avoir vue.

**Exemples vivants** : GED `Document`, photos de profil, contrats Studio,
images collées dans une note markdown.

## Nommage sur disque - aléatoire, jamais dérivé

`Aurora\Core\Storage\StoredFileName` : 16 octets de `random_bytes()`, rien
d'autre. **Pas de slug du nom d'origine, pas d'`uniqid()`** - c'était la
convention jusqu'au 2026-09-16 et elle était devinable (`cv.pdf` + le
microseconde de l'upload). Pour la plupart de ce qu'Aurora stocke,
l'adresse est la seule serrure : `UploadAccessDecider` laisse un préfixe
non revendiqué en anonyme et la GED sert tout document `published`. Le nom
d'origine n'est pas perdu, il est **stocké** (`originalName`) et rendu au
download via `Content-Disposition`.

## Schéma standard - 5 colonnes sur l'entité

```php
abstract class AbstractMyEntity implements MyEntityInterface
{
    use TimestampableTrait;

    /** Path relatif sous `var/uploads/`. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $filePath = null;

    /** Nom sur disque (aléatoire + extension). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $fileName = null;

    /** Nom d'origine, pour le Content-Disposition au download. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $size = null;
}
```

NOT NULL là où le fichier est obligatoire (ex: `DocumentVersion`).

## Storage sur disque

- **Area** : ajouter un case à `Aurora\Core\Storage\Enum\StorageAreaEnum`
  (infra transverse, vit dans Core). Cases : `Ged`, `ProfilePhotos`,
  `Contracts`, `NotesMarkdown`. **La valeur est un préfixe de chemin : on
  ajoute et on retire, on ne renomme jamais** - renommer orpheline tout ce
  qui a été écrit avant.
- **Revendiquer l'area** : un préfixe sans `UploadAccessGuardInterface` est
  servi **anonymement** par le catch-all `/uploads/{path}`. Mesuré le
  2026-09-16 : `notes-markdown` répondait 200 sans session. Un guard doit
  répondre `Denied`, jamais `Restricted` - `/uploads/` n'est pas derrière
  le firewall `^/(backend|dev|workspace)`, donc il n'y a pas de login vers
  lequel rediriger.
- **Upload** : un service `<Module>Uploader` qui appelle `StoredFileName`,
  déplace via `Filesystem`, retourne les 5 champs. Référence :
  `GedDocumentUploader`.

## URLs publiques - toujours via UploadUrlGenerator

```php
'fileUrl' => $this->uploadUrlGenerator->publicUrl($entity->getFilePath()),
```

**Jamais** `'/uploads/'.$path` en dur. Absolu (emails, RSS) :
`publicUrlAbsolute()`.

> **Si l'entité porte un statut de publication, `UploadUrlGenerator` ne
> suffit plus.** Il reçoit une clé et rien d'autre, donc il ne sait
> construire que l'adresse publique - et depuis que le catch-all ne sert
> que les documents `published`, c'est la mauvaise adresse pour tout le
> reste. Il faut un générateur qui voit l'entité et choisit sa route,
> comme `DocumentUrlGenerator::routeFor()`. Le piège est silencieux :
> l'URL se construit sans erreur et répond 404 en production.
>
> Vaut aussi pour les fichiers dérivés. La clé d'un variant ne dit rien
> du statut de l'image dont il vient : c'est l'entité qu'on interroge,
> jamais le chemin.

## Référencer un fichier d'un autre module

Ne pas copier le fichier - FK vers l'entité propriétaire :

```php
#[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
protected ?DocumentInterface $document = null;
```

**Consommateurs actuels** (mesuré le 2026-09-16) :
- `SpaceContentAttachment.document` - FK, `onDelete: CASCADE`
- `Post.thumbnail` et `PostTranslation.ogImage` - FK, `SET NULL`
- `Post.galleryLayout.items[].mediaId` - id **dans du JSON**
- `Deck` : `mediaId` / `bgMediaId` par slide + `logoMediaId` dans le style,
  également en JSON (`DeckPictures` est la seule liste qui sait où)

**Règle d'arbitrage** : « attacher un fichier » à une entité métier =
FK vers un `Document`. Le self-owned est réservé aux modules dont le
fichier **est** l'entité.

**Chaque consommateur doit fournir son `DocumentUsageProviderInterface`**
(tag `aurora.document_usage_provider`, agrégé par `DocumentUsageService`,
`GET /backend/ged/documents/{id}/usage`, affiché au moment de supprimer).
Sinon la bibliothèque annonce « aucun usage » sur un document utilisé, et
la suppression casse en silence - en `CASCADE` la pièce jointe disparaît
de l'espace du client, en `SET NULL` le billet perd sa couverture.
Deux formes selon le stockage : **query builder Doctrine** quand c'est une
FK (exact, refactor-safe), **scan** quand l'id vit dans du JSON
(`DeckDocumentUsageProvider`).

## Re-traiter une image (crop/rotate/…) - toujours vers un NOUVEAU path

**Piège** : `recordVersion()` ne duplique PAS le fichier - la ligne
`DocumentVersion` pointe sur le **même** `filePath`. Un crop en place
écraserait donc aussi les bytes de la version précédente.

**Règle** : écrire le résultat dans un **nouveau** path (comme un
ré-upload), laisser la source intacte, basculer le document dessus puis
`recordVersion()`. L'original reste récupérable via la ligne antérieure.

- `DocumentManager::cropImage()` → orchestration + audit `document.cropped`
- `GedDocumentUploader::cropToNewFile()` → nouveau path + crop
- `Aurora\Core\Storage\Service\ImageCropper` → pixels (clamp, alpha PNG/WebP)

**Rétention** : plafonnée par `ApplicationParameterEnum::FileVersionsLimit`
(`file_versions_limit`, défaut **3**, `0` = illimité). `recordVersion()`
appelle `pruneVersions()`, qui supprime les versions au-delà de la limite
**avec leur fichier** via `findPrunable(entity, limit)`. Le fichier courant
est la version la plus récente → jamais purgé.

## Orphelins

`aurora:storage:prune-orphans` balaie `var/uploads/` et compare aux clés
déclarées par les `ReferencedKeysProviderInterface`. Sec par défaut : rien
n'est supprimé sans `--force`, et les fichiers de moins de 7 jours sont
épargnés. **Une area sans provider verrait tous ses fichiers comme
orphelins** - ajouter l'une sans l'autre arme donc une suppression de
données pour le jour où quelqu'un passe `--force`.

## Tests

```php
use Aurora\Core\Testing\Concern\CreatesStorageUrlGenerators;
// $this->makeUploadUrlGenerator() → /uploads/<path> pour toute route
```

## Voir aussi

- [[pattern_core_submodules_split]] - chaque module owne son domaine
- [[decision_4_hard_rules]] - les hooks de Manager (ce document ne parle pas
  des imports : `Core` importe bien `SettingRepository` du module
  Configuration, c'est le cas établi par `LocaleContext` et le contexte front)
