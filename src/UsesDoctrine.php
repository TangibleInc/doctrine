<?php

declare(strict_types=1);

namespace Tangible\Doctrine;

use Doctrine\ORM\EntityManager;

trait UsesDoctrine {
    public function get_entity_manager(): EntityManager {
        return EntityManagerFactory::getInstance();
    }
}
