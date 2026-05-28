<?php

declare(strict_types=1);

namespace Tangible\Doctrine\Tests\Fixtures\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two-statement migration used to exercise the partial-apply recovery path.
 * Bare-prefixed table names (tngtest_) are rewritten to the full prefix
 * (wp_tngtest_) by TablePrefixMiddleware, mirroring real migrations.
 */
final class Version29990101000000 extends AbstractMigration {
    public function getDescription(): string {
        return 'Fixture: two CREATE TABLE statements for idempotent-replay testing.';
    }

    public function up(Schema $schema): void {
        $this->addSql('CREATE TABLE tngtest_replay_a (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tngtest_replay_b (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void {
        $this->addSql('DROP TABLE tngtest_replay_a');
        $this->addSql('DROP TABLE tngtest_replay_b');
    }
}
