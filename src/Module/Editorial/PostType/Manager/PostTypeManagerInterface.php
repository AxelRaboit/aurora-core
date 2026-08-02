<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Manager;

use Aurora\Module\Editorial\PostType\Dto\PostTypeFieldInputInterface;
use Aurora\Module\Editorial\PostType\Dto\PostTypeInputInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeFieldInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;

interface PostTypeManagerInterface
{
    public function create(PostTypeInputInterface $input): PostTypeInterface;

    public function update(PostTypeInterface $postType, PostTypeInputInterface $input): void;

    public function delete(PostTypeInterface $postType): void;

    public function createField(PostTypeInterface $postType, PostTypeFieldInputInterface $input): PostTypeFieldInterface;

    public function updateField(PostTypeFieldInterface $field, PostTypeFieldInputInterface $input): void;

    public function deleteField(PostTypeFieldInterface $field): void;

    /** @param list<int> $orderedFieldIds */
    public function reorderFields(PostTypeInterface $postType, array $orderedFieldIds): void;
}
