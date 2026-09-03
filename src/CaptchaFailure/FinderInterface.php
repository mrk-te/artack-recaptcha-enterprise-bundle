<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\CaptchaFailure;

use Artack\RecaptchaEnterpriseBundle\CaptchaFailure\Exception\NoFailureException;
use Symfony\Component\Form\FormInterface;

/**
 * Reads back the captcha failure a submitted form carries, so a controller does not have to walk the
 * form errors and unwrap two levels of cause itself.
 */
interface FinderInterface
{
    /**
     * Whether the form was refused because of its captcha field. False on a form that was not
     * submitted, that is valid, or that failed for any other reason.
     *
     * @param FormInterface<mixed> $form
     */
    public function has(FormInterface $form): bool;

    /**
     * @param FormInterface<mixed> $form
     *
     * @throws NoFailureException when has() would have returned false
     */
    public function get(FormInterface $form): Failure;
}
