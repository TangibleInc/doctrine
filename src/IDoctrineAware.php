<?php

declare(strict_types=1);

namespace Tangible\Doctrine;

use Doctrine\ORM\EntityManager;

interface IDoctrineAware {
    public function get_entity_manager(): EntityManager;
}
