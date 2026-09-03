<?php

declare(strict_types=1);

namespace Codein\RecaptchaEnterpriseBundle\CaptchaFailure;

use Codein\RecaptchaEnterpriseBundle\CaptchaFailure\Exception\NoFailureException;
use Codein\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterprise;
use Codein\RecaptchaEnterpriseBundle\Verifier\Result;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\ConstraintViolation;

use function sprintf;

/**
 * Stateless: it only reads what the validator already recorded, and decides nothing of its own.
 */
final class Finder implements FinderInterface
{
    /**
     * @param FormInterface<mixed> $form
     */
    public function has(FormInterface $form): bool
    {
        return null !== $this->search($form);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    public function get(FormInterface $form): Failure
    {
        return $this->search($form) ?? throw new NoFailureException(sprintf(
            'The form "%s" carries no reCAPTCHA Enterprise failure. Call has() before get().',
            $form->getName(),
        ));
    }

    /**
     * Searches the whole tree, so the caller passes its form and never names the captcha field.
     * Passing the field itself works just as well, being a smaller subtree.
     *
     * A form holds at most one captcha field: site_key and challenge are application wide, so a
     * second one would only be the same challenge twice on one page.
     *
     * @param FormInterface<mixed> $form
     */
    private function search(FormInterface $form): ?Failure
    {
        foreach ($form->getErrors(true) as $error) {
            // Anything but a constraint violation — a transformation failure, an error added by
            // hand — cannot come from the constraint, so there is nothing to unwrap.
            $violation = $error->getCause();

            // getConstraint() and getCause() are on the concrete class, not on the interface.
            if (!$violation instanceof ConstraintViolation) {
                continue;
            }

            if (!$violation->getConstraint() instanceof RecaptchaEnterprise) {
                continue;
            }

            $result = $violation->getCause();

            // The bundle's validator always attaches one. A violation of the same constraint
            // raised elsewhere may not, and is skipped rather than reported half filled.
            if (!$result instanceof Result) {
                continue;
            }

            return new Failure($violation, $result);
        }

        return null;
    }
}
