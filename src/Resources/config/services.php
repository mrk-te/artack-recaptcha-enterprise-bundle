<?php

declare(strict_types=1);

use Codein\RecaptchaEnterpriseBundle\Assessment\GatewayInterface;
use Codein\RecaptchaEnterpriseBundle\Assessment\HttpGateway;
use Codein\RecaptchaEnterpriseBundle\CaptchaFailure\Finder;
use Codein\RecaptchaEnterpriseBundle\CaptchaFailure\FinderInterface;
use Codein\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use Codein\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterpriseValidator;
use Codein\RecaptchaEnterpriseBundle\Verifier\Verifier;
use Codein\RecaptchaEnterpriseBundle\Verifier\VerifierInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('codein_recaptcha_enterprise.gateway', HttpGateway::class)
        ->args([
            // Replaced by the extension with the client named in http_client_service.
            service('http_client'),
            '%codein_recaptcha_enterprise.project_id%',
            '%codein_recaptcha_enterprise.api_key%',
        ])
    ;

    $services->alias(GatewayInterface::class, 'codein_recaptcha_enterprise.gateway');

    $services->set('codein_recaptcha_enterprise.verifier', Verifier::class)
        ->args([
            service('codein_recaptcha_enterprise.gateway'),
            '%codein_recaptcha_enterprise.site_key%',
            service('request_stack')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            '%codein_recaptcha_enterprise.deny_on_error%',
        ])
    ;

    $services->alias(VerifierInterface::class, 'codein_recaptcha_enterprise.verifier');

    $services->set('codein_recaptcha_enterprise.captcha_failure_finder', Finder::class);

    $services->alias(FinderInterface::class, 'codein_recaptcha_enterprise.captcha_failure_finder');

    $services->set(RecaptchaEnterpriseValidator::class)
        ->args([
            service('codein_recaptcha_enterprise.verifier'),
            '%codein_recaptcha_enterprise.enabled%',
            '%codein_recaptcha_enterprise.min_score%',
        ])
        ->tag('validator.constraint_validator')
    ;

    $services->set(RecaptchaEnterpriseType::class)
        ->args([
            '%codein_recaptcha_enterprise.site_key%',
            '%codein_recaptcha_enterprise.enabled%',
            '%codein_recaptcha_enterprise.challenge%',
        ])
        ->tag('form.type')
    ;
};
