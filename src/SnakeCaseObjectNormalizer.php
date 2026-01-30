<?php

declare(strict_types=1);

namespace Tangible\Doctrine;

use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Custom normalizer that uses snake_case method names
 * instead of the default camelCase convention.
 */
class SnakeCaseObjectNormalizer implements NormalizerInterface, DenormalizerInterface {
    public function normalize(mixed $object, ?string $format = null, array $context = []): array {
        if (!\is_object($object)) {
            return [];
        }

        $data = [];
        $class = new \ReflectionClass($object);

        // Get all properties
        foreach ($class->getProperties() as $property) {
            $propertyName = $property->getName();

            // Try snake_case getter
            $getter = 'get_'.$propertyName;
            $isGetter = 'is_'.$propertyName;

            if (method_exists($object, $getter)) {
                $value = $object->$getter();
            } elseif (method_exists($object, $isGetter)) {
                $value = $object->$isGetter();
            } else {
                // Access property directly if no getter exists
                $property->setAccessible(true);
                $value = $property->getValue($object);
            }

            // Handle nested objects and collections
            if (\is_object($value)) {
                if ($value instanceof \JsonSerializable) {
                    $value = $value->jsonSerialize();
                } elseif (method_exists($value, 'toArray')) {
                    $value = $value->toArray();
                } elseif ($value instanceof \Traversable) {
                    $value = iterator_to_array($value);
                } else {
                    $value = null; // Skip complex objects that can't be serialized
                }
            }

            $data[$propertyName] = $value;
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool {
        return \is_object($data);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): object {
        // Check if we should populate an existing object
        if (isset($context[AbstractNormalizer::OBJECT_TO_POPULATE])) {
            $object = $context[AbstractNormalizer::OBJECT_TO_POPULATE];
            $class = new \ReflectionClass($object);
        } else {
            $class = new \ReflectionClass($type);
            $object = $class->newInstanceWithoutConstructor();

            // Call constructor if it exists to initialize collections
            if ($class->getConstructor() && $class->getConstructor()->getNumberOfRequiredParameters() === 0) {
                $constructor = $class->getConstructor();
                $constructor->invoke($object);
            }
        }

        if (!\is_array($data)) {
            return $object;
        }

        foreach ($data as $key => $value) {
            // Skip the 'id' field when populating existing objects (don't change IDs)
            if ($key === 'id' && isset($context[AbstractNormalizer::OBJECT_TO_POPULATE])) {
                continue;
            }

            // Try snake_case setter
            $setter = 'set_'.$key;

            if (method_exists($object, $setter)) {
                try {
                    $object->$setter($value);
                } catch (\Throwable $e) {
                    // Skip if setter fails (e.g., type mismatch, validation error)
                    continue;
                }
            } else {
                // Try to set property directly if no setter exists
                try {
                    if ($class->hasProperty($key)) {
                        $property = $class->getProperty($key);
                        $property->setAccessible(true);
                        $property->setValue($object, $value);
                    }
                } catch (\Throwable $e) {
                    // Skip if property access fails
                    continue;
                }
            }
        }

        return $object;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool {
        return class_exists($type);
    }

    public function getSupportedTypes(?string $format): array {
        return [
            'object' => true,
            '*' => false,
        ];
    }
}
