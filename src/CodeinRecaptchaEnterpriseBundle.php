<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/*
 * AbstractBundle and its DefinitionConfigurator only exist from Symfony 6.1, so the bundle keeps
 * the classic Bundle/Extension/Configuration trio to stay installable on 5.4. Bundle::getPath()
 * resolves to src/, which is why the form theme lives in src/Resources/views.
 */
class CodeinRecaptchaEnterpriseBundle extends Bundle {}
