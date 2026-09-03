<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Verifier;

interface VerifierInterface
{
    /**
     * The result is returned rather than kept, so two fields validated in one request cannot read
     * each other's verdict and a worker cannot carry one over to the next request.
     */
    public function verify(string $token, ?string $expectedAction = null): Result;
}
