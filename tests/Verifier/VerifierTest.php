<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Tests\Verifier;

use Codein\RecaptchaEnterpriseBundle\Assessment\Assessment;
use Codein\RecaptchaEnterpriseBundle\Assessment\Exception\TransportException;
use Codein\RecaptchaEnterpriseBundle\Assessment\InvalidReason;
use Codein\RecaptchaEnterpriseBundle\Tests\Fixtures\FakeGateway;
use Codein\RecaptchaEnterpriseBundle\Verifier\Result;
use Codein\RecaptchaEnterpriseBundle\Verifier\Verifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[CoversClass(Verifier::class)]
#[CoversClass(Result::class)]
final class VerifierTest extends TestCase
{
    private const SITE_KEY = 'my-site-key';

    public function testAValidTokenWithAMatchingActionSucceeds(): void
    {
        $gateway = new FakeGateway(new Assessment(true, 'contact', 0.9));

        $result = $this->createVerifier($gateway)->verify('a-token', 'contact');

        self::assertTrue($result->success);
        self::assertTrue($result->valid);
        self::assertSame('contact', $result->action);
        self::assertSame(0.9, $result->score);
        self::assertNull($result->invalidReason);
        self::assertNull($result->error);
    }

    public function testTheRequestCarriesTheSiteKeyAndTheExpectedAction(): void
    {
        $gateway = new FakeGateway(new Assessment(true, 'contact'));

        $this->createVerifier($gateway)->verify('a-token', 'contact');

        self::assertNotNull($gateway->lastRequest);
        self::assertSame(self::SITE_KEY, $gateway->lastRequest->siteKey);
        self::assertSame('a-token', $gateway->lastRequest->token);
        self::assertSame('contact', $gateway->lastRequest->expectedAction);
    }

    public function testTheClientDataComesFromTheCurrentRequest(): void
    {
        $gateway = new FakeGateway(new Assessment(true));

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://example.com/signup?step=2', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'a-user-agent',
        ]));

        $this->createVerifier($gateway, $requestStack)->verify('a-token');

        self::assertNotNull($gateway->lastRequest);
        self::assertSame('203.0.113.7', $gateway->lastRequest->userIpAddress);
        self::assertSame('a-user-agent', $gateway->lastRequest->userAgent);
        self::assertSame('https://example.com/signup?step=2', $gateway->lastRequest->requestedUri);
    }

    public function testWithoutARequestStackTheEventCarriesNoClientData(): void
    {
        $gateway = new FakeGateway(new Assessment(true));

        $this->createVerifier($gateway)->verify('a-token');

        self::assertNotNull($gateway->lastRequest);
        self::assertNull($gateway->lastRequest->userIpAddress);
        self::assertNull($gateway->lastRequest->userAgent);
        self::assertNull($gateway->lastRequest->requestedUri);
    }

    public function testAnEmptyTokenIsRefusedWithoutCallingTheGateway(): void
    {
        $gateway = new FakeGateway(new Assessment(true, null, 0.9));

        $result = $this->createVerifier($gateway)->verify('');

        self::assertFalse($result->success);
        self::assertFalse($result->valid);
        self::assertSame(InvalidReason::MISSING, $result->invalidReason);
        self::assertSame(0, $gateway->calls);
    }

    public function testAnInvalidTokenKeepsTheReasonFromGoogle(): void
    {
        $gateway = new FakeGateway(new Assessment(false, null, null, InvalidReason::EXPIRED));

        $result = $this->createVerifier($gateway)->verify('a-token');

        self::assertFalse($result->success);
        self::assertFalse($result->valid);
        self::assertSame(InvalidReason::EXPIRED, $result->invalidReason);
        self::assertSame('EXPIRED', $result->getInvalidReasonName());
    }

    public function testAValidTokenMintedForAnotherActionIsRefused(): void
    {
        $gateway = new FakeGateway(new Assessment(true, 'newsletter', 0.9));

        $result = $this->createVerifier($gateway)->verify('a-token', 'contact');

        // Google said the token is fine; it is the action mismatch that refuses it.
        self::assertFalse($result->success);
        self::assertTrue($result->valid);
        self::assertSame(InvalidReason::UNEXPECTED_ACTION, $result->invalidReason);
    }

    public function testWithoutAnExpectedActionTheActionIsNotChecked(): void
    {
        $gateway = new FakeGateway(new Assessment(true, 'newsletter', 0.9));

        $result = $this->createVerifier($gateway)->verify('a-token');

        self::assertTrue($result->success);
    }

    public function testAnUnreachableApiIsReportedAsSuchAndNotAsAnInvalidToken(): void
    {
        $gateway = new FakeGateway(new TransportException('Connection refused.'));

        $result = $this->createVerifier($gateway)->verify('a-token');

        self::assertFalse($result->success);
        self::assertSame('Connection refused.', $result->error);
        // The token was never assessed, so claiming a reason for it would be a lie.
        self::assertNull($result->invalidReason);
    }

    public function testAnUnreachableApiIsLogged(): void
    {
        // psr/log 1.x, 2.x and 3.x declare log() differently, so a mock is the portable fake here.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->createVerifier(new FakeGateway(new TransportException('Boom.')), logger: $logger)->verify('a-token');
    }

    public function testTheOutagePolicyCanAcceptTheToken(): void
    {
        $gateway = new FakeGateway(new TransportException('Connection refused.'));

        $result = $this->createVerifier($gateway, denyOnError: false)->verify('a-token');

        self::assertTrue($result->success);
        self::assertFalse($result->valid);
        self::assertSame('Connection refused.', $result->error);
    }

    /**
     * The verifier is a container service, so holding the last result would leak it across a
     * long-running worker's requests and make two fields in one request ambiguous.
     */
    public function testTheVerifierKeepsNoStateBetweenCalls(): void
    {
        $mutable = array_filter(
            (new ReflectionClass(Verifier::class))->getProperties(),
            static fn (ReflectionProperty $property): bool => !$property->isReadOnly(),
        );

        self::assertSame([], $mutable);
    }

    private function createVerifier(
        FakeGateway $gateway,
        ?RequestStack $requestStack = null,
        ?LoggerInterface $logger = null,
        bool $denyOnError = true,
    ): Verifier {
        return new Verifier($gateway, self::SITE_KEY, $requestStack, $logger, $denyOnError);
    }
}
