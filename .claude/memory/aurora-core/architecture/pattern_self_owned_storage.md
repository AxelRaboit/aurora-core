---
name: pattern-self-owned-storage
description: Convention pour les modules qui stockent leurs propres fichiers - 5 colonnes standard, UploadUrlGenerator, var/uploads/<module>/Y/m/, jamais de FK vers Media library.
metadata:
  type: project
---

## Règle

Tout nouveau module aurora-core (ou client) qui doit stocker des fichiers
**internes au domaine** (PDFs métier générés, exports, archives
temporaires, OCR raw uploads…) **possède son propre stockage** sous
`var/uploads/<module>/Y/m/<slug>-<uniq>.<ext>` - **jamais** une FK vers
une entité partagée.

**Why** : un module métier qui FK'ait une entité-fichier partagée se
trouvait couplé à son lifecycle (rétention, droits, chiffrement,
versioning), ce qui empêchait les politiques spécifiques d'évoluer
indépendamment. Le risque historique venait du module `Media` (supprimé
en 2026-05-30 lors de la fusion Media → GED) ; depuis, **les assets de
contenu utilisateur (images, documents administratifs, etc.) FK'ent vers
`Aurora\Module\Ged\Document\Entity\Document`** - c'est l'entité-fichier
canonique d'Aurora, qui a *justement* été conçue pour porter ce lifecycle
partagé. La règle de self-owned ne concerne donc que les fichiers
*internes* dont aucun autre module ne doit avoir vue.

**How to apply** : pour toute nouvelle entité-fichier, suivre le pattern
documenté ci-dessous. Exemples vivants : `WeldingPdfDocument`,
`Aurora\Module\Ged\Document` (depuis la refacto du 2026-05-24).

## Schéma standard - 5 colonnes sur l'entité

```php
abstract class AbstractMyEntity implements MyEntityInterface
{
    use TimestampableTrait;

    /** Path relatif sous `var/uploads/` (ex: my_module/2026/05/abc.pdf). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $filePath = null;

    /** Filename sur disque (slug + extension). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $fileName = null;

    /** Filename original uploadé (pour Content-Disposition au download). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $size = null;

    // ... getters / setters standard
}
```

Sur des entités où le fichier est **obligatoire** (ex: `DocumentVersion`),
les colonnes sont en NOT NULL - sinon `nullable: true`.

## Storage sur disque

- **Path** : `var/uploads/<module>/Y/m/<slug>-<uniq>.<ext>`
- **Module slug** : ajouter un case dans
  `Aurora\Core\Storage\Enum\StorageAreaEnum` (infra de stockage transverse,
  vit dans Core - pas dans un module feature). Cases existants : `Media`,
  `Ocr`, `Photo`, `Users`, `Ged`.
- **Upload** : créer un petit service `<Module>Uploader` qui slugifie le
  nom, génère un uniqid, déplace le fichier via `Filesystem`, retourne les
  5 champs comme array. Pattern de référence :
  `Aurora\Module\Ged\Document\Service\GedDocumentUploader`.
- **Endpoint** : `POST /backend/<module>/upload` qui reçoit un
  `UploadedFile`, appelle le uploader, renvoie les 5 champs en JSON. Le
  form Vue les stocke dans le state et les envoie avec le submit.

## URLs publiques - toujours via UploadUrlGenerator

```php
use Aurora\Core\Storage\Service\UploadUrlGenerator;

class MyEntitySerializer
{
    public function __construct(
        protected readonly UploadUrlGenerator $uploadUrlGenerator,
    ) {}

    public function serialize(MyEntityInterface $entity): array
    {
        return [
            // ...
            'fileUrl' => $this->uploadUrlGenerator->publicUrl($entity->getFilePath()),
        ];
    }
}
```

**Jamais** `'/uploads/'.$path` en hardcode. Le route name `uploads_serve`
peut changer demain - `UploadUrlGenerator` encapsule cette dépendance.

Pour les URLs absolues (emails, RSS) : `publicUrlAbsolute()`.

## Référencer un fichier d'un autre module

Si une entité a besoin de **référencer un fichier appartenant à un autre
module** (ex: `WeldingPdfTemplate` référence un `Document` GED), ne PAS
copier le fichier - stocker une FK vers l'entité propriétaire :

```php
#[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
protected ?DocumentInterface $document = null;
```

Le serializer lit `$entity->getDocument()->getFilePath()` puis appelle
`UploadUrlGenerator->publicUrl(...)`. C'est la stratégie "W3" appliquée
dans la refacto Welding → GED.

**Consommateurs actuels du W3 vers GED `Document`** :
- `WeldingPdfTemplate.document` (W3, refacto initiale)
- `BillingInvoice.document` + `BillingOcrJob.document` (mêmes Document
  partagé entre OcrJob et Invoice produit)
- `ProjectTask.attachments` (`ManyToMany<DocumentInterface>` via
  `core_project_task_documents` - refacto 2026-05-24, commit `ba6b55c5`)

**Règle d'arbitrage** : un module qui veut "attacher un fichier" à une
entité métier doit FK-référencer un GED `Document` (= ce pattern), pas
créer son propre stockage. Les `var/uploads/<module>/` self-owned sont
réservés aux modules dont le fichier **est** l'entité (PdfDocument
Welding, Document GED, MarkdownNote image, profile photo…).

**Usage / traçabilité (2026-05-25)** : « où ce document est-il utilisé ? »
est exposé via le registre `DocumentUsageProviderInterface` (tag
`aurora.document_usage_provider`, agrégé par `DocumentUsageService`, endpoint
`GET /backend/ged/documents/{id}/usage`, affiché dans le panneau détail).
**Tout nouveau consommateur W3 doit fournir son provider** (un par module qui
FK-référence un Document) - sinon ses usages n'apparaîtront pas. Core en a 3 :
`BillingInvoiceDocumentUsageProvider`, `BillingOcrDocumentUsageProvider`,
`ProjectDocumentUsageProvider` ; Welding ajoute le sien côté client. C'est le
miroir exact de `MediaUsageProviderInterface`, mais en **query builder Doctrine**
(FK typées, pas de scan de contenu - contrairement à Media qui scanne le JSONB).

## Re-traiter une image self-owned (crop/rotate/…) - toujours vers un NOUVEAU path

**Piège** : `recordVersion()` ne duplique PAS le fichier sur disque - la
ligne `DocumentVersion` pointe sur le **même** `filePath` que le document.
Donc un crop/rotate **en place** (overwrite du fichier) écraserait aussi
les bytes de la version précédente → original perdu.

**Règle** : pour transformer une image en gardant l'original, écrire le
résultat dans un **nouveau** path `ged/Y/m/<slug>-<uniq>.<ext>` (comme un
ré-upload), laisser le fichier source intact, puis basculer le document
dessus et `recordVersion()`. C'est exactement la sémantique de `update()`
(snapshot du fichier qui devient courant). L'original reste récupérable
via la ligne de version antérieure + ses bytes intacts à l'ancien path.

Implémentation de référence (2026-05-25, commit `2616e07e`) :
- `DocumentManager::cropImage()` → orchestration (mutate → flush →
  `recordVersion` → audit `document.cropped`)
- `GedDocumentUploader::cropToNewFile()` → build du nouveau path + crop
- Pixel work : `Aurora\Core\Storage\Service\ImageCropper` (service Core
  partagé, `crop(sourceAbs, destAbs, mime, x,y,w,h): ?[w,h]`, clamp +
  alpha PNG/WebP). Aussi consommé par `MediaManager::crop`.

> **Media vs GED** : les deux cropent **vers une nouvelle version** (nouveau
> fichier `destAbs !== sourceAbs`, original gardé via la ligne de version
> précédente). Différence : Media régénère ses **variants**
> (thumbnail/medium/large) sur le fichier courant - les versions ne stockent
> que le fichier brut (pas de variants par version) ; GED n'a pas de variants.
> Même `ImageCropper`. Entités d'historique : `DocumentVersion`
> (`core_ged_document_versions`) et `MediaVersion` (`core_media_versions`),
> toutes deux dans la catégorie « audit/historique auto-généré » de la
> convention (hors 5-couches CRUD).

**Rétention (cap rolling, 2026-05-25)** : l'historique est plafonné par le
setting **`ApplicationParameterEnum::FileVersionsLimit`** (`file_versions_limit`,
groupe `media`, défaut **3**, éditable en admin, `0` = illimité). Un setting
unique gouverne **les deux** modules. Chaque `recordVersion()` appelle
`pruneVersions()` qui supprime les versions au-delà de la limite (les plus
anciennes) **avec leur fichier sur disque** via `<repo>::findPrunable(entity, limit)`
(`setFirstResult($limit)` sur l'ordre DESC). Le fichier courant est toujours la
version la plus récente → jamais purgé. Côté GED le fichier est supprimé via
`GedDocumentUploader::deleteFile()` (le manager n'a pas de `Filesystem`) ;
côté Media directement (il a `Filesystem` + `uploadDir`). Pattern calqué sur
`post_revisions_limit` / `PostRevisionRepository::pruneOlderThanLimit`.

## Migration depuis un couplage Media

Pattern de migration testé :

1. DROP la FK + DROP l'index
2. `UPDATE table SET file_id = NULL` (les anciennes valeurs pointaient
   vers Media - invalides après le swap de cible)
3. Si on switch vers une autre FK : RENAME `file_id` → `<new>_id` + ADD
   la nouvelle FK
4. Si on switch vers self-owned : DROP `file_id`, ADD les 5 colonnes

Exemple : `aurora-core/migrations/Version20260524160716.php` (GED).

## Tests

Pour stubber `UploadUrlGenerator` sans plumber la route :

```php
use Aurora\Core\Testing\Concern\CreatesStorageUrlGenerators;

final class MySerializerTest extends TestCase
{
    use CreatesStorageUrlGenerators;

    public function testIt(): void
    {
        $serializer = new MySerializer($this->makeUploadUrlGenerator());
        // ...
    }
}
```

Le helper retourne `/uploads/<path>` pour toute route, donc les
assertions de URL shape restent simples et stables.

## Voir aussi

- [[pattern-core-submodules-split]] - chaque module owne son domaine
- [[decision-4-hard-rules]] - pas d'import `Core → Module`
- Migration de référence : `aurora-core 2026-05-24` (commit `f66ffaf1`)
