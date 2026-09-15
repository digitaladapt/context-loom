<?php

declare(strict_types=1);

namespace App\Service\Registry;

use App\Domain\InputSpec;
use App\Domain\OutputSpec;
use App\Domain\ProbeSpec;
use App\Domain\RegistryEntry;
use Psr\Log\LoggerInterface;

/**
 * Loads and validates registry YAML files.
 *
 * SPEC §4.1: Registry entries are loaded at boot; invalid entries are excluded
 * with structured logging. Only entries with satisfied `requires` env vars
 * and valid YAML are registered.
 *
 * SPEC §4.5: Naming is no-prefix — entry name = tool name = REST path basis.
 */
final class Registry
{
    /** @var list<RegistryEntry> */
    private array $entries = [];

    /** @var list<string> Validation errors from static checks */
    private array $validationErrors = [];

    private readonly string $registryDir;

    public function __construct(
        string $registryDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->registryDir = $registryDir;
    }

    /**
     * Load all registry entries from YAML files.
     */
    public function load(): self
    {
        if (!is_dir($this->registryDir)) {
            $this->logger?->warning('Registry directory not found, skipping load.', ['dir' => $this->registryDir]);

            return $this;
        }

        $files = glob($this->registryDir . '/*.yaml') ?? [];
        sort($files);

        foreach ($files as $file) {
            $this->loadFile($file);
        }

        $this->logger?->info('Registry loaded.', [
            'entries' => \count($this->entries),
            'excluded' => \count($this->validationErrors),
        ]);

        return $this;
    }

    private function loadFile(string $file): void
    {
        $content = file_get_contents($file);
        if ($content === false) {
            $this->validationErrors[] = "Cannot read {$file}";
            $this->logger?->error('Cannot read registry file.', ['file' => $file]);

            return;
        }

        $data = yaml_parse($content);

        if (!\is_array($data)) {
            $this->validationErrors[] = "Invalid YAML in {$file}";
            $this->logger?->error('Invalid YAML in registry file.', ['file' => $file]);

            return;
        }

        // Validate required fields
        $requiredFields = ['name', 'title', 'description', 'domain', 'type'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || !\is_string($data[$field]) || '' === trim((string) $data[$field])) {
                $this->validationErrors[] = "Missing or empty field '{$field}' in {$file}";
                $this->logger?->error("Missing field '{$field}' in registry file.", ['file' => $file, 'field' => $field]);

                return;
            }
        }

        // Validate type
        if (!\in_array($data['type'], ['internal', 'process', 'http'], true)) {
            $this->validationErrors[] = "Invalid type '{$data['type']}' in {$file}. Must be internal, process, or http.";
            $this->logger?->error('Invalid type in registry file.', ['file' => $file, 'type' => $data['type']]);

            return;
        }

        // Validate name: no prefix collisions (SPEC §4.5)
        $name = $data['name'];
        if (str_starts_with($name, 'cmd_')) {
            $this->validationErrors[] = "Entry uses deprecated cmd_ prefix: {$name}";
            $this->logger?->error('Deprecated cmd_ prefix in registry file.', ['file' => $file, 'name' => $name]);

            return;
        }

        // Convert DTO → Domain
        $dto = $this->toDTO($data, $file);
        $entry = $this->toDomain($dto, $file);

        // Check requires
        foreach ($dto->requires as $envVar) {
            if ((\getenv($envVar) === false || \getenv($envVar) === '') && !isset($_ENV[$envVar]) || ($_ENV[$envVar] ?? '') === '') {
                $this->logger?->info('Excluded entry (missing requires).', [
                    'entry' => $name,
                    'missing' => $envVar,
                    'file' => $file,
                ]);

                return; // Exclude, don't error — it's a deployment choice
            }
        }

        $this->entries[] = $entry;
    }

    private function toDTO(array $data, string $file): RegistryEntryDTO
    {
        return new RegistryEntryDTO(
            name: (string) $data['name'],
            title: (string) $data['title'],
            description: (string) $data['description'],
            domain: (string) $data['domain'],
            type: (string) $data['type'],
            requires: $data['requires'] ?? [],
            args: $data['args'] ?? [],
            internal: $data['internal'] ?? null,
            process: $data['process'] ?? null,
            http: $data['http'] ?? null,
            output: $data['output'] ?? null,
            probe: $data['probe'] ?? null,
            streaming: (bool) ($data['streaming']['enabled'] ?? false),
            tags: $data['tags'] ?? [],
        );
    }

    private function toDomain(RegistryEntryDTO $dto, string $file): RegistryEntry
    {
        // Convert args → InputSpec[]
        $args = [];
        if (\is_array($dto->args)) {
            foreach ($dto->args as $arg) {
                if (!\is_array($arg)) {
                    continue;
                }
                $args[] = new InputSpec(
                    name: (string) ($arg['name'] ?? ''),
                    type: (string) ($arg['type'] ?? 'string'),
                    required: (bool) ($arg['required'] ?? false),
                    help: $arg['help'] ?? null,
                    field_name: $arg['field_name'] ?? null,
                    enum: $arg['enum'] ?? null,
                );
            }
        }

        // Convert output → OutputSpec
        $outputSpec = null;
        if (\is_array($dto->output)) {
            $outputSpec = new OutputSpec(
                mode: (string) ($dto->output['mode'] ?? 'block'),
                format: (string) ($dto->output['format'] ?? 'json'),
            );
        }

        // Convert probe → ProbeSpec
        $probeSpec = null;
        if (\is_array($dto->probe)) {
            $probeSpec = new ProbeSpec(
                level: (string) ($dto->probe['level'] ?? 'connectivity'),
                method: (string) ($dto->probe['method'] ?? 'GET'),
                url: (string) ($dto->probe['url'] ?? ''),
                timeout: (int) ($dto->probe['timeout'] ?? 3),
            );
        }

        return new RegistryEntry(
            name: $dto->name,
            title: $dto->title,
            description: $dto->description,
            domain: $dto->domain,
            type: $dto->type,
            requires: $dto->requires,
            args: $args,
            internal: $dto->internal,
            process: $dto->process,
            http: $dto->http,
            output: $outputSpec,
            probe: $probeSpec,
            streaming: $dto->streaming,
            tags: $dto->tags,
        );
    }

    /**
     * Get all loaded entries.
     *
     * @return list<RegistryEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * Get an entry by name.
     */
    public function getEntry(string $name): ?RegistryEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Get entries filtered by domain.
     *
     * @return list<RegistryEntry>
     */
    public function getEntriesByDomain(string $domain): array
    {
        return array_values(array_filter($this->entries, fn (RegistryEntry $e) => $e->domain === $domain));
    }

    /**
     * Get validation errors from the last load.
     *
     * @return list<string>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get entries grouped by domain.
     *
     * @return array<string, list<RegistryEntry>>
     */
    public function getGroupedByDomain(): array
    {
        $groups = [];
        foreach ($this->entries as $entry) {
            $groups[$entry->domain][] = $entry;
        }

        return $groups;
    }
}
