<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class ArtackRecaptchaEnterpriseExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{enabled: bool, site_key: string, project_id: string, api_key: string, min_score: float, challenge: string, on_error: string, http_client_service: string} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('artack_recaptcha_enterprise.enabled', $config['enabled']);
        $container->setParameter('artack_recaptcha_enterprise.site_key', $config['site_key']);
        $container->setParameter('artack_recaptcha_enterprise.project_id', $config['project_id']);
        $container->setParameter('artack_recaptcha_enterprise.api_key', $config['api_key']);
        $container->setParameter('artack_recaptcha_enterprise.min_score', $config['min_score']);
        $container->setParameter('artack_recaptcha_enterprise.challenge', $config['challenge']);
        $container->setParameter('artack_recaptcha_enterprise.deny_on_error', 'deny' === $config['on_error']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');

        // The client is a service reference, not a parameter, so it is wired here rather than in
        // services.php where the configuration is not in scope.
        $container->getDefinition('artack_recaptcha_enterprise.gateway')
            ->replaceArgument(0, new Reference($config['http_client_service']))
        ;
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Prepending to a missing extension throws, and neither of these is useful without it.
        if ($container->hasExtension('framework')) {
            // Prepended config loses against the application's own, so redeclaring this scoped
            // client under framework.http_client.scoped_clients overrides any of it.
            $container->prependExtensionConfig('framework', [
                'http_client' => [
                    'scoped_clients' => [
                        Configuration::CLIENT_SERVICE => [
                            'base_uri' => 'https://recaptchaenterprise.googleapis.com',
                            // Left to default_socket_timeout, an unresponsive Google would hold
                            // the worker instead of reaching the on_error policy.
                            'timeout' => 2.0,
                            'max_duration' => 5.0,
                        ],
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', [
                'form_themes' => ['@ArtackRecaptchaEnterprise/Form/recaptcha_enterprise_widget.html.twig'],
            ]);
        }
    }

    /**
     * Extension::getAlias() would derive the same name from the class, but pinning it keeps the
     * configuration key stable if the class is ever renamed.
     */
    public function getAlias(): string
    {
        return 'artack_recaptcha_enterprise';
    }
}
