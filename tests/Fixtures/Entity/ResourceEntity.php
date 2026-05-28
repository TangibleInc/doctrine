<?php

declare(strict_types=1);

namespace Tangible\Doctrine\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Minimal fixture entity. With plugin_slug "tngtest" + table_prefix "wp_" the
 * naming strategy maps this to `wp_tngtest_resource_entity`.
 */
#[ORM\Entity]
class ResourceEntity {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int {
        return $this->id;
    }
}
