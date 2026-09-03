<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\CaptchaFailure;

use Artack\RecaptchaEnterpriseBundle\Assessment\InvalidReason;
use Artack\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterprise;
use Artack\RecaptchaEnterpriseBundle\Verifier\Result;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Why a captcha field was refused, as a controller needs it: the violation the validator raised and
 * the assessment it was raised from, side by side.
 *
 * The three questions below read the violation code rather than the result, because a low score
 * cannot be told from an accepted one by looking at the assessment alone: the threshold lives in
 * the constraint, which the result never sees.
 */
final class Failure
{
    public function __construct(
        public readonly ConstraintViolation $violation,
        public readonly Result $result,
    ) {}

    /**
     * The assessment the violation was raised from, for anything the shortcuts below do not cover —
     * `$result->raw` holds Google's untouched answer.
     */
    public function getResult(): Result
    {
        return $this->result;
    }

    /**
     * The violation as the validator raised it, for the message parameters or the constraint.
     */
    public function getViolation(): ConstraintViolation
    {
        return $this->violation;
    }

    /**
     * One of the RecaptchaEnterprise::*_ERROR constants.
     */
    public function getCode(): ?string
    {
        return $this->violation->getCode();
    }

    /**
     * No token was submitted, or Google refused the one that was. See getInvalidReason().
     */
    public function isInvalidToken(): bool
    {
        return RecaptchaEnterprise::INVALID_TOKEN_ERROR === $this->getCode();
    }

    /**
     * The token was genuine, but the risk analysis stayed below the threshold.
     */
    public function isLowScore(): bool
    {
        return RecaptchaEnterprise::LOW_SCORE_ERROR === $this->getCode();
    }

    /**
     * Google could not be asked at all. Only reachable under `on_error: deny`, since `allow` refuses
     * nothing — the token is then accepted and there is no failure to find.
     */
    public function isUnavailable(): bool
    {
        return RecaptchaEnterprise::UNAVAILABLE_ERROR === $this->getCode();
    }

    /**
     * Null whenever the assessment carried no risk analysis, which is the normal case for a checkbox
     * key and the reason isLowScore() never fires there.
     */
    public function getScore(): ?float
    {
        return $this->result->score;
    }

    public function getInvalidReason(): ?InvalidReason
    {
        return $this->result->invalidReason;
    }

    /**
     * The translated message, which is what the visitor was already shown.
     */
    public function getMessage(): string
    {
        return (string) $this->violation->getMessage();
    }

    /**
     * Where the field sits in the form, e.g. "children[recaptchaToken].data".
     */
    public function getPropertyPath(): string
    {
        return $this->violation->getPropertyPath();
    }
}
