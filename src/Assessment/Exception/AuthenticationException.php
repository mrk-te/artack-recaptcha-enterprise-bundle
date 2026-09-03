<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment\Exception;

use RuntimeException;

/**
 * Google refused the caller: a missing, wrong or unauthorised API key.
 *
 * A configuration fault, not a transient one — retrying cannot fix it.
 */
final class AuthenticationException extends RuntimeException implements AssessmentExceptionInterface {}
