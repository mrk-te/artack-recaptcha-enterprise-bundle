<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Tests\Assessment;

use Codein\RecaptchaEnterpriseBundle\Assessment\Assessment;
use Codein\RecaptchaEnterpriseBundle\Assessment\AssessmentRequest;
use Codein\RecaptchaEnterpriseBundle\Assessment\Exception\AuthenticationException;
use Codein\RecaptchaEnterpriseBundle\Assessment\Exception\InvalidRequestException;
use Codein\RecaptchaEnterpriseBundle\Assessment\Exception\TransportException;
use Codein\RecaptchaEnterpriseBundle\Assessment\HttpGateway;
use Codein\RecaptchaEnterpriseBundle\Assessment\InvalidReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException as HttpClientTransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function is_string;

/**
 * Covers the half of the bundle the domain tests cannot reach: the wire format.
 *
 * @internal
 */
#[CoversClass(HttpGateway::class)]
#[CoversClass(InvalidReason::class)]
final class HttpGatewayTest extends TestCase
{
    private const PROJECT_ID = 'my-project';
    private const API_KEY = 'my-api-key';
    private const SITE_KEY = 'my-site-key';

    private string $requestedUrl = '';

    /**
     * @var array<mixed>
     */
    private array $sentBody = [];

    /**
     * @var array<mixed>
     */
    private array $sentOptions = [];

    /**
     * The transport belongs to the injected client, which the bundle declares as a scoped client.
     * Setting any of it per request here would silently defeat that configuration.
     */
    public function testTheGatewayDoesNotOverrideTransportOptions(): void
    {
        $client = $this->respondWith(['tokenProperties' => ['valid' => true]])
            ->withOptions(['timeout' => 42.0, 'max_duration' => 43.0])
        ;

        (new HttpGateway($client, self::PROJECT_ID, self::API_KEY))
            ->assess(new AssessmentRequest(self::SITE_KEY, 'a-token'))
        ;

        self::assertSame(42.0, $this->sentOptions['timeout'] ?? null);
        self::assertSame(43.0, $this->sentOptions['max_duration'] ?? null);
    }

    public function testTheRequestIsBuiltFromTheAssessmentRequest(): void
    {
        $gateway = $this->createGateway($this->respondWith([
            'tokenProperties' => ['valid' => true, 'action' => 'contact'],
            'riskAnalysis' => ['score' => 0.9],
        ]));

        $gateway->assess(new AssessmentRequest(
            self::SITE_KEY,
            'a-token',
            'contact',
            '203.0.113.7',
            'a-user-agent',
            'https://example.com/signup?step=2',
        ));

        self::assertStringStartsWith(
            'https://recaptchaenterprise.googleapis.com/v1/projects/'.self::PROJECT_ID.'/assessments',
            $this->requestedUrl,
        );
        self::assertStringContainsString('key='.self::API_KEY, $this->requestedUrl);

        self::assertSame([
            'event' => [
                'siteKey' => self::SITE_KEY,
                'token' => 'a-token',
                'expectedAction' => 'contact',
                'userIpAddress' => '203.0.113.7',
                'userAgent' => 'a-user-agent',
                'requestedUri' => 'https://example.com/signup?step=2',
            ],
        ], $this->sentBody);
    }

    public function testOptionalEventFieldsAreOmitted(): void
    {
        $gateway = $this->createGateway($this->respondWith(['tokenProperties' => ['valid' => true]]));

        $gateway->assess(new AssessmentRequest(self::SITE_KEY, 'a-token'));

        self::assertSame([
            'event' => ['siteKey' => self::SITE_KEY, 'token' => 'a-token'],
        ], $this->sentBody);
    }

    public function testAValidTokenIsMapped(): void
    {
        $payload = [
            'tokenProperties' => ['valid' => true, 'action' => 'contact'],
            'riskAnalysis' => ['score' => 0.7],
        ];

        $assessment = $this->assess($payload);

        self::assertTrue($assessment->valid);
        self::assertSame('contact', $assessment->action);
        self::assertSame(0.7, $assessment->score);
        self::assertNull($assessment->invalidReason);
        self::assertSame($payload, $assessment->raw);
    }

    public function testAnInvalidTokenCarriesItsReasonAsADomainEnum(): void
    {
        $assessment = $this->assess([
            'tokenProperties' => ['valid' => false, 'invalidReason' => 'EXPIRED'],
        ]);

        self::assertFalse($assessment->valid);
        self::assertSame(InvalidReason::EXPIRED, $assessment->invalidReason);
        self::assertNull($assessment->score);
    }

    public function testAnUnknownReasonDoesNotBreakTheAssessment(): void
    {
        $assessment = $this->assess([
            'tokenProperties' => ['valid' => false, 'invalidReason' => 'SOMETHING_GOOGLE_ADDED_LATER'],
        ]);

        self::assertSame(InvalidReason::UNKNOWN_INVALID_REASON, $assessment->invalidReason);
    }

    public function testAMissingReasonFallsBackToUnspecified(): void
    {
        $assessment = $this->assess(['tokenProperties' => ['valid' => false]]);

        self::assertSame(InvalidReason::INVALID_REASON_UNSPECIFIED, $assessment->invalidReason);
    }

    public function testAnEmptyActionIsNormalisedToNull(): void
    {
        $assessment = $this->assess(['tokenProperties' => ['valid' => true, 'action' => '']]);

        self::assertNull($assessment->action);
    }

    public function testAMissingRiskAnalysisLeavesTheScoreNull(): void
    {
        $assessment = $this->assess(['tokenProperties' => ['valid' => true, 'action' => 'contact']]);

        self::assertNull($assessment->score);
    }

    public function testAZeroScoreIsNotConfusedWithAMissingOne(): void
    {
        $assessment = $this->assess([
            'tokenProperties' => ['valid' => true],
            'riskAnalysis' => ['score' => 0],
        ]);

        self::assertSame(0.0, $assessment->score);
    }

    public function testAnEmptyPayloadYieldsAnInvalidAssessment(): void
    {
        $assessment = $this->assess([]);

        self::assertFalse($assessment->valid);
        self::assertNull($assessment->action);
        self::assertNull($assessment->score);
        self::assertSame(InvalidReason::INVALID_REASON_UNSPECIFIED, $assessment->invalidReason);
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('provideHttpErrorsAreMappedToDomainExceptionsCases')]
    public function testHttpErrorsAreMappedToDomainExceptions(int $statusCode, string $expected): void
    {
        $client = new MockHttpClient(new MockResponse(
            $this->encode(['error' => ['code' => $statusCode, 'message' => 'Nope.']]),
            ['http_code' => $statusCode],
        ));

        $this->expectException($expected);
        $this->expectExceptionMessage('Nope.');

        $this->createGateway($client)->assess(new AssessmentRequest(self::SITE_KEY, 'a-token'));
    }

    /**
     * @return iterable<string, array{int, class-string<Throwable>}>
     */
    public static function provideHttpErrorsAreMappedToDomainExceptionsCases(): iterable
    {
        yield 'bad request' => [400, InvalidRequestException::class];

        yield 'unauthenticated' => [401, AuthenticationException::class];

        yield 'forbidden' => [403, AuthenticationException::class];

        yield 'unknown project' => [404, InvalidRequestException::class];

        yield 'rate limited' => [429, TransportException::class];

        yield 'server error' => [500, TransportException::class];
    }

    public function testANetworkFailureBecomesATransportException(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new HttpClientTransportException('Connection refused.');
        });

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('could not be reached');

        $this->createGateway($client)->assess(new AssessmentRequest(self::SITE_KEY, 'a-token'));
    }

    public function testAnUndecodableBodyBecomesATransportException(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>gateway timeout</html>'));

        $this->expectException(TransportException::class);

        $this->createGateway($client)->assess(new AssessmentRequest(self::SITE_KEY, 'a-token'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assess(array $payload): Assessment
    {
        return $this->createGateway($this->respondWith($payload))
            ->assess(new AssessmentRequest(self::SITE_KEY, 'a-token'))
        ;
    }

    /**
     * Captures the outgoing request, which is the only way to see what the gateway actually sent.
     *
     * @param array<string, mixed> $payload
     */
    private function respondWith(array $payload): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($payload): MockResponse {
            self::assertSame('POST', $method);

            $this->requestedUrl = $url;

            $body = $options['body'] ?? '';
            $this->sentBody = is_string($body) ? $this->decode($body) : [];
            $this->sentOptions = $options;

            return new MockResponse($this->encode($payload));
        });
    }

    private function createGateway(MockHttpClient $client): HttpGateway
    {
        return new HttpGateway($client, self::PROJECT_ID, self::API_KEY);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
