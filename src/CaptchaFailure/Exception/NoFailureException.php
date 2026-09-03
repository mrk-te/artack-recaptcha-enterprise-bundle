<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\CaptchaFailure\Exception;

use LogicException;

/**
 * The form carries no captcha failure. Asking for one that is not there is a programming error, not
 * a runtime condition: FinderInterface::has() answers that question without throwing.
 */
final class NoFailureException extends LogicException {}
