<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment;

/**
 * What the caller wants assessed.
 *
 * The site key belongs here rather than to the gateway: it is part of the event Google assesses,
 * while the project only identifies the endpoint the gateway talks to.
 */
final class AssessmentRequest
{
    public function __construct(
        public readonly string $siteKey,
        public readonly string $token,
        public readonly ?string $expectedAction = null,
        public readonly ?string $userIpAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $requestedUri = null,
    ) {}
}
