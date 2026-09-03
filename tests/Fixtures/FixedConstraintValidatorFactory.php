<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Tests\Fixtures;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;

/**
 * Hands out one prepared validator and instantiates every other one by name, as Symfony's own
 * factory does. The bundle's validator cannot go through that default: it takes a verifier and the
 * two configuration values, and a form validated through the validator component also needs the
 * FormValidator to be resolvable.
 */
final class FixedConstraintValidatorFactory implements ConstraintValidatorFactoryInterface
{
    /**
     * @var array<string, ConstraintValidatorInterface>
     */
    private array $validators = [];

    public function __construct(string $for, ConstraintValidatorInterface $validator)
    {
        $this->validators[$for] = $validator;
    }

    public function getInstance(Constraint $constraint): ConstraintValidatorInterface
    {
        /** @var class-string<ConstraintValidatorInterface> $className */
        $className = $constraint->validatedBy();

        return $this->validators[$className] ??= new $className();
    }
}
