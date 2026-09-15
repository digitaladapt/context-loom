<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Defines a single tool input parameter.
 *
 * SPEC §4: Registry entries carry InputSpec[] in their `args` block.
 * Used for MCP tool schema generation and argument validation.
 */
final class InputSpec
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly ?string $help = null,
        public readonly ?string $field_name = null,
        /** @var list<string>|null */
        public readonly ?array $enum = null,
    ) {
        if (!\in_array($this->type, ['string', 'number', 'integer', 'boolean', 'array', 'object'], true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported InputSpec type: "%s". Must be string, number, integer, boolean, array, or object.', $this->type));
        }
    }

    /**
     * Build a JSON schema property fragment for this input.
     */
    public function toSchemaProperty(): array
    {
        $property = [
            'type' => $this->type,
        ];

        if (null !== $this->help) {
            $property['description'] = $this->help;
        }

        if (null !== $this->enum) {
            $property['enum'] = $this->enum;
        }

        return $property;
    }

    /**
     * Convert a raw input value to the correct PHP type.
     */
    public function cast(mixed $value): mixed
    {
        if (null === $value && !$this->required) {
            return null;
        }

        return match ($this->type) {
            'string' => (string) $value,
            'integer' => (int) $value,
            'number' => (float) $value,
            'boolean' => (bool) $value,
            'array', 'object' => $value,
            default => $value,
        };
    }
}
