<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment;

/**
 * What Google answered, in terms the domain understands.
 *
 * A gateway returns this and nothing else, so no transport type ever reaches the Verifier. An
 * instance means Google answered: a refused token is a value, an unreachable API is an exception.
 */
final class Assessment
{
    /**
     * @param array<string, mixed> $raw the untouched payload, for logging and application use
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $action = null,
        public readonly ?float $score = null,
        public readonly ?InvalidReason $invalidReason = null,
        public readonly array $raw = [],
    ) {}
}
