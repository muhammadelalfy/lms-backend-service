<?php

declare(strict_types=1);

namespace Modules\Courses\DTOs;

final readonly class SubjectData
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromValidated(array $validated): self
    {
        return new self($validated);
    }
}
