# La couche de stockage

Tout ce qu'Aurora écrit passe par un adaptateur. Cette page dit lequel, comment
en ajouter un, et les quelques pièges qui ne se voient qu'en production.

> **En une phrase** : ne lisez jamais `app.upload_dir`. Demandez un adaptateur
> à `StorageManager`, et un chemin local à `LocalWorkspace` si votre code a
> besoin d'un nom de fichier.

## Les pièces

| Classe | Rôle |
|---|---|
| `StorageAdapterInterface` | Le contrat. Écrire, lire, supprimer, lister, décrire. |
| `LocalStorageAdapter` | Le disque sous `var/uploads/`. Implémentation de référence. |
| `R2StorageAdapter` | Cloudflare R2, par son API compatible S3. |
| `StorageManager` | Quel adaptateur pour écrire, quel adaptateur pour **ce** fichier. |
| `LocalWorkspace` | Prête un vrai chemin au code qui ne prend que des noms de fichiers. |
| `StoredFileLocator` | Quel support détient la clé demandée par `/uploads/{chemin}`. |
| `StorageProbe` | Prouve qu'un support fonctionne, en s'en servant. |

## Écrire du code qui stocke

```php
final readonly class MonService
{
    public function __construct(
        private StorageManager $storageManager,
    ) {}

    public function enregistrer(UploadedFile $fichier): string
    {
        $cle = sprintf('%s/%s/%s', StorageAreaEnum::Ged->value, date('Y/m'), $nom);

        // PHP a déjà posé l'upload quelque part sur cette machine : lui passer
        // ce chemin évite une copie de plus.
        $this->storageManager->active()->writeFromLocalFile($cle, $fichier->getPathname());

        return $cle;
    }
}
```

Une **clé** est le chemin relatif que la base stocke déjà. Elle ne change pas
quand un fichier change de support : c'est ce qui rend un déplacement invisible
pour tout ce qui la référence.

**Écrire** demande `active()`. **Lire ou supprimer** demande
`forDisk($entite->getStorageDisk())` : le réglage dit où va le *prochain*
fichier, un fichier déjà écrit dit lui-même où il est.

## Le code qui exige un nom de fichier

GD, `pdftoppm`, Ghostscript, `getimagesize`, une pièce jointe d'e-mail :
beaucoup de choses ne prennent pas un flux. `LocalWorkspace` leur prête un
chemin, et le geste diffère selon l'intention. **Les confondre est la façon
dont un dérivé cesse silencieusement d'être enregistré.**

| Verbe | Intention | Sur le disque | Sur un support distant |
|---|---|---|---|
| `readable()` | Je regarde, je ne modifie pas | Prête le fichier stocké | Télécharge, supprime après |
| `writable()` | Je modifie sur place | Prête le fichier stocké | Télécharge, renvoie, supprime |
| `target()` | Je crée un nouvel objet | Prête le chemin de destination | Temporaire, puis envoi |

```php
$dimensions = $this->workspace->readable($adapter, $cle, static fn (string $chemin): array|false
    => getimagesize($chemin));
```

`writable()` ne renvoie rien si le travail lève : un réencodage interrompu ne
remplace pas les octets d'origine par la moitié d'un fichier. `target()`
n'enregistre rien si le travail n'a rien écrit : un PDF sans première page
lisible ne laisse pas d'objet vide derrière lui.

Sur le disque local, les trois prêtent le fichier stocké lui-même. Aucune
copie, aucun temporaire, exactement les performances d'avant la couche.

## Ce qui coûte de l'argent

Sur un disque, `is_file()` et `filesize()` sont des appels système. Sur un
stockage objet, ce sont des requêtes HTTP facturées. Le code se lit pareil et
a un compteur derrière.

- **`list()` rend les métadonnées avec les clés.** Un listing qui rend les clés
  seules transforme un appel sur mille objets en mille et un.
- **`deleteMany()` groupe.** Une boucle de `delete()` facture chaque tour.
- **Pas de `exists()` réflexe.** Si vous venez d'écrire, vous connaissez la
  taille : ne la redemandez pas.

## Ajouter un support

1. Implémenter `StorageAdapterInterface`. Le `_instanceof` de
   `config/services.yaml` tague automatiquement.
2. Ajouter un cas à `StorageDiskEnum`. **La valeur atterrit en base**, dans la
   colonne `storage_disk` : ne jamais renommer un cas existant.
3. N'implémenter `LocalPathAware` que si la clé *est* réellement un fichier de
   cette machine. Un cache d'objets distants ne doit pas : le chemin serait une
   copie, et `writable()` n'enregistrerait rien.
4. Faire passer `aurora:storage:doctor --disk=<le vôtre>`.

L'enum ne porte que des cas qui ont un adaptateur derrière. Un cas sans
implémentation laisse une colonne nommer un support que `StorageManager` ne
sait pas servir, c'est-à-dire une panne déguisée en type.

## Deux pièges vérifiés en production

**Cloudflare compresse à la volée.** Une réponse gzippée n'a pas de
`Content-Length` et porte un ETag faible, donc un `HeadObject` rend une taille
absente pour un `text/plain` et la bonne pour un PNG. `R2StorageAdapter::stat()`
lit donc dans un listing réduit à la clé. Voir la mémoire
`pitfall_r2_head_metadata`.

**La date d'un objet distant est celle de sa mise en dépôt**, pas celle du
fichier d'origine. Après une migration tout paraît neuf, et une garde du type
« épargner les fichiers récents » épargne tout le lot.

## Tester

`LocalStorageAdapter` est l'implémentation de référence : la suite tourne
dessus, sans identifiants. Les tests R2 sont derrière `make test-r2`, se sautent
proprement sans clés, et sont exclus du run par défaut parce que Symfony ignore
`.env.local` quand `APP_ENV=test`.

Pour éprouver un déplacement entre deux supports sans réseau,
`DocumentRelocatorTest` monte deux adaptateurs locaux dont l'un se déclare R2.
Ce qui y est testé est l'ordre des opérations et le verrou, qui ne dépendent pas
du support d'en face.

## Voir aussi

- Le manuel utilisateur : rubrique Configuration, page « Stockage des fichiers ».
- Mémoires : `pattern_storage_adapter`, `pitfall_r2_head_metadata`.
- Le déplacement d'un document : `DocumentRelocator`, et sa page dans la
  rubrique Médiathèque. Pour un ensemble connu depuis la console (une
  catégorie, des identifiants) : `aurora:ged:relocate <local|r2>
  --category=<id> | --id=<id>…`, que les outils du tour public appellent pour
  garder leurs images sur le disque du serveur.
