<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Poll\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Poll\Entity\PollVote;
use Aurora\Module\Editorial\Poll\Repository\PollVoteRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function count;
use function hash_hmac;
use function is_int;
use function is_string;
use function preg_split;
use function round;

/**
 * Where a reader's answer to a poll zone lands.
 *
 * Anonymous by design, like a comment reaction: the reader is a salted
 * fingerprint of their address and browser, and the unique index on
 * (publication, zone, reader) is what keeps it to one vote each - a second
 * vote fails at the insert and is answered with the tally, not an error.
 *
 * Nothing is trusted from the page but the answer's position. The poll must
 * be a zone of a publication that is out, and the position must be one of
 * the answers that zone offers in this language.
 */
final class PollVoteController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly PollVoteRepository $votes,
        private readonly EntityManagerInterface $entityManager,
        private readonly GridNormalizer $gridNormalizer,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {}

    #[Route('/{locale}/poll/{postId}/{zoneId}', name: 'editorial_poll_vote', requirements: ['locale' => '[a-z]{2}', 'postId' => '\d+', 'zoneId' => '[A-Za-z0-9_-]{1,36}'], methods: [HttpMethodEnum::Post->value], priority: 12)]
    public function vote(string $locale, int $postId, string $zoneId, Request $request): JsonResponse
    {
        $post = $this->postRepository->find($postId);

        if (!$post instanceof PostInterface || !$post->isPublished()) {
            return $this->jsonFailure('frontend.editorial.grid.poll.closed', 404);
        }

        $answers = $this->answers($post, $zoneId, $locale);
        $answer = $this->decodeJson($request)['answer'] ?? null;

        if (null === $answers || !is_int($answer) || $answer < 0 || $answer >= count($answers)) {
            return $this->jsonFailure('frontend.editorial.grid.poll.closed');
        }

        $voter = hash_hmac('sha256', $request->getClientIp().'|'.$request->headers->get('User-Agent', '').'|'.$postId.'|'.$zoneId, $this->secret);

        if (!$this->votes->hasVoted($postId, $zoneId, $voter)) {
            try {
                $this->entityManager->persist(new PollVote($post, $zoneId, $answer, $voter));
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // Two clicks racing: the second lost, and the first counted.
            }
        }

        $tally = $this->votes->tally($postId, $zoneId);
        $total = array_sum($tally);

        return $this->jsonSuccess([
            'total' => $total,
            'answers' => array_map(static fn (int $index): array => [
                'votes' => $tally[$index] ?? 0,
                'percent' => 0 === $total ? 0 : (int) round(($tally[$index] ?? 0) / $total * 100),
            ], array_keys($answers)),
        ]);
    }

    /**
     * The answers the zone offers in this language, or null when the zone is
     * not a poll of this publication.
     *
     * @return list<string>|null
     */
    private function answers(PostInterface $post, string $zoneId, string $locale): ?array
    {
        $layout = $this->gridNormalizer->normalizeLayout($post->getGridLayout());
        $zone = null;

        foreach (GridNormalizer::flatten($layout['zones']) as $candidate) {
            if ($candidate['id'] === $zoneId) {
                $zone = $candidate;
            }
        }

        if (null === $zone || GridNormalizer::ZONE_POLL !== $zone['type']) {
            return null;
        }

        $content = $this->gridNormalizer->normalizeContent($post->translate($locale)->getGrid(), $layout);
        $code = $content['zones'][$zoneId]['code'] ?? '';
        $lines = is_string($code) ? array_values(array_filter(array_map(trim(...), preg_split('/\R/', $code) ?: []))) : [];

        return count($lines) >= 2 ? array_slice($lines, 0, 8) : null;
    }
}
