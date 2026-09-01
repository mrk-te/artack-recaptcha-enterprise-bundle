<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\Tests\DependencyInjection;

use Artack\RecaptchaEnterpriseBundle\Assessment\GatewayInterface;
use Artack\RecaptchaEnterpriseBundle\DependencyInjection\ArtackRecaptchaEnterpriseExtension;
use Artack\RecaptchaEnterpriseBundle\DependencyInjection\Configuration;
use Artack\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use Artack\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterpriseValidator;
use Artack\RecaptchaEnterpriseBundle\Verifier\VerifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[CoversClass(ArtackRecaptchaEnterpriseExtension::class)]
#[CoversClass(Configuration::class)]
final class ArtackRecaptchaEnterpriseExtensionTest extends TestCase
{
    private const MINIMAL_CONFIG = [
        'site_key' => 'a-site-key',
        'project_id' => 'a-project',
        'api_key' => 'an-api-key',
    ];

    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [self::MINIMAL_CONFIG]);

        self::assertTrue($config['enabled']);
        self::assertSame(0.5, $config['min_score']);
        self::assertSame('deny', $config['on_error']);
        self::assertSame('score', $config['challenge']);
        self::assertSame('artack_recaptcha_enterprise.client', $config['http_client_service']);
    }

    /**
     * The transport is configured in one place — timeouts, proxy, TLS verification, retries — so
     * the bundle ships a scoped client instead of forwarding those settings one by one.
     */
    public function testTheScopedClientIsPrependedWithTimeouts(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('framework'));

        (new ArtackRecaptchaEnterpriseExtension())->prepend($container);

        self::assertSame([[
            'http_client' => [
                'scoped_clients' => [
                    Configuration::CLIENT_SERVICE => [
                        'base_uri' => 'https://recaptchaenterprise.googleapis.com',
                        'timeout' => 2.0,
                        'max_duration' => 5.0,
                    ],
                ],
            ],
        ]], $container->getExtensionConfig('framework'));
    }

    public function testTheGatewayUsesTheScopedClientByDefault(): void
    {
        $client = $this->load()->getDefinition('artack_recaptcha_enterprise.gateway')->getArgument(0);

        self::assertInstanceOf(Reference::class, $client);
        self::assertSame(Configuration::CLIENT_SERVICE, (string) $client);
    }

    public function testAScopedHttpClientCanBeInjected(): void
    {
        $client = $this->load(['http_client_service' => 'recaptcha.client'])
            ->getDefinition('artack_recaptcha_enterprise.gateway')
            ->getArgument(0)
        ;

        self::assertInstanceOf(Reference::class, $client);
        self::assertSame('recaptcha.client', (string) $client);
    }

    public function testAnUnknownChallengeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['challenge' => 'invisible'] + self::MINIMAL_CONFIG]);
    }

    public function testAnUnknownErrorPolicyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['on_error' => 'shrug'] + self::MINIMAL_CONFIG]);
    }

    public function testSiteKeyIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['project_id' => 'a-project']]);
    }

    public function testApiKeyCannotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['api_key' => ''] + self::MINIMAL_CONFIG]);
    }

    public function testServicesAreRegistered(): void
    {
        $container = $this->load();

        self::assertTrue($container->hasDefinition('artack_recaptcha_enterprise.gateway'));
        self::assertSame('artack_recaptcha_enterprise.gateway', (string) $container->getAlias(GatewayInterface::class));

        self::assertTrue($container->hasDefinition('artack_recaptcha_enterprise.verifier'));
        self::assertSame('artack_recaptcha_enterprise.verifier', (string) $container->getAlias(VerifierInterface::class));

        self::assertArrayHasKey(
            'validator.constraint_validator',
            $container->getDefinition(RecaptchaEnterpriseValidator::class)->getTags(),
        );
        self::assertArrayHasKey('form.type', $container->getDefinition(RecaptchaEnterpriseType::class)->getTags());
    }

    public function testParametersAreBoundFromConfiguration(): void
    {
        $container = $this->load(['min_score' => 0.7, 'enabled' => false]);

        self::assertSame('a-site-key', $container->getParameter('artack_recaptcha_enterprise.site_key'));
        self::assertSame('a-project', $container->getParameter('artack_recaptcha_enterprise.project_id'));
        self::assertSame('an-api-key', $container->getParameter('artack_recaptcha_enterprise.api_key'));
        self::assertSame(0.7, $container->getParameter('artack_recaptcha_enterprise.min_score'));
        self::assertFalse($container->getParameter('artack_recaptcha_enterprise.enabled'));
        self::assertTrue($container->getParameter('artack_recaptcha_enterprise.deny_on_error'));
    }

    public function testTheDefaultChallengeIsBound(): void
    {
        self::assertSame('checkbox', $this->load(['challenge' => 'checkbox'])->getParameter('artack_recaptcha_enterprise.challenge'));
    }

    /**
     * Page-wide, not per field: the single site_key already points at one kind of key.
     */
    public function testTheChallengeReachesTheFormType(): void
    {
        $arguments = $this->load(['challenge' => 'checkbox'])
            ->getDefinition(RecaptchaEnterpriseType::class)
            ->getArguments()
        ;

        self::assertSame('%artack_recaptcha_enterprise.challenge%', $arguments[2]);
        self::assertCount(3, $arguments);
    }

    public function testTheOutagePolicyIsBoundAsABoolean(): void
    {
        self::assertFalse($this->load(['on_error' => 'allow'])->getParameter('artack_recaptcha_enterprise.deny_on_error'));
    }

    public function testContainerCompiles(): void
    {
        $container = $this->load();
        $container->register('request_stack', RequestStack::class);
        $container->register(Configuration::CLIENT_SERVICE, MockHttpClient::class);
        $container->getDefinition('artack_recaptcha_enterprise.verifier')->setPublic(true);
        $container->compile();

        self::assertTrue($container->isCompiled());
        self::assertTrue($container->hasDefinition('artack_recaptcha_enterprise.verifier'));
    }

    public function testTwigFormThemeIsPrepended(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('twig'));

        (new ArtackRecaptchaEnterpriseExtension())->prepend($container);

        self::assertSame(
            [['form_themes' => ['@ArtackRecaptchaEnterprise/Form/recaptcha_enterprise_widget.html.twig']]],
            $container->getExtensionConfig('twig'),
        );
    }

    /**
     * Prepending onto an extension that is not registered throws.
     */
    public function testPrependIsSkippedWhenTheExtensionsAreAbsent(): void
    {
        $container = new ContainerBuilder();

        (new ArtackRecaptchaEnterpriseExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('twig'));
        self::assertSame([], $container->getExtensionConfig('framework'));
    }

    private function createExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias) {}

            public function load(array $configs, ContainerBuilder $container): void {}

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new ArtackRecaptchaEnterpriseExtension())->load([$config + self::MINIMAL_CONFIG], $container);

        return $container;
    }
}
