<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Probe;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Doctrine\DBAL\Connection;

/**
 * Ce que pèse le stockage, et de quel côté.
 *
 * **Lu sur la table des documents, pas sur le disque.** Ce qui intéresse celui
 * qui regarde, c'est ce que l'application a rangé quelque part et dont elle
 * répond. Un `du` compterait aussi les restes d'un import raté et les
 * variantes qu'une purge n'a pas encore ramassées, ce qui donne un nombre plus
 * grand et moins vrai.
 *
 * Une requête agrégée plutôt qu'un chargement : l'inventaire peut faire des
 * milliers de lignes, et personne n'a besoin de les voir pour connaître leur
 * somme.
 */
final readonly class StorageUsageProbe
{
    public function __construct(private Connection $connection) {}

    /**
     * Le poids et le nombre, par emplacement.
     *
     * Les deux emplacements sont toujours rendus, même à zéro : un écran qui
     * masquerait celui qui est vide ne permettrait pas de lire « il ne reste
     * plus rien sur le serveur », qui est précisément ce qu'on vient vérifier.
     *
     * @return array<string, array{count: int, bytes: int}>
     */
    public function byDisk(): array
    {
        $usage = [];

        foreach (StorageDiskEnum::cases() as $disk) {
            $usage[$disk->value] = ['count' => 0, 'bytes' => 0];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT storage_disk, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes
             FROM core_ged_documents
             WHERE deleted_at IS NULL
             GROUP BY storage_disk',
        );

        foreach ($rows as $row) {
            $disk = (string) $row['storage_disk'];

            if (!isset($usage[$disk])) {
                continue;
            }

            $usage[$disk] = [
                'count' => (int) $row['files'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        return $usage;
    }

    /**
     * Le poids des fichiers rangés dans le dossier de chaque espace client.
     *
     * **Le dossier, et pas les rattachements.** Ce qu'on cherche à savoir,
     * c'est ce qu'un espace a fait *déposer* : un document choisi dans la
     * médiathèque était déjà là et le serait resté sans lui. Le téléverseur
     * range tout ce qui arrive par un espace dans son dossier, donc le dossier
     * est exactement la réponse.
     *
     * Une seule requête groupée : une liste d'espaces en compte des dizaines,
     * et une somme par ligne ferait autant d'allers-retours que de lignes.
     *
     * @return array<int, int> id de l'espace => octets
     */
    public function bySpace(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id AS space_id, COALESCE(SUM(d.size), 0) AS bytes
             FROM core_studio_customer_spaces s
             JOIN core_ged_documents d ON d.folder_id = s.document_folder_id AND d.deleted_at IS NULL
             WHERE s.document_folder_id IS NOT NULL
             GROUP BY s.id',
        );

        $bytes = [];

        foreach ($rows as $row) {
            $bytes[(int) $row['space_id']] = (int) $row['bytes'];
        }

        return $bytes;
    }
}
