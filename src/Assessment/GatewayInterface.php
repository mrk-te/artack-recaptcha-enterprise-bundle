<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\Assessment;

use Codein\RecaptchaEnterpriseBundle\Assessment\Exception\AssessmentExceptionInterface;

/**
 * The port between the domain and whatever talks to Google.
 *
 * An implementation translates, it does not decide: it maps the wire format onto an Assessment and
 * nothing more. Whether a valid token is good enough is the Verifier's business.
 */
interface GatewayInterface
{
    /**
     * @throws AssessmentExceptionInterface when Google could not be asked, or did not answer with
     *                                      an assessment
     */
    public function assess(AssessmentRequest $request): Assessment;
}
