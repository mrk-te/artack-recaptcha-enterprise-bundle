<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\Tests\CaptchaFailure;

use Artack\RecaptchaEnterpriseBundle\Assessment\InvalidReason;
use Artack\RecaptchaEnterpriseBundle\CaptchaFailure\Exception\NoFailureException;
use Artack\RecaptchaEnterpriseBundle\CaptchaFailure\Failure;
use Artack\RecaptchaEnterpriseBundle\CaptchaFailure\Finder;
use Artack\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use Artack\RecaptchaEnterpriseBundle\Tests\Fixtures\FakeVerifier;
use Artack\RecaptchaEnterpriseBundle\Tests\Fixtures\FixedConstraintValidatorFactory;
use Artack\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterprise;
use Artack\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterpriseValidator;
use Artack\RecaptchaEnterpriseBundle\Verifier\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/**
 * Drives the real validator through a real form, so the violations under test are the ones the
 * bundle actually raises rather than hand-built ones.
 *
 * @internal
 */
#[CoversClass(Finder::class)]
#[CoversClass(Failure::class)]
#[CoversClass(NoFailureException::class)]
final class FinderTest extends TestCase
{
    private const SITE_KEY = 'a-site-key';

    private Finder $finder;

    protected function setUp(): void
    {
        $this->finder = new Finder();
    }

    public function testALowScoreIsFoundFromTheRootForm(): void
    {
        $form = $this->submit(new Result(true, true, 'contact', 0.1));

        self::assertTrue($this->finder->has($form));

        $failure = $this->finder->get($form);

        self::assertTrue($failure->isLowScore());
        self::assertFalse($failure->isInvalidToken());
        self::assertFalse($failure->isUnavailable());
        self::assertSame(RecaptchaEnterprise::LOW_SCORE_ERROR, $failure->getCode());
        self::assertSame(0.1, $failure->getScore());
        self::assertNull($failure->getInvalidReason());
        self::assertSame('The captcha did not validate.', $failure->getMessage());
        self::assertSame($failure->result, $failure->getResult());
        self::assertSame($failure->violation, $failure->getViolation());
        self::assertStringContainsString('recaptchaToken', $failure->getPropertyPath());
    }

    public function testARefusedTokenIsReportedWithItsReason(): void
    {
        $form = $this->submit(Result::unverified(InvalidReason::DUPE));

        $failure = $this->finder->get($form);

        self::assertTrue($failure->isInvalidToken());
        self::assertSame(InvalidReason::DUPE, $failure->getInvalidReason());
        self::assertNull($failure->getScore());
    }

    public function testADeniedOutageIsReportedAsUnavailable(): void
    {
        $form = $this->submit(Result::unavailable('Connection refused.', false));

        $failure = $this->finder->get($form);

        self::assertTrue($failure->isUnavailable());
        self::assertSame(RecaptchaEnterprise::UNAVAILABLE_ERROR, $failure->getCode());
        self::assertSame('Connection refused.', $failure->result->error);
    }

    /**
     * The finder never looks at the challenge: it reads what the validator recorded, and that
     * validator has one code path. A checkbox key carries no risk analysis and runs with
     * min_score: 0, so an expired token is what a failure looks like there.
     */
    public function testACheckboxFailureIsFoundJustTheSame(): void
    {
        $form = $this->submit(
            Result::unverified(InvalidReason::EXPIRED),
            minScore: 0.0,
            challenge: RecaptchaEnterpriseType::CHALLENGE_CHECKBOX,
        );

        $failure = $this->finder->get($form);

        self::assertTrue($failure->isInvalidToken());
        self::assertFalse($failure->isLowScore());
        self::assertSame(InvalidReason::EXPIRED, $failure->getInvalidReason());
        self::assertNull($failure->getScore());
    }

    public function testAnAcceptedTokenLeavesNothingToFind(): void
    {
        $form = $this->submit(new Result(true, true, 'contact', 0.9));

        self::assertTrue($form->isValid());
        self::assertFalse($this->finder->has($form));
    }

    public function testGetThrowsWhenThereIsNoFailure(): void
    {
        $form = $this->submit(new Result(true, true, 'contact', 0.9));

        $this->expectException(NoFailureException::class);
        $this->expectExceptionMessage('carries no reCAPTCHA Enterprise failure');

        $this->finder->get($form);
    }

    public function testAnUnrelatedViolationIsNotMistakenForACaptchaFailure(): void
    {
        // The captcha passes; the other field does not.
        $form = $this->submit(new Result(true, true, 'contact', 0.9), name: '');

        self::assertFalse($form->isValid());
        self::assertFalse($this->finder->has($form));
    }

    public function testAnErrorWithoutAConstraintViolationIsSkipped(): void
    {
        $form = $this->submit(new Result(true, true, 'contact', 0.9));
        $form->addError(new FormError('Something else went wrong.'));

        self::assertFalse($this->finder->has($form));
    }

    public function testTheFieldItselfCanBePassedIn(): void
    {
        $form = $this->submit(Result::unverified());

        self::assertTrue($this->finder->has($form->get('recaptchaToken')));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function submit(
        Result $result,
        float $minScore = 0.5,
        string $challenge = RecaptchaEnterpriseType::CHALLENGE_SCORE,
        string $name = 'Ada',
    ): FormInterface {
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new FixedConstraintValidatorFactory(
                RecaptchaEnterpriseValidator::class,
                new RecaptchaEnterpriseValidator(new FakeVerifier($result), true, $minScore),
            ))
            ->getValidator()
        ;

        $form = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->addType(new RecaptchaEnterpriseType(self::SITE_KEY, true, $challenge))
            ->getFormFactory()
            ->createBuilder(FormType::class)
            ->add('name', TextType::class, ['constraints' => [new NotBlank()]])
            ->add('recaptchaToken', RecaptchaEnterpriseType::class, [
                'constraints' => [new RecaptchaEnterprise(actionName: 'contact')],
            ])
            ->getForm()
        ;

        $form->submit(['name' => $name, 'recaptchaToken' => 'a-token']);

        return $form;
    }
}
