<?php

declare(strict_types=1);

namespace App\Service\Registry;

/**
 * Raw data extracted from a single registry YAML file before domain conversion.
 */
final class RegistryEntryDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $domain,
        public readonly string $type,
        public readonly array $requires = [],
        public readonly array $args = [],
        public readonly ?array $internal = null,
        public readonly ?string $process = null,
        public readonly ?array $http = null,
        public readonly ?array $output = null,
        public readonly ?array $probe = null,
        public readonly bool $streaming = false,
        public readonly array $tags = [],
    ) {
    }
}
