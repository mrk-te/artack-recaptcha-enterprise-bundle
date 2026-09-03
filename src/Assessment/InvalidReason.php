<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment;

/**
 * Why Google refused a token.
 *
 * The cases mirror TokenProperties.invalidReason of the reCAPTCHA Enterprise API. A backed string
 * enum matches the REST representation, which serialises the enum by name; a gateway talking to a
 * transport that reports the reason differently, such as the protobuf SDK, maps onto these names.
 *
 * UNEXPECTED_ACTION is returned by Google when the token was created for another action, and is
 * also set by the Verifier when it performs that comparison itself.
 */
enum InvalidReason: string
{
    case INVALID_REASON_UNSPECIFIED = 'INVALID_REASON_UNSPECIFIED';
    case UNKNOWN_INVALID_REASON = 'UNKNOWN_INVALID_REASON';
    case MALFORMED = 'MALFORMED';
    case EXPIRED = 'EXPIRED';
    case DUPE = 'DUPE';
    case MISSING = 'MISSING';
    case BROWSER_ERROR = 'BROWSER_ERROR';
    case UNEXPECTED_ACTION = 'UNEXPECTED_ACTION';
    case KEY_MISMATCH = 'KEY_MISMATCH';
    case DOMAIN_MISMATCH = 'DOMAIN_MISMATCH';

    /**
     * Maps what the API sent. Never fails: a reason Google adds later must not break an assessment.
     *
     * REST sends the enum name as a string, which is why the cases are backed by their own name;
     * an SDK gateway would send an int and would map it here too.
     */
    public static function fromApiValue(?string $value): self
    {
        if (null === $value || '' === $value) {
            return self::INVALID_REASON_UNSPECIFIED;
        }

        return self::tryFrom($value) ?? self::UNKNOWN_INVALID_REASON;
    }
}
