<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Pexels\Command;

use Aurora\Module\Ged\Pexels\Service\PexelsClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The picker's search, without the picker.
 *
 * The médiathèque already searches Pexels, and that is the right way in for
 * somebody choosing a photograph for the page they are writing. It is the
 * wrong way in - in fact the only way in - for everything that has no browser:
 * seeding a demo, rebuilding a page from a script, or an assistant asked to
 * illustrate three sections and left to work on its own. Until now those had
 * to reach around the module, decrypt the key themselves and call the provider
 * by hand, which is a second implementation of the one thing this module is.
 *
 * Prints ids, because {@see ImportPexelsPhotosCommand} takes ids. The pair is
 * the point: look, then take the ones you want.
 *
 * Nothing is downloaded here and no quota is spent beyond the one search.
 */
#[AsCommand(
    name: 'aurora:ged:pexels:search',
    description: 'Search Pexels from the console, and print what the import would take.',
)]
final class SearchPexelsCommand extends Command
{
    public function __construct(
        private readonly PexelsClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::REQUIRED, 'What to look for.');
        $this->addOption('page', null, InputOption::VALUE_REQUIRED, 'Which page of results.', '1');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Show at most this many of them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The same answer the picker gives, for the same reason: an
        // administrator who has not filled the tab in has a screen to go to,
        // and a stack trace would not name it.
        if (!$this->client->isConfigured()) {
            $io->error('Pexels is not configured. Enable it and enter the API key in Settings > Médiathèque > Pexels.');

            return Command::FAILURE;
        }

        $query = (string) $input->getArgument('query');
        $result = $this->client->search($query, (int) $input->getOption('page'));

        if ([] === $result['results']) {
            $io->warning(sprintf('Nothing found for "%s".', $query));

            return Command::SUCCESS;
        }

        $photos = $result['results'];
        $limit = $input->getOption('limit');

        if (is_numeric($limit)) {
            $photos = array_slice($photos, 0, max(1, (int) $limit));
        }

        $io->table(
            ['id', 'size', 'photographer', 'description'],
            array_map(static fn (array $photo): array => [
                (string) $photo['id'],
                sprintf('%dx%d', $photo['width'], $photo['height']),
                (string) $photo['authorName'],
                mb_substr((string) ($photo['description'] ?? ''), 0, 70),
            ], $photos),
        );

        $io->info(sprintf(
            'Page %d of %d. Take one with: aurora:ged:pexels:import <id>',
            max(1, (int) $input->getOption('page')),
            $result['totalPages'],
        ));

        return Command::SUCCESS;
    }
}
