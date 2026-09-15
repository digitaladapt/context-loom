<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A single declarative tool definition from the registry YAML.
 *
 * SPEC §4: RegistryEntry is the heart — one YAML file → one native tool,
 * one REST endpoint, one executor. No `cmd_` prefix.
 */
final class RegistryEntry
{
    /** @var list<string> */
    public readonly array $requires;

    /** @var list<InputSpec> */
    public readonly array $args;

    public readonly ?OutputSpec $output;

    public readonly ?ProbeSpec $probe;

    /**
     * @param string $type    'internal' | 'process' | 'http'
     * @param list<string> $requires  env vars that must be set
     * @param list<InputSpec> $args     tool input parameters
     * @param array<string,mixed>|null $internal  internal handler reference (for type: internal)
     * @param string|null $process     process executable + args (for type: process)
     * @param array<string,mixed>|null $http  HTTP config (for type: http)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $domain,
        public readonly string $type,
        array $requires = [],
        array $args = [],
        public readonly ?array $internal = null,
        public readonly ?string $process = null,
        public readonly ?array $http = null,
        ?OutputSpec $output = null,
        ?ProbeSpec $probe = null,
        public readonly bool $streaming = false,
        /** @var list<string> */
        public readonly array $tags = [],
    ) {
        $this->requires = $requires;
        $this->args = $args;
        $this->output = $output;
        $this->probe = $probe;

        if (!\in_array($this->type, ['internal', 'process', 'http'], true)) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid entry type "%s" for "%s". Must be internal, process, or http.', $this->type, $this->name)
            );
        }

        // Validate naming: no prefix collisions
        if (str_starts_with($this->name, 'cmd_')) {
            throw new \InvalidArgumentException(
                \sprintf('Entry "%s" uses deprecated cmd_ prefix. Drop it.', $this->name)
            );
        }
    }

    /**
     * Derive the REST path from the tool name per SPEC §4.5.
     * Underscores → slashes. e.g. vital_pulse_list_records → /vital-pulse/list-records
     */
    public function getRestPath(): string
    {
        // domain-level entries get a dash instead of underscore
        // e.g. vital_pulse → /vital-pulse
        $segments = explode('_', $this->name);
        // Join domain words with dashes, then remaining words with slashes
        // Convention: {domain}_{verb}_{resource...}
        // First segment is the domain; the rest forms the path
        if (\count($segments) <= 1) {
            return '/' . $this->name;
        }

        $domainPart = $segments[0];
        $restParts = array_slice($segments, 1);

        // First part of the rest may also contain domain-specific grouping
        // e.g. vital_pulse_list_records → domain=vital_pulse, path=/list-records
        // We join remaining parts with dashes, then slash between domain and rest
        $restPath = implode('-', $restParts);

        return '/' . str_replace('_', '-', $domainPart) . '/' . $restPath;
    }

    /**
     * Check if all required env vars are present.
     */
    public function isConfigured(): bool
    {
        foreach ($this->requires as $envVar) {
            $value = $_ENV[$envVar] ?? getenv($envVar);
            if ($value === false || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve template variables (${ENV_VAR}) in a string.
     */
    public function resolveTemplate(string $template): string
    {
        return preg_replace_callback(
            '/\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $matches): string {
                $envVar = $matches[1];
                $value = $_ENV[$envVar] ?? getenv($envVar);

                return $value !== false && $value !== '' ? (string) $value : $matches[0];
            },
            $template
        );
    }

    /**
     * Validate the entry's arguments against its InputSpec.
     *
     * @return list<string> validation error messages (empty = valid)
     */
    public function validateArguments(array $arguments): array
    {
        $errors = [];
        $argMap = [];
        foreach ($this->args as $spec) {
            $argMap[$spec->name] = $spec;
        }

        // Check required args
        foreach ($this->args as $spec) {
            if ($spec->required && (!isset($arguments[$spec->name]) || $arguments[$spec->name] === null || $arguments[$spec->name] === '')) {
                $errors[] = "Missing required argument: {$spec->name}";
            }
        }

        // Check unknown args
        foreach ($arguments as $name => $value) {
            if (!isset($argMap[$name])) {
                $errors[] = "Unknown argument: {$name}";
            }
        }

        // Type validation
        foreach ($arguments as $name => $value) {
            if (isset($argMap[$name])) {
                $spec = $argMap[$name];
                try {
                    $casted = $spec->cast($value);
                    // If cast fails, the value is invalid
                } catch (\TypeError $e) {
                    $errors[] = "Argument {$name}: invalid type (expected {$spec->type})";
                }
            }
        }

        return $errors;
    }
}
