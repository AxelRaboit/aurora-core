<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function base64_decode;
use function bin2hex;
use function file_put_contents;
use function json_decode;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

/**
 * Ce qu'un lien d'accès ne montre pas.
 *
 * **Le côté qui ne se voit pas depuis le studio.** Un réglage de
 * confidentialité se vérifie en ouvrant la page du client, ce que personne ne
 * fait à chaque déploiement ; une régression y serait donc silencieuse, et
 * découverte par le client lui-même. C'est exactement ce que des tests doivent
 * porter à la place.
 *
 * Deux garanties, et l'une des deux a deux moitiés : une étape marquée interne
 * retire **ses cartes** en plus d'elle-même, sans quoi elles resteraient dans
 * le calendrier du client, qui les lit par leur date et non par leur étape.
 */
final class SpaceClientVisibilityTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columns;

    private SpaceAccessLinkManagerInterface $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        // Sans cela le noyau redémarre entre deux requêtes, et l'espace créé
        // par l'écran cesse d'être la même instance que celle de ce test : le
        // gestionnaire de liens le voit alors comme une entité inconnue.
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        // Le compteur du limiteur survit au processus : une classe qui écrit
        // comme un invité dépense un budget horaire partagé, et vire au
        // rouge au troisième lancement de l'heure - par un 429 sur une
        // route que le test ne voulait pas éprouver.
        $this->resetRateLimiter('space_guest_write');

        // Le navigateur pose cet en-tête sur chaque appel, et les routes
        // publiques l'exigent : ce qui les protège est un secret dans
        // l'adresse, et une adresse se transfère.
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->links = $container->get(SpaceAccessLinkManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceContentAttachment::class, SpaceContentItem::class, SpaceContentColumn::class, SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testAStepMarkedInternalTakesItsCardsOutOfTheClientPage(): void
    {
        $space = $this->givenSpace();
        $columns = $this->columns->findForSpace($space);

        $open = $columns[0];
        $internal = $columns[1];

        $this->givenItem($space, $open, 'Ce que le client voit');
        $this->givenItem($space, $internal, 'Relecture juridique interne');

        // Un seul lien, relu deux fois : ce que voit le client change parce
        // que le réglage change, et non parce qu'on lui a donné une autre
        // adresse. C'est aussi ce qu'on veut prouver.
        $link = $this->givenLink($space);

        // Le titre dans la page, et rien de plus savant : c'est la garantie
        // telle qu'un client la constate, et elle ne dépend d'aucune façon
        // d'écrire les données dans le gabarit.
        $before = $this->clientPage($link);
        self::assertStringContainsString('Ce que le client voit', $before);
        self::assertStringContainsString('Relecture juridique interne', $before);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/update', $space->getId(), $internal->getId()), [
            'name' => $internal->getName(),
            'visibleToClient' => false,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $after = $this->clientPage($link);

        // L'étape disparaît, et sa carte avec elle : c'est la moitié qu'on
        // oublie, et celle qui laisserait la carte dans le calendrier.
        self::assertStringContainsString('Ce que le client voit', $after);
        self::assertStringNotContainsString('Relecture juridique interne', $after);
        self::assertStringNotContainsString($internal->getName(), $after);
    }

    /**
     * Une étape interne emporte aussi son fil et ses fichiers.
     *
     * **C'est la moitié qui manquait, et la plus grave.** Les fiches
     * traversaient le tamis des colonnes visibles ; les commentaires et les
     * pièces jointes non. Le fil d'une étape marquée interne et ses fichiers
     * partaient donc dans la source de la page du client, avec leurs adresses
     * de téléchargement - invisibles à l'usage, puisque l'écran ne connaissait
     * pas la fiche, et entiers pour qui lit le HTML.
     *
     * Le test ferme les deux moitiés : ce que la page porte, et ce que
     * l'adresse rend. Filtrer la charge sans fermer la route n'aurait fait que
     * cacher le lien, et un identifiant de pièce jointe est un petit entier.
     */
    public function testAnInternalStepTakesItsThreadAndItsFilesOutOfTheClientPage(): void
    {
        $space = $this->givenSpace();
        $columns = $this->columns->findForSpace($space);
        $internal = $columns[1];

        $this->givenItem($space, $internal, 'Relecture juridique interne');
        $item = $this->entityManager->getRepository(SpaceContentItem::class)
            ->findOneBy(['title' => 'Relecture juridique interne']);
        self::assertInstanceOf(SpaceContentItem::class, $item);

        // Un fil et un fichier sur cette fiche, posés par le studio.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/comments', $space->getId(), $item->getId()), [
            'body' => 'Attention au nom du dirigeant dans le paragraphe deux.',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/content/%d/attachments/upload', $space->getId(), $item->getId()),
            [],
            ['file' => $this->aJpeg('note-interne.jpg')],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $attachment = $this->entityManager->getRepository(SpaceContentAttachment::class)
            ->findOneBy(['item' => $item]);
        self::assertInstanceOf(SpaceContentAttachment::class, $attachment);

        $link = $this->givenLink($space);

        // L'étape passe en interne.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/update', $space->getId(), $internal->getId()), [
            'name' => $internal->getName(),
            'visibleToClient' => false,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $page = $this->clientPage($link);

        self::assertStringNotContainsString('Attention au nom du dirigeant', $page, 'le fil est dans la page');
        self::assertStringNotContainsString('note-interne.jpg', $page, 'le fichier est dans la page');

        // Et l'adresse, que la page ne montre plus, ne rend plus rien.
        $this->client->request('GET', sprintf(
            '/spaces/%s/%s/attachments/%d/file',
            $link->getSelector(),
            (string) $link->getPlainToken(),
            $attachment->getId(),
        ));

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), "l'adresse du fichier répond encore");
    }

    /**
     * Le droit de voir le Drive, sur les trois routes qui le servent.
     *
     * Le même 404 qu'un lien inconnu, et pas un refus explicite : dire « vous
     * pouvez lire mais pas ceci » apprend à celui qui tient une adresse fuitée
     * ce qu'il tient.
     */
    public function testALinkWithoutTheDriveRightIsRefusedOnEveryDriveRoute(): void
    {
        $space = $this->givenSpace();
        $space->setDriveFolderId('un-dossier-partage');
        $this->entityManager->flush();

        $refused = $this->links->issue(
            $space,
            'sans-drive@example.test',
            'Second lecteur',
            30,
            canApprove: true,
            canComment: true,
            canSeeDrive: false,
        );

        foreach (['', '/archive', '/un-fichier'] as $suffix) {
            $this->client->request('GET', sprintf(
                '/spaces/%s/%s/drive%s',
                $refused->getSelector(),
                (string) $refused->getPlainToken(),
                $suffix,
            ));

            self::assertSame(
                404,
                $this->client->getResponse()->getStatusCode(),
                sprintf('la route "drive%s" laisse passer', $suffix),
            );
        }
    }

    /** Et le droit accordé rend bien quelque chose, sinon le test précédent ne prouve rien. */
    public function testALinkWithTheDriveRightReachesTheListing(): void
    {
        $space = $this->givenSpace();
        $space->setDriveFolderId('un-dossier-partage');
        $this->entityManager->flush();

        $allowed = $this->links->issue(
            $space,
            'avec-drive@example.test',
            'Le client',
            30,
            canApprove: true,
            canComment: true,
            canSeeDrive: true,
        );

        $this->client->request('GET', sprintf(
            '/spaces/%s/%s/drive',
            $allowed->getSelector(),
            (string) $allowed->getPlainToken(),
        ));

        // 200 même sans intégration branchée : la route répond une liste vide,
        // ce qui est un état de l'écran et non un refus.
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** La page du client, telle qu'elle lui est servie. */
    private function clientPage(SpaceAccessLinkInterface $link): string
    {
        $this->client->request('GET', sprintf(
            '/spaces/%s/%s',
            $link->getSelector(),
            (string) $link->getPlainToken(),
        ));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Un lien, sur l'espace tel que le gestionnaire d'entités le connaît.
     *
     * Rechargé plutôt que réutilisé : les requêtes HTTP qui précèdent vident
     * le gestionnaire, et l'instance d'avant passe alors pour une entité
     * inconnue au moment d'émettre le lien.
     */
    private function givenLink(CustomerSpace $space, string $email = 'client@example.test'): SpaceAccessLinkInterface
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return $this->links->issue($fresh, $email, 'Le client', 30, true, true);
    }

    /** Le plus petit fichier que le renifleur appelle un JPEG. */
    private function aJpeg(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        ));

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client de la visibilité')
            ->setSiret('73282932000074')
            ->setContractualEmail('visibilite@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace de la visibilité',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenItem(CustomerSpace $space, SpaceContentColumn $column, string $title): void
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $column->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
