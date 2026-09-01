<?php

declare(strict_types=1);

use Artack\RecaptchaEnterpriseBundle\Assessment\GatewayInterface;
use Artack\RecaptchaEnterpriseBundle\Assessment\HttpGateway;
use Artack\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use Artack\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterpriseValidator;
use Artack\RecaptchaEnterpriseBundle\Verifier\Verifier;
use Artack\RecaptchaEnterpriseBundle\Verifier\VerifierInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('artack_recaptcha_enterprise.gateway', HttpGateway::class)
        ->args([
            // Replaced by the extension with the client named in http_client_service.
            service('http_client'),
            '%artack_recaptcha_enterprise.project_id%',
            '%artack_recaptcha_enterprise.api_key%',
        ])
    ;

    $services->alias(GatewayInterface::class, 'artack_recaptcha_enterprise.gateway');

    $services->set('artack_recaptcha_enterprise.verifier', Verifier::class)
        ->args([
            service('artack_recaptcha_enterprise.gateway'),
            '%artack_recaptcha_enterprise.site_key%',
            service('request_stack')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            '%artack_recaptcha_enterprise.deny_on_error%',
        ])
    ;

    $services->alias(VerifierInterface::class, 'artack_recaptcha_enterprise.verifier');

    $services->set(RecaptchaEnterpriseValidator::class)
        ->args([
            service('artack_recaptcha_enterprise.verifier'),
            '%artack_recaptcha_enterprise.enabled%',
            '%artack_recaptcha_enterprise.min_score%',
        ])
        ->tag('validator.constraint_validator')
    ;

    $services->set(RecaptchaEnterpriseType::class)
        ->args([
            '%artack_recaptcha_enterprise.site_key%',
            '%artack_recaptcha_enterprise.enabled%',
            '%artack_recaptcha_enterprise.challenge%',
        ])
        ->tag('form.type')
    ;
};
