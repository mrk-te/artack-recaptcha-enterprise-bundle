<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Validator;

use Codein\RecaptchaEnterpriseBundle\Verifier\Result;
use Codein\RecaptchaEnterpriseBundle\Verifier\VerifierInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

use function is_string;

final class RecaptchaEnterpriseValidator extends ConstraintValidator
{
    public function __construct(
        private readonly VerifierInterface $verifier,
        private readonly bool $enabled,
        private readonly float $minScore,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RecaptchaEnterprise) {
            throw new UnexpectedTypeException($constraint, RecaptchaEnterprise::class);
        }

        if (!$this->enabled) {
            return;
        }

        if (null === $value) {
            $value = '';
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $result = $this->verifier->verify($value, $constraint->actionName);

        if (!$result->success) {
            $code = null === $result->error
                ? RecaptchaEnterprise::INVALID_TOKEN_ERROR
                : RecaptchaEnterprise::UNAVAILABLE_ERROR;

            $this->addViolation($constraint, $result, $code);

            return;
        }

        // An accepted outage carries no assessment, so there is no score to hold to a threshold.
        // The Verifier has already applied the configured policy by accepting it.
        if (null !== $result->error) {
            return;
        }

        $minScore = $constraint->minScore ?? $this->minScore;

        // A threshold of zero disables the risk analysis check, which is what checkbox keys
        // without score based protection need.
        if ($minScore <= 0.0) {
            return;
        }

        // A missing score means the assessment carried no risk analysis at all, so the threshold
        // cannot be honoured: fail closed rather than let the request through unchecked.
        if (null === $result->score || $result->score < $minScore) {
            $this->addViolation($constraint, $result, RecaptchaEnterprise::LOW_SCORE_ERROR);
        }
    }

    private function addViolation(RecaptchaEnterprise $constraint, Result $result, string $code): void
    {
        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ reason }}', $result->getInvalidReasonName() ?? 'NONE')
            ->setParameter('{{ score }}', null === $result->score ? 'null' : (string) $result->score)
            ->setCause($result)
            ->setCode($code)
            ->addViolation()
        ;
    }
}
