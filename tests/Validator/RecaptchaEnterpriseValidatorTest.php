<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Tests\Validator;

use Codein\RecaptchaEnterpriseBundle\Assessment\InvalidReason;
use Codein\RecaptchaEnterpriseBundle\Tests\Fixtures\FakeVerifier;
use Codein\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterprise;
use Codein\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterpriseValidator;
use Codein\RecaptchaEnterpriseBundle\Verifier\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @internal
 */
#[CoversClass(RecaptchaEnterpriseValidator::class)]
#[CoversClass(RecaptchaEnterprise::class)]
final class RecaptchaEnterpriseValidatorTest extends ConstraintValidatorTestCase
{
    private const MESSAGE = 'The captcha did not validate.';

    private FakeVerifier $verifier;
    private bool $enabled = true;
    private float $minScore = 0.5;

    public function testASuccessfulAssessmentRaisesNoViolation(): void
    {
        $this->expect(new Result(true, true, 'contact', 0.9));

        $this->validator->validate('a-token', new RecaptchaEnterprise(actionName: 'contact'));

        $this->assertNoViolation();
        self::assertSame('a-token', $this->verifier->lastToken);
        self::assertSame('contact', $this->verifier->lastExpectedAction);
    }

    public function testARefusedTokenRaisesAViolationCarryingItsReason(): void
    {
        $this->expect(Result::unverified(InvalidReason::EXPIRED));

        $this->validator->validate('a-token', new RecaptchaEnterprise());

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ reason }}', 'EXPIRED')
            ->setParameter('{{ score }}', 'null')
            ->setCause($this->verifier->nextResult)
            ->setCode(RecaptchaEnterprise::INVALID_TOKEN_ERROR)
            ->assertRaised()
        ;
    }

    public function testAScoreBelowTheGlobalThresholdRaisesALowScoreViolation(): void
    {
        $this->expect(new Result(true, true, null, 0.3));

        $this->validator->validate('a-token', new RecaptchaEnterprise());

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ reason }}', 'NONE')
            ->setParameter('{{ score }}', '0.3')
            ->setCause($this->verifier->nextResult)
            ->setCode(RecaptchaEnterprise::LOW_SCORE_ERROR)
            ->assertRaised()
        ;
    }

    public function testTheConstraintThresholdOverridesTheGlobalOne(): void
    {
        $this->expect(new Result(true, true, null, 0.6));

        $this->validator->validate('a-token', new RecaptchaEnterprise(minScore: 0.7));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ reason }}', 'NONE')
            ->setParameter('{{ score }}', '0.6')
            ->setCause($this->verifier->nextResult)
            ->setCode(RecaptchaEnterprise::LOW_SCORE_ERROR)
            ->assertRaised()
        ;
    }

    public function testAMissingScoreFailsClosed(): void
    {
        // No risk analysis at all: the threshold cannot be honoured, so the token is refused.
        $this->expect(new Result(true, true, null, null));

        $this->validator->validate('a-token', new RecaptchaEnterprise());

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ reason }}', 'NONE')
            ->setParameter('{{ score }}', 'null')
            ->setCause($this->verifier->nextResult)
            ->setCode(RecaptchaEnterprise::LOW_SCORE_ERROR)
            ->assertRaised()
        ;
    }

    public function testAMissingScoreIsAcceptedWhenTheThresholdIsZero(): void
    {
        $this->expect(new Result(true, true, null, null));

        $this->validator->validate('a-token', new RecaptchaEnterprise(minScore: 0.0));

        $this->assertNoViolation();
    }

    public function testADeniedOutageRaisesAnUnavailableViolation(): void
    {
        $this->expect(Result::unavailable('Connection refused.', false));

        $this->validator->validate('a-token', new RecaptchaEnterprise());

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ reason }}', 'NONE')
            ->setParameter('{{ score }}', 'null')
            ->setCause($this->verifier->nextResult)
            ->setCode(RecaptchaEnterprise::UNAVAILABLE_ERROR)
            ->assertRaised()
        ;
    }

    public function testAnAcceptedOutageSkipsTheScoreThreshold(): void
    {
        // There is no assessment to score, so the threshold must not refuse it after the fact.
        $this->expect(Result::unavailable('Connection refused.', true));

        $this->validator->validate('a-token', new RecaptchaEnterprise());

        $this->assertNoViolation();
    }

    public function testACustomMessageIsUsed(): void
    {
        $this->expect(Result::unverified());

        $this->validator->validate('a-token', new RecaptchaEnterprise(message: 'Nope.'));

        $this->buildViolation('Nope.')
            ->setParameter('{{ reason }}', 'MISSING')
            ->setParameter('{{ score }}', 'null')
            ->setCause($this->verifier->nextResult)
            ->setCode(RecaptchaEnterprise::INVALID_TOKEN_ERROR)
            ->assertRaised()
        ;
    }

    public function testANullTokenIsAssessedAsAnEmptyString(): void
    {
        $this->expect(Result::unverified());

        $this->validator->validate(null, new RecaptchaEnterprise());

        self::assertSame('', $this->verifier->lastToken);
    }

    public function testADisabledBundleSkipsTheAssessment(): void
    {
        $this->enabled = false;
        $this->validator = $this->createValidator();
        $this->validator->initialize($this->context);

        $this->validator->validate('a-token', new RecaptchaEnterprise());

        $this->assertNoViolation();
        self::assertNull($this->verifier->lastToken);
    }

    public function testANonStringValueIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(42, new RecaptchaEnterprise());
    }

    protected function createValidator(): RecaptchaEnterpriseValidator
    {
        // The answer is chosen per test, after setUp() has already built the validator.
        $this->verifier = new FakeVerifier(Result::unverified());

        return new RecaptchaEnterpriseValidator($this->verifier, $this->enabled, $this->minScore);
    }

    private function expect(Result $result): void
    {
        $this->verifier->setNextResult($result);
    }
}
