<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Command;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;

/**
 * Creates a user, interactively by default.
 *
 * `aurora:install` seeds everything a fresh Aurora needs except this, on
 * purpose: a known email and password shipped by an automated command would be
 * a hole in every deployment that forgot to change it. Credentials are the one
 * thing a human has to decide, so they are asked for - the password prompt is
 * hidden and never lands in a file, an environment variable or a CI log.
 *
 * Arguments are accepted for the cases where that is genuinely wanted (a test
 * fixture, a provisioning script holding a generated secret), but nothing is
 * assumed when they are absent.
 */
#[AsCommand(
    name: 'aurora:user:create',
    description: 'Crée un utilisateur (interactif par défaut). À utiliser après aurora:install pour le premier administrateur.',
)]
class UserCreateCommand extends Command
{
    public function __construct(
        protected readonly UserRepository $userRepository,
        protected readonly EntityManagerInterface $entityManager,
        protected readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    /**
     * The role the flags ask for.
     *
     * `--dev` wins over `--admin` rather than the two conflicting: `ROLE_DEV`
     * already contains `ROLE_ADMIN` through the hierarchy, so asking for both
     * is asking for the wider one.
     *
     * This option exists because the first account of an installation needs it.
     * `/dev` is gated on `ROLE_DEV`, so an owner created with `--admin` - which
     * is what the install command tells you to run - cannot open the dashboard
     * that toggles modules or lists mount points on their own site.
     */
    private function role(InputInterface $input): UserRoleEnum
    {
        if (true === $input->getOption('dev')) {
            return UserRoleEnum::Dev;
        }

        if (true === $input->getOption('admin')) {
            return UserRoleEnum::Admin;
        }

        return UserRoleEnum::User;
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email - demandé si absent')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe - demandé (masqué) si absent')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiché')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Donne le rôle administrateur (ROLE_ADMIN)')
            ->addOption('dev', null, InputOption::VALUE_NONE, "Donne le rôle développeur (ROLE_DEV) - à utiliser pour le premier compte d'une installation")
            ->addOption('frontend', null, InputOption::VALUE_NONE, 'Crée un compte frontend au lieu du backend');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $email = $this->askEmail($input, $symfonyStyle);
        if (null === $email) {
            return Command::FAILURE;
        }

        $type = $input->getOption('frontend') ? UserTypeEnum::Frontend : UserTypeEnum::Backend;

        // Backend and frontend accounts are distinct rows and may legitimately
        // share an address, so uniqueness is checked per type rather than on
        // the email alone.
        if (null !== $this->userRepository->findOneBy(['email' => $email, 'type' => $type])) {
            $symfonyStyle->error(sprintf('Un compte %s existe déjà avec l\'email "%s".', $type->value, $email));

            return Command::FAILURE;
        }

        $password = $this->askPassword($input, $symfonyStyle);
        if (null === $password) {
            return Command::FAILURE;
        }

        $name = (string) ($input->getOption('name') ?? '');
        if ('' === $name) {
            $name = $input->isInteractive()
                ? (string) $symfonyStyle->ask('Nom affiché', $this->defaultNameFor($email))
                : $this->defaultNameFor($email);
        }

        $user = new User();
        $user->setEmail($email)
            ->setName($name)
            ->setType($type)
            ->setRoles([$this->role($input)->value])
            ->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $symfonyStyle->success(sprintf(
            'Compte %s créé : %s%s',
            $type->value,
            $email,
            match ($this->role($input)) {
                UserRoleEnum::Dev => ' (développeur)',
                UserRoleEnum::Admin => ' (administrateur)',
                default => '',
            },
        ));

        return Command::SUCCESS;
    }

    private function askEmail(InputInterface $input, SymfonyStyle $symfonyStyle): ?string
    {
        $email = $input->getOption('email');

        if (null === $email && $input->isInteractive()) {
            $email = $symfonyStyle->ask('Email');
        }

        $email = mb_trim((string) $email);

        if ('' === $email) {
            $symfonyStyle->error("L'email est obligatoire (--email en mode non interactif).");

            return null;
        }

        if (0 !== count(Validation::createValidator()->validate($email, new Email()))) {
            $symfonyStyle->error(sprintf('"%s" n\'est pas un email valide.', $email));

            return null;
        }

        return $email;
    }

    /**
     * Hidden prompt, asked twice. A typo in a password nobody can read back is
     * only discovered at the login screen, on an install that may have no other
     * way in.
     */
    private function askPassword(InputInterface $input, SymfonyStyle $symfonyStyle): ?string
    {
        $password = $input->getOption('password');

        if (null === $password) {
            if (!$input->isInteractive()) {
                $symfonyStyle->error('Le mot de passe est obligatoire (--password en mode non interactif).');

                return null;
            }

            $password = $symfonyStyle->askQuestion(new Question('Mot de passe')->setHidden(true)->setHiddenFallback(false));
            $confirmation = $symfonyStyle->askQuestion(new Question('Confirme le mot de passe')->setHidden(true)->setHiddenFallback(false));

            if ($password !== $confirmation) {
                $symfonyStyle->error('Les deux saisies diffèrent.');

                return null;
            }
        }

        $password = (string) $password;

        if (mb_strlen($password) < 8) {
            $symfonyStyle->error('Le mot de passe doit faire au moins 8 caractères.');

            return null;
        }

        return $password;
    }

    private function defaultNameFor(string $email): string
    {
        return ucfirst(mb_strstr($email, '@', true) ?: $email);
    }
}
