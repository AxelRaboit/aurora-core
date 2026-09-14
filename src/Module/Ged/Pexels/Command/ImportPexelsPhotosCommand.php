<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Pexels\Command;

use Aurora\Module\Ged\Pexels\Service\PexelsClient;
use Aurora\Module\Ged\Pexels\Service\PexelsImporter;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Files chosen Pexels photos, named by id, exactly as the picker would.
 *
 * The same {@see PexelsImporter} the browser calls, which is the whole point.
 * A document that arrived through here is indistinguishable from one somebody
 * clicked: same download width, same store, same variants, same
 * "médias éditoriaux" category, and - the part that is easy to lose when this
 * is reimplemented by hand - the same `sourceUrl` and attribution columns, from
 * which the credit under the picture is rendered. Pexels asks for that credit,
 * and a bypass that forgets it is a licence problem, not a cosmetic one.
 *
 * Why it exists: the module's own import is a route behind a session, and its
 * services are private, so anything without a browser had to decrypt the key
 * and re-drive the provider itself. That second implementation is exactly what
 * drifts - it was the category and the audit line that went missing first.
 *
 * Ids come from {@see SearchPexelsCommand}, or from a pexels.com address: the
 * number at the end of `/photo/<slug>-<id>/` is the id.
 *
 * Nothing is deduplicated. Importing the same photo twice gives two documents,
 * because the module behaves that way too and this must not be a third
 * behaviour.
 */
#[AsCommand(
    name: 'aurora:ged:pexels:import',
    description: 'Import Pexels photos by id, the same way the médiathèque picker would.',
)]
final class ImportPexelsPhotosCommand extends Command
{
    public function __construct(
        private readonly PexelsClient $client,
        private readonly PexelsImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'ids',
            InputArgument::IS_ARRAY | InputArgument::REQUIRED,
            'Pexels photo ids, in the order they should be filed.',
        );

        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Look the photos up and stop before downloading.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importing Pexels photos');

        if (!$this->client->isConfigured()) {
            $io->error('Pexels is not configured. Enable it and enter the API key in Settings > Médiathèque > Pexels.');

            return Command::FAILURE;
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter(array_map(mb_trim(...), (array) $input->getArgument('ids')), static fn (string $id): bool => '' !== $id));

        $imported = 0;
        $failed = 0;

        foreach ($ids as $id) {
            // Looked up before anything is written, so a typo costs a request
            // rather than a half-filed document.
            $photo = $this->client->photo($id);

            if (null === $photo) {
                $io->warning(sprintf('%s: no such photo, or Pexels could not be reached.', $id));
                ++$failed;

                continue;
            }

            if ((bool) $input->getOption('dry-run')) {
                $io->writeln(sprintf(
                    '  · %s <info>%s</info> (%s)',
                    $id,
                    mb_substr((string) ($photo['description'] ?? ''), 0, 60),
                    (string) $photo['authorName'],
                ));

                continue;
            }

            try {
                $document = $this->importer->import($photo);
            } catch (InvalidArgumentException $exception) {
                // The payload is not a photo we may take: a missing
                // photographer, a URL off their CDN. Not worth retrying.
                $io->warning(sprintf('%s: refused. %s', $id, $exception->getMessage()));
                ++$failed;

                continue;
            } catch (RuntimeException $exception) {
                // The photo is real and the fetch failed. Worth retrying, and
                // said differently for that reason.
                $io->warning(sprintf('%s: download failed. %s', $id, $exception->getMessage()));
                ++$failed;

                continue;
            }

            ++$imported;
            $io->writeln(sprintf(
                '  ✓ #%d <info>%s</info> (%s, %s)',
                $document->getId(),
                $document->getTitle(),
                $id,
                (string) $document->getAttributionName(),
            ));
        }

        if ((bool) $input->getOption('dry-run')) {
            $io->info(sprintf('%d photo(s) would be imported.', count($ids) - $failed));

            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io->success(sprintf('Done. Imported: %d  Failed: %d', $imported, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
