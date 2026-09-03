<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment\Exception;

use RuntimeException;

/**
 * Google could not be reached, answered with something unusable, or asked to back off.
 *
 * Rate limiting and server errors land here on purpose: like a network failure they are transient
 * and say nothing about the token.
 */
final class TransportException extends RuntimeException implements AssessmentExceptionInterface {}
