<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Tests\Fixtures;

use Codein\RecaptchaEnterpriseBundle\Verifier\Result;
use Codein\RecaptchaEnterpriseBundle\Verifier\VerifierInterface;

final class FakeVerifier implements VerifierInterface
{
    public ?string $lastToken = null;
    public ?string $lastExpectedAction = null;

    public function __construct(
        public Result $nextResult,
    ) {}

    public function setNextResult(Result $result): void
    {
        $this->nextResult = $result;
    }

    public function verify(string $token, ?string $expectedAction = null): Result
    {
        $this->lastToken = $token;
        $this->lastExpectedAction = $expectedAction;

        return $this->nextResult;
    }
}
