<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment\Exception;

use Throwable;

/**
 * No assessment could be obtained.
 *
 * This never means "the token is invalid" — that is an Assessment with valid = false. It means the
 * question could not be asked or was not answered, which is a different fact and deserves a
 * different reaction.
 */
interface AssessmentExceptionInterface extends Throwable {}
