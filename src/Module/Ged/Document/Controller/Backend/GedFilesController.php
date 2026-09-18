<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\StoredFileResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function str_contains;
use function str_starts_with;

/**
 * The GED's own serve endpoint, for the files the public one withholds.
 *
 * `/uploads/{path}` answers for published documents and refuses everything
 * else, which is right for the public and useless for the screen that has to
 * show a draft's thumbnail while somebody decides whether to publish it. So
 * the module defines the more specific route CLAUDE.md §5bis prescribes, the
 * way the contracts module already does.
 *
 * **Why a route and not a privilege check on the catch-all.** The admin
 * firewall is `^/(backend|dev)`. A request to `/uploads/…` is handled by the
 * front firewall, in a different session context, so no backend identity
 * exists there to test - a check placed on the catch-all would refuse staff
 * exactly as it refuses strangers. Being under `/backend` is not decoration
 * here, it is the only place the question can be asked at all.
 *
 * **By key, not by id.** One route then covers a document's own file, its
 * rendered still, each responsive variant and the snapshot every previous
 * version points at, without the caller having to say which kind it holds.
 * `DocumentUrlGenerator` swaps this route in for the catch-all and changes
 * nothing else, so no consumer learns that a second address exists.
 */
#[Route('/backend/ged/files', name: 'backend_ged_files')]
#[IsGranted('ged.documents.view')]
final class GedFilesController extends AbstractController
{
    public function __construct(
        private readonly StoredFileResponder $responder,
    ) {}

    #[Route(
        '/{path}',
        name: '',
        requirements: ['path' => '[^.][^./].*'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(string $path): Response
    {
        if (str_contains($path, '..')) {
            throw $this->createNotFoundException();
        }

        // Confined to the GED's own area. Without this line the privilege
        // that opens the document library would also open `contracts/`, and
        // the whole point of that area having its own gated route is that
        // reading a signed contract asks a different question.
        if (!str_starts_with($path, StorageAreaEnum::Ged->value.'/')) {
            throw $this->createNotFoundException();
        }

        // Servi par le service commun : local déchargé par le serveur web,
        // distant diffusé par morceaux, privé une heure. L'autorisation, elle,
        // vient d'être donnée ci-dessus et reste ici.
        return $this->responder->respond($path);
    }
}
