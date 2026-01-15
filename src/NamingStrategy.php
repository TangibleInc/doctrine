<?php declare( strict_types=1 );

namespace Tangible\Doctrine;

use Doctrine\ORM\Mapping\NamingStrategy as INamingStrategy;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;

class NamingStrategy extends UnderscoreNamingStrategy implements INamingStrategy
{
  public function __construct(
    private string $tablePrefix
  ) {}

  public function classToTableName( string $className ): string
  {
    return $tablePrefix . parent::classToTableName( $className );
  }

  public function joinTableName( string $sourceEntity, string $targetEntity, string $propertyName ): string
  {
    return $tablePrefix . parent::joinTableName( $sourceEntity, $targetEntity, $propertyName );
  }
}
