<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Single health surface (SPEC D10): consumed by GET /health and
 * contextloom_health. Registration is config-only; health never gates
 * registration (SPEC §4.1). Phase 0 holds static state; Phase 4 adds
 * probe-driven per-provider status.
 */
final class HealthRegistry
{
    private const STATUS = 'ok';

    /**
     * @var \ArrayObject<int, array{name: string, status: string, detail: ?string, checkedAt: ?\DateTimeImmutable}>
     */
    private \ArrayObject $providers;

    public function __construct(
        iterable $providers = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->providers = new \ArrayObject();
        foreach ($providers as $provider) {
            $this->add($provider);
        }
    }

    /**
     * @param array{name: string, status: string, detail?: ?string, checkedAt?: ?\DateTimeImmutable} $provider
     */
    public function add(array $provider): void
    {
        $this->providers->append([
            'name' => $provider['name'],
            'status' => $provider['status'],
            'detail' => $provider['detail'] ?? null,
            'checkedAt' => $provider['checkedAt'] ?? null,
        ]);
    }

    /**
     * @return array{status: string, providers: array<string, mixed>, version: string}
     */
    public function snapshot(): array
    {
        $providers = [];
        foreach ($this->providers as $provider) {
            $providers[$provider['name']] = [
                'status' => $provider['status'],
                'detail' => $provider['detail'],
                'checked_at' => $provider['checkedAt']?->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'status' => self::STATUS,
            'providers' => $providers,
            'version' => '0.1.0-dev',
        ];
    }
}
