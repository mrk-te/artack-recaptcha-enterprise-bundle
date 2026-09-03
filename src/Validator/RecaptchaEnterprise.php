<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute]
final class RecaptchaEnterprise extends Constraint
{
    public const INVALID_TOKEN_ERROR = '6bdc8b9e-2d9a-4d9f-9d6a-2a2b1c3d4e5f';
    public const LOW_SCORE_ERROR = '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';
    public const UNAVAILABLE_ERROR = 'c4a1f0e2-9b3d-4c5e-8a7f-1d2e3b4c5a6d';

    protected const ERROR_NAMES = [
        self::INVALID_TOKEN_ERROR => 'INVALID_TOKEN_ERROR',
        self::LOW_SCORE_ERROR => 'LOW_SCORE_ERROR',
        self::UNAVAILABLE_ERROR => 'UNAVAILABLE_ERROR',
    ];

    public string $message = 'The captcha did not validate.';

    public ?float $minScore = null;
    public ?string $actionName = null;

    public function __construct(?float $minScore = null, ?string $actionName = null, ?string $message = null, ?array $groups = null, $payload = null)
    {
        parent::__construct([], $groups, $payload);

        $this->minScore = $minScore;
        $this->actionName = $actionName;
        $this->message = $message ?? $this->message;
    }

    public function validatedBy(): string
    {
        return self::class.'Validator';
    }
}
