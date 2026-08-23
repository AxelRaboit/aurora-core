# FileTransfer - Validators

> Protections contre les uploads malveillants : extensions interdites,
> MIME-type incohérents, zip-bomb, dépassement de quotas.

## Contexte

Nimbus a une classe `TransferFileValidator` appelée lors de la finalize
(`TransferManager::finalize()`) qui :
- vérifie le nombre de fichiers (`maxFiles`)
- vérifie la taille totale (`maxSizeMb`)
- vérifie chaque extension contre une whitelist
- vérifie chaque MIME-type contre une whitelist (détecté via finfo, pas le header HTTP)
- inspecte les ZIP pour détecter le contenu executable ou la zip-bomb

Source Nimbus :
- `app/Service/TransferFileValidator.php`
- `app/Exception/*FileException.php` (4-5 exceptions typées)

## Architecture cible

`Aurora\Module\FileTransfer\Validator\TransferFileValidator` exposé comme
service avec `#[AsAlias]` sur une interface :

```php
interface TransferFileValidatorInterface
{
    /**
     * @param array<int, string> $uploadKeys
     * @throws FileTransferValidationException
     */
    public function validate(array $uploadKeys, int $maxFiles, int $maxSizeMb): void;
}

class TransferFileValidator implements TransferFileValidatorInterface
{
    public function __construct(
        protected readonly TusUploadService $tus,
        protected readonly array $allowedExtensions,
        protected readonly array $allowedMimeTypes,
        protected readonly array $forbiddenZipExtensions,
    ) {}

    public function validate(array $uploadKeys, int $maxFiles, int $maxSizeMb): void
    {
        // 1. Count
        if (count($uploadKeys) > $maxFiles) {
            throw new FileLimitExceededException(count($uploadKeys), $maxFiles);
        }

        $totalSize = 0;
        foreach ($uploadKeys as $key) {
            $upload = $this->tus->getUpload($key);
            if (!$upload) throw new UploadNotFoundException($key);

            $totalSize += $upload['size'];
            $this->validateExtension($upload['original_name']);
            $this->validateMimeType($upload['file_path']);
            $this->validateZipContent($upload['file_path']);
        }

        // 2. Total size
        if ($totalSize > $maxSizeMb * 1024 * 1024) {
            throw new SizeLimitExceededException($totalSize, $maxSizeMb);
        }
    }

    protected function validateExtension(string $filename): void { /* … */ }
    protected function validateMimeType(string $path): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);
        if (!in_array($detected, $this->allowedMimeTypes, true)) {
            throw new DisallowedFileTypeException($detected);
        }
    }
    protected function validateZipContent(string $path): void
    {
        if (!preg_match('/\.zip$/i', $path)) return;
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidZipException();
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $totalUncompressed += $stat['size'];
            $name = $stat['name'];
            // 1. Extension blacklist
            foreach ($this->forbiddenZipExtensions as $ext) {
                if (str_ends_with(strtolower($name), $ext)) {
                    throw new DisallowedZipContentException($name);
                }
            }
            // 2. Path traversal
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                throw new DisallowedZipContentException($name);
            }
        }
        $zip->close();
        // 3. Zip-bomb : ratio uncompressed/compressed > 100x
        if ($totalUncompressed / filesize($path) > 100) {
            throw new ZipBombDetectedException();
        }
    }
}
```

## Whitelists

Configurables via settings (cf. [admin.md](admin.md)) :

| Setting | Type | Défaut | Description |
|---|---|---|---|
| `fileTransfer.allowedExtensions` | csv | (large list incl. pdf, docx, xlsx, jpg, png, mp4, zip, tar.gz) | Whitelisting par extension |
| `fileTransfer.allowedMimeTypes` | csv | (matching MIME) | Whitelisting par MIME détecté |
| `fileTransfer.forbiddenZipExtensions` | csv | `.exe,.bat,.cmd,.sh,.ps1,.scr,.vbs,.js,.app,.deb,.rpm,.msi,.dmg,.com,.cpl,.dll` | Extensions interdites dans les ZIP |
| `fileTransfer.zipBombRatioMax` | int | 100 | Ratio uncompressed/compressed max |

Le client définit ses propres listes via Configuration si défaut trop
permissif/restrictif.

## Exceptions typées

Toutes héritent de `FileTransferValidationException` (`extends DomainException`) :

| Exception | Message client (i18n) |
|---|---|
| `FileLimitExceededException` | `file_transfer.errors.tooManyFiles` |
| `SizeLimitExceededException` | `file_transfer.errors.fileTooLarge` |
| `DisallowedFileTypeException` | `file_transfer.errors.fileTypeNotAllowed` |
| `DisallowedZipContentException` | `file_transfer.errors.zipContentNotAllowed` |
| `ZipBombDetectedException` | `file_transfer.errors.zipBombDetected` |
| `InvalidZipException` | `file_transfer.errors.zipCorrupt` |
| `UploadNotFoundException` | `file_transfer.errors.uploadNotFound` |

Le `FileTransferApiController::create` catch et renvoie 400/413 avec le
message i18n approprié.

## Décisions ouvertes

- **Antivirus** : pas en V1. Si besoin → hook protected dans Validator
  qui appelle ClamAV via socket. Setting `fileTransfer.antivirusEnabled`.
- **Validation côté client** : oui (filtre l'extension AVANT upload TUS
  pour éviter de gaspiller la bande passante), mais **doublée côté
  serveur** (le client peut être contourné).
- **Détection MIME** : `finfo` est obligatoire. Faire confiance au
  `Upload-Metadata: filetype=…` du client TUS = vulnérabilité.

## Tests obligatoires

- Upload `.exe` direct → DisallowedFileTypeException
- ZIP contenant `evil.exe` → DisallowedZipContentException
- ZIP-bomb (10 ko zip → 1 Go uncompressed) → ZipBombDetectedException
- 21 fichiers avec max=20 → FileLimitExceededException
- Total 101 Mo avec max=100 → SizeLimitExceededException
- ZIP avec path `../../etc/passwd` → DisallowedZipContentException
- Whitelist custom via setting override → validation passe pour le nouveau type
