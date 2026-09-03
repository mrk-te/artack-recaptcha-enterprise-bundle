<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\DependencyInjection;

use Codein\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * The scoped client the bundle prepends onto framework.http_client. An application redeclares
     * it under that key to change any transport option — timeouts, proxy, TLS verification,
     * retries — rather than through settings this bundle would have to forward one by one.
     */
    public const CLIENT_SERVICE = 'codein_recaptcha_enterprise.client';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('codein_recaptcha_enterprise');

        $treeBuilder->getRootNode()
            ->children()
            ->booleanNode('enabled')
            ->info('Set to false to skip every assessment, e.g. in a test environment.')
            ->defaultTrue()
            ->end()
            ->scalarNode('site_key')
            ->info('The reCAPTCHA Enterprise site key, exposed to the browser.')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('project_id')
            ->info('The Google Cloud project owning the reCAPTCHA Enterprise key.')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('api_key')
            ->info('The Google Cloud API key authenticating the assessment calls.')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->floatNode('min_score')
            ->info('The lowest accepted risk analysis score. 0 disables the score check.')
            ->defaultValue(0.5)
            ->end()
            ->enumNode('challenge')
            ->info('The default challenge rendered by the form type. Each type needs its own kind of site key.')
            ->values([RecaptchaEnterpriseType::CHALLENGE_SCORE, RecaptchaEnterpriseType::CHALLENGE_CHECKBOX])
            ->defaultValue(RecaptchaEnterpriseType::CHALLENGE_SCORE)
            ->end()
            ->enumNode('on_error')
            ->info('What to do when Google cannot be reached: deny the token, or let it through.')
            ->values(['deny', 'allow'])
            ->defaultValue('deny')
            ->end()
            ->scalarNode('http_client_service')
            ->info('The HTTP client used for the assessment calls. Defaults to the scoped client the bundle declares, which carries the timeouts.')
            ->defaultValue(self::CLIENT_SERVICE)
            ->cannotBeEmpty()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
