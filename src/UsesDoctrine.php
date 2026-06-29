<?php

declare(strict_types=1);

namespace Tangible\Doctrine;

use Doctrine\ORM\EntityManager;

trait UsesDoctrine {
    private ?string $doctrine_plugin_slug = null;

    /**
     * Set the plugin slug whose EntityManager this repository should use.
     * Injected per-plugin via DI (_instanceof) so multiple Doctrine-using
     * plugins in one process each query their own tables.
     *
     * We inject the *slug* (not the EntityManager instance) and resolve live
     * on every call: integration tests swap the EntityManager between runs, so
     * a cached instance would go stale. In production the instance is stable,
     * so the per-call lookup is just a cheap array hit.
     */
    public function set_doctrine_plugin_slug(string $plugin_slug): void {
        $this->doctrine_plugin_slug = $plugin_slug;
    }

    public function get_entity_manager(): EntityManager {
        return $this->doctrine_plugin_slug !== null
            ? EntityManagerFactory::getInstanceForPlugin($this->doctrine_plugin_slug)
            : EntityManagerFactory::getInstance();
    }
}
