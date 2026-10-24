<?php

declare(strict_types=1);

namespace Modules\Tenancy\DTOs;

/** What a visitor sends to open a trial center. */
final readonly class SignUpData
{
    /**
     * @param  array{name: string, email: string, password: string}  $admin
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $slug,
        public array $admin,
        public ?string $plan = null,
        public string $billing = 'monthly',
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            kind: $data['kind'],
            name: $data['name'],
            slug: $data['slug'],
            admin: $data['admin'],
            plan: $data['plan'] ?? null,
            billing: $data['billing'] ?? 'monthly',
        );
    }
}
