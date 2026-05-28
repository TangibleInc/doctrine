<?php

declare(strict_types=1);

namespace Tangible\Doctrine\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Minimal fixture entity. Maps to `wp_tngtest_item_entity`. The test creates
 * the foreign key to the resource table directly in SQL — only the table name
 * is needed from the mapping.
 */
#[ORM\Entity]
class ItemEntity {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int {
        return $this->id;
    }
}
