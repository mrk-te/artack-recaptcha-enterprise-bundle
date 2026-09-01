<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<null>
 */
final class RecaptchaEnterpriseType extends AbstractType
{
    /**
     * Invisible: the token is fetched on submit and judged by its risk analysis score.
     */
    public const CHALLENGE_SCORE = 'score';

    /**
     * The "I'm not a robot" checkbox, rendered explicitly so the token lands in this field.
     */
    public const CHALLENGE_CHECKBOX = 'checkbox';

    /**
     * The challenge is application-wide, not per field: the single site_key settles it anyway,
     * since the two challenges need different kinds of key in the Google console.
     */
    public function __construct(
        private readonly string $siteKey,
        private readonly bool $enabled,
        private readonly string $challenge = self::CHALLENGE_SCORE,
    ) {}

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['site_key'] = $this->siteKey;
        $view->vars['enabled'] = $this->enabled;
        $view->vars['challenge'] = $this->challenge;
        $view->vars['action_name'] = $options['action_name'];
        $view->vars['theme'] = $options['theme'];
        $view->vars['size'] = $options['size'];
        $view->vars['script_csp_nonce'] = $options['script_csp_nonce'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
            'action_name' => null,
            'theme' => 'light',
            'size' => 'normal',
            'script_csp_nonce' => null,
        ]);

        // theme and size are ignored by the score challenge, which renders nothing visible.
        $resolver->setAllowedValues('theme', ['light', 'dark']);
        $resolver->setAllowedValues('size', ['normal', 'compact']);

        $resolver->setAllowedTypes('action_name', ['null', 'string']);
        $resolver->setAllowedTypes('script_csp_nonce', ['null', 'string']);
    }

    public function getBlockPrefix(): string
    {
        return 'recaptcha_enterprise';
    }
}
