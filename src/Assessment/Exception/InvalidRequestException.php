<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment\Exception;

use RuntimeException;

/**
 * Google rejected the request itself, e.g. an unknown project or a malformed event.
 *
 * A configuration fault, not a transient one — retrying cannot fix it.
 */
final class InvalidRequestException extends RuntimeException implements AssessmentExceptionInterface {}
