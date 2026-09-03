<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Verifier;

use Codein\RecaptchaEnterpriseBundle\Assessment\Assessment;
use Codein\RecaptchaEnterpriseBundle\Assessment\AssessmentRequest;
use Codein\RecaptchaEnterpriseBundle\Assessment\Exception\AssessmentExceptionInterface;
use Codein\RecaptchaEnterpriseBundle\Assessment\GatewayInterface;
use Codein\RecaptchaEnterpriseBundle\Assessment\InvalidReason;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides what an assessment means, so a gateway only ever has to translate. The score threshold
 * is the one judgement made elsewhere: it is per-constraint, so the validator owns it.
 *
 * Stateless on purpose — see VerifierInterface::verify().
 */
final class Verifier implements VerifierInterface
{
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly string $siteKey,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $denyOnError = true,
    ) {}

    public function verify(string $token, ?string $expectedAction = null): Result
    {
        // Nothing to assess, and Google would only be asked to confirm it.
        if ('' === $token) {
            return Result::unverified(InvalidReason::MISSING);
        }

        try {
            $assessment = $this->gateway->assess($this->createRequest($token, $expectedAction));
        } catch (AssessmentExceptionInterface $exception) {
            $this->logger?->error('The reCAPTCHA Enterprise assessment failed: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            // An unreachable API says nothing about the token, so it is reported as its own fact
            // rather than disguised as an invalid one.
            return Result::unavailable($exception->getMessage(), !$this->denyOnError);
        }

        return $this->createResult($assessment, $expectedAction);
    }

    private function createRequest(string $token, ?string $expectedAction): AssessmentRequest
    {
        $request = $this->requestStack?->getCurrentRequest();

        return new AssessmentRequest(
            $this->siteKey,
            $token,
            $expectedAction,
            // Behind a proxy this is the proxy unless framework.trusted_proxies is configured,
            // which would score every visitor from one address and degrade the risk analysis.
            $request?->getClientIp(),
            $request?->headers->get('User-Agent'),
            $request?->getUri(),
        );
    }

    private function createResult(Assessment $assessment, ?string $expectedAction): Result
    {
        $invalidReason = $assessment->invalidReason;

        // A token minted for another action could otherwise be replayed here. Google reports this
        // itself when it is given the expected action, but the comparison is cheap and the bundle
        // must not depend on the gateway having sent it.
        $actionMatches = null === $expectedAction || $assessment->action === $expectedAction;

        if ($assessment->valid && !$actionMatches) {
            $invalidReason = InvalidReason::UNEXPECTED_ACTION;
        }

        return new Result(
            $assessment->valid && $actionMatches,
            $assessment->valid,
            $assessment->action,
            $assessment->score,
            $invalidReason,
            $assessment->raw,
        );
    }
}
