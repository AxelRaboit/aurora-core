<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/{{MODULE_KEBAB}}', name: 'suite_{{MODULE_ID}}')]
#[IsGranted('{{MODULE_ID}}.use')]
final class {{MODULE}}Controller extends AbstractController
{
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@{{MODULE}}/suite/index.html.twig');
    }
}
