<?php

declare(strict_types=1);

namespace App\MCP;

use App\Domain\InputSpec;
use App\Domain\RegistryEntry;

/**
 * Converts a RegistryEntry into an MCP tool definition (JSON schema).
 *
 * SPEC §3.1: Registry entries register as native MCP tools.
 * Tool schema = JSON Schema built from InputSpec[].
 */
final class ToolFactory
{
    /**
     * Build an MCP tool definition array from a RegistryEntry.
     *
     * @return array<string, mixed>
     */
    public function toToolDefinition(RegistryEntry $entry): array
    {
        // Build properties map
        $properties = [];
        $required = [];

        foreach ($entry->args as $arg) {
            $properties[$arg->name] = $arg->toSchemaProperty();

            if ($arg->required) {
                $required[] = $arg->name;
            }
        }

        // Schema: never publish empty containers (see schema convention in ContextLoomServer)
        $schema = ['type' => 'object'];

        if (!empty($properties)) {
            $schema['properties'] = $properties;
        }

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return [
            'name' => $entry->name,
            'title' => $entry->title,
            'description' => $this->enrichDescription($entry),
            'inputSchema' => $schema,
        ];
    }

    /**
     * Enrich the tool description with type-specific context.
     */
    private function enrichDescription(RegistryEntry $entry): string
    {
        $desc = $entry->description;

        if ($entry->type === 'http') {
            $method = $entry->http['method'] ?? 'GET';
            $url = $entry->http['url'] ?? '';
            $desc .= " [HTTP {$method} " . $url . "]";

            if (!empty($entry->requires)) {
                $desc .= " (requires: " . implode(', ', $entry->requires) . ')';
            }
        } elseif ($entry->type === 'process') {
            $desc .= " [process: {$entry->process}]";
        }

        if ($entry->streaming) {
            $desc .= ' [streaming]';
        }

        return $desc;
    }
}
