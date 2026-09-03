<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\Assessment;

use Artack\RecaptchaEnterpriseBundle\Assessment\Exception\AuthenticationException;
use Artack\RecaptchaEnterpriseBundle\Assessment\Exception\InvalidRequestException;
use Artack\RecaptchaEnterpriseBundle\Assessment\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Talks to the reCAPTCHA Enterprise REST API with an API key.
 *
 * The bundle makes a single unary call, so the official SDK would only add a protobuf and gRPC
 * stack — and its google/gax dependency pins ramsey/uuid ^4, which cannot be installed next to
 * applications held at ramsey/uuid 3.x. A second gateway can be added behind GatewayInterface
 * without the domain noticing.
 */
final class HttpGateway implements GatewayInterface
{
    private const ENDPOINT = 'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments';

    /**
     * Everything about the transport — timeouts, proxy, TLS verification, retries, DNS — belongs
     * to the injected client, which the bundle declares as a scoped client so an application can
     * reconfigure all of it in one place instead of through a handful of forwarded settings.
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectId,
        private readonly string $apiKey,
    ) {}

    public function assess(AssessmentRequest $request): Assessment
    {
        try {
            $response = $this->httpClient->request('POST', sprintf(self::ENDPOINT, $this->projectId), [
                'query' => ['key' => $this->apiKey],
                'json' => ['event' => $this->createEvent($request)],
            ]);

            $statusCode = $response->getStatusCode();
            // Passing false keeps the decoding of error bodies, which carry Google's message.
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $exception) {
            throw new TransportException(
                sprintf('The reCAPTCHA Enterprise API could not be reached: %s', $exception->getMessage()),
                previous: $exception,
            );
        }

        if (200 !== $statusCode) {
            throw $this->createException($statusCode, $payload);
        }

        return $this->createAssessment($payload);
    }

    /**
     * @return array<string, string>
     */
    private function createEvent(AssessmentRequest $request): array
    {
        $event = [
            'siteKey' => $request->siteKey,
            'token' => $request->token,
            'expectedAction' => $request->expectedAction,
            'userIpAddress' => $request->userIpAddress,
            'userAgent' => $request->userAgent,
            'requestedUri' => $request->requestedUri,
        ];

        return array_filter($event, static fn (?string $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createAssessment(array $payload): Assessment
    {
        $tokenProperties = $payload['tokenProperties'] ?? [];
        $tokenProperties = is_array($tokenProperties) ? $tokenProperties : [];

        $riskAnalysis = $payload['riskAnalysis'] ?? [];
        $riskAnalysis = is_array($riskAnalysis) ? $riskAnalysis : [];

        $valid = true === ($tokenProperties['valid'] ?? false);

        // An action is either present or absent; an empty one carries no more meaning than none.
        $action = $tokenProperties['action'] ?? null;
        $action = (is_string($action) && '' !== $action) ? $action : null;

        $invalidReason = null;

        if (!$valid) {
            $rawReason = $tokenProperties['invalidReason'] ?? null;
            $invalidReason = InvalidReason::fromApiValue(is_string($rawReason) ? $rawReason : null);
        }

        // No risk analysis at all is a different fact from a score of zero, so it stays null.
        $score = $riskAnalysis['score'] ?? null;
        $score = is_numeric($score) ? (float) $score : null;

        return new Assessment($valid, $action, $score, $invalidReason, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createException(int $statusCode, array $payload): Throwable
    {
        $error = $payload['error'] ?? [];
        $message = is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : 'no message';

        $message = sprintf('The reCAPTCHA Enterprise API answered %d: %s', $statusCode, $message);

        return match (true) {
            401 === $statusCode, 403 === $statusCode => new AuthenticationException($message),
            400 === $statusCode, 404 === $statusCode => new InvalidRequestException($message),
            // Rate limiting and server faults are transient, so they are reported as such.
            default => new TransportException($message),
        };
    }
}
