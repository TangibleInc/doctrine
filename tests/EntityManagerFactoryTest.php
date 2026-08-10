<?php

declare(strict_types=1);

namespace Tangible\Doctrine\Tests;

use PHPUnit\Framework\TestCase;
use Tangible\Doctrine\EntityManagerFactory;

/**
 * Registry semantics for multi-plugin EntityManager coexistence. Pure
 * in-memory: connections are lazy and auto_create_database is off, so no
 * database is touched.
 *
 * The regression under test: clearMetadataCache() used to call the global
 * reset(), so one plugin's version-bump migration evicted EVERY seeded
 * instance mid-boot. The migrating plugin then re-seeded only itself, and
 * getInstanceForPlugin()'s single-instance fallback served that survivor
 * to the other plugin — which queried the wrong table prefix and fataled
 * (wp_tangible_quiz_permission_entity, first boot after activation in CI).
 */
final class EntityManagerFactoryTest extends TestCase {
    protected function tearDown(): void {
        EntityManagerFactory::reset();
    }

    private function seed(string $slug): \Doctrine\ORM\EntityManager {
        return EntityManagerFactory::getInstance([
            'plugin_slug' => $slug,
            'entity_paths' => [__DIR__.'/Fixtures'],
            'proxy_dir' => sys_get_temp_dir().'/tangible-doctrine-test-proxies',
            'table_prefix' => 'wp_',
            'auto_create_database' => false,
        ]);
    }

    public function testClearMetadataCacheEvictsOnlyTheTargetPlugin(): void {
        $emA = $this->seed('plugin_a');
        $emB = $this->seed('plugin_b');

        EntityManagerFactory::clearMetadataCache(['plugin_slug' => 'plugin_b']);

        $this->assertSame(
            $emA,
            EntityManagerFactory::getInstanceForPlugin('plugin_a'),
            'The non-migrating plugin must keep its seeded EntityManager',
        );

        $this->assertNotSame(
            $emB,
            $this->seed('plugin_b'),
            'The migrating plugin must get a fresh EntityManager on re-seed',
        );
    }

    public function testEvictedPluginResolvesItsOwnInstanceAfterReseeding(): void {
        $this->seed('plugin_a');
        $this->seed('plugin_b');

        EntityManagerFactory::clearMetadataCache(['plugin_slug' => 'plugin_b']);
        $reseeded = $this->seed('plugin_b');

        $this->assertSame($reseeded, EntityManagerFactory::getInstanceForPlugin('plugin_b'));
    }

    public function testEvictingTheDefaultPromotesAnotherSeededInstance(): void {
        $this->seed('plugin_a');
        $emB = $this->seed('plugin_b');

        EntityManagerFactory::resetForPlugin('plugin_a');

        $this->assertSame(
            $emB,
            EntityManagerFactory::getInstance(),
            'No-arg getInstance() must not manufacture a phantom instance under the stale default slug',
        );
    }

    public function testResetForPluginOnEmptyRegistryIsANoOp(): void {
        EntityManagerFactory::resetForPlugin('never_seeded');

        $this->assertTrue(true);
    }
}
