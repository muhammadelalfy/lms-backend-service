<?php

declare(strict_types=1);

namespace Modules\Tenancy\DTOs;

/** What a plan request carries, from the landing form or from a center's admin. */
final readonly class PlanRequestData
{
    public function __construct(
        public string $type,
        public string $name,
        public string $message,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $orgName = null,
        public ?string $audience = null,
        public ?int $studentsEstimate = null,
        public ?string $planSlug = null,
        public ?string $schoolId = null,
        public ?string $ipHash = null,
        public ?string $userAgent = null,
    ) {}

    public static function custom(array $validated, ?string $ip, ?string $userAgent): self
    {
        return new self(
            type: 'custom',
            name: $validated['name'],
            message: $validated['message'],
            phone: $validated['phone'],
            email: $validated['email'] ?? null,
            orgName: $validated['org_name'] ?? null,
            audience: $validated['audience'],
            studentsEstimate: isset($validated['students_estimate']) ? (int) $validated['students_estimate'] : null,
            planSlug: $validated['plan_slug'] ?? null,
            ipHash: self::hashIp($ip),
            userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        );
    }

    /** The visitor's address is kept only as a keyed hash: enough to spot abuse, never the address itself. */
    public static function hashIp(?string $ip): ?string
    {
        return $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /** @return array<string, mixed> the columns of a plan_requests row */
    public function toRow(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'message' => $this->message,
            'phone' => $this->phone,
            'email' => $this->email,
            'org_name' => $this->orgName,
            'audience' => $this->audience,
            'students_estimate' => $this->studentsEstimate,
            'plan_slug' => $this->planSlug,
            'school_id' => $this->schoolId,
            'ip_hash' => $this->ipHash,
            'user_agent' => $this->userAgent,
        ];
    }
}
