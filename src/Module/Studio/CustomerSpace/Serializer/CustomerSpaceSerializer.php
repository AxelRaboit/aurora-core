<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Serializer;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMemberInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(CustomerSpaceSerializerInterface::class)]
class CustomerSpaceSerializer implements CustomerSpaceSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(CustomerSpaceInterface $space): array
    {
        $customer = $space->getCustomer();

        return [
            'id' => $space->getId(),
            'name' => $space->getName(),
            'description' => $space->getDescription(),
            'customerId' => $customer->getId(),
            // The company's name travels, and nothing else of it does: this
            // payload reaches a page about work, not about legal identity.
            'customerName' => $customer->getLegalName(),
            // Prospect ou client, parce que la liste des espaces se separe
            // dessus : c'est l'ecran de tous les jours, et « ce sur quoi je
            // travaille » et « ce que j'essaie de decrocher » ne se lisent pas
            // dans la meme minute.
            'customerStatus' => $customer->getStatus()->value,
            'status' => $space->getStatus()->value,
            'archived' => $space->isArchived(),
            // A slot, not a colour. The page resolves it against the same
            // palette every chart and calendar uses, so it follows the theme.
            'colourSlot' => $space->getColourSlot(),
            'timezone' => $space->getTimezone(),
            'members' => $this->members($space),
            'createdAt' => $space->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return list<array{userId: int|null, name: string, email: string, role: string}> */
    private function members(CustomerSpaceInterface $space): array
    {
        return array_values(array_map(
            static function (CustomerSpaceMemberInterface $member): array {
                $user = $member->getUser();

                return [
                    'userId' => $user->getId(),
                    'name' => $user instanceof User ? $user->getName() : $user->getUserIdentifier(),
                    'email' => $user->getUserIdentifier(),
                    'role' => $member->getRole()->value,
                ];
            },
            $space->getMembers()->toArray(),
        ));
    }
}
