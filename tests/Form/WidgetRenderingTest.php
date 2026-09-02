<?php

declare(strict_types=1);

namespace Artack\RecaptchaEnterpriseBundle\Tests\Form;

use Artack\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use function dirname;
use function is_string;

/**
 * Renders the shipped form theme, which is the only part of the bundle the end user sees.
 *
 * The site key is deliberately free of dashes: Twig's `e('js')` escapes them, which would make
 * the assertions unreadable rather than reveal anything about the template.
 *
 * @internal
 */
#[CoversNothing]
final class WidgetRenderingTest extends TestCase
{
    private const SITE_KEY = 'asitekey123';
    private const FIELD_ID = 'recaptcha_enterprise';
    private const THEME = 'Form/recaptcha_enterprise_widget.html.twig';

    private FormFactoryInterface $factory;
    private FormRenderer $renderer;
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->boot();
    }

    public function testTheScoreChallengeDescribesTheFieldWithDataAttributes(): void
    {
        $html = $this->render();

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('data-artack-recaptcha="score"', $html);
        self::assertStringContainsString('data-artack-recaptcha-sitekey="'.self::SITE_KEY.'"', $html);
        // The score challenge renders nothing visible, so there is no container.
        self::assertStringNotContainsString('data-artack-recaptcha-container', $html);
    }

    public function testTheScoreChallengeIsTheDefault(): void
    {
        self::assertSame($this->render(), $this->render(['challenge' => 'score']));
    }

    public function testTheCheckboxChallengeDescribesItsContainer(): void
    {
        $html = $this->render([
            'challenge' => 'checkbox',
            'theme' => 'dark',
            'size' => 'compact',
        ]);

        self::assertStringContainsString('data-artack-recaptcha="checkbox"', $html);
        self::assertStringContainsString('class="recaptcha-enterprise__widget"', $html);
        self::assertStringContainsString('data-artack-recaptcha-container="'.self::FIELD_ID.'"', $html);
        self::assertStringContainsString('data-artack-recaptcha-theme="dark"', $html);
        self::assertStringContainsString('data-artack-recaptcha-size="compact"', $html);
    }

    /**
     * The container is matched to its field by id, so several checkbox widgets on one page each
     * render into their own div rather than fighting over the first one.
     */
    public function testTheContainerCarriesTheFieldId(): void
    {
        $html = $this->render(['challenge' => 'checkbox']);

        self::assertStringContainsString('id="'.self::FIELD_ID.'"', $html);
        self::assertStringContainsString('data-artack-recaptcha-container="'.self::FIELD_ID.'"', $html);
    }

    /**
     * An unset action must be omitted rather than sent as an empty string, which Google rejects.
     */
    public function testTheActionIsOmittedWhenAbsent(): void
    {
        foreach (['score', 'checkbox'] as $challenge) {
            self::assertStringNotContainsString(
                'data-artack-recaptcha-action',
                $this->render(['challenge' => $challenge]),
            );

            self::assertStringContainsString(
                'data-artack-recaptcha-action="contact"',
                $this->render(['challenge' => $challenge, 'action_name' => 'contact']),
            );
        }
    }

    /**
     * The behaviour ships as an asset the application loads. A theme emitting inline script would
     * force a CSP nonce back into the bundle and duplicate the same handler once per field.
     */
    public function testNeitherChallengeEmitsScript(): void
    {
        foreach (['score', 'checkbox'] as $challenge) {
            $html = $this->render(['challenge' => $challenge, 'action_name' => 'contact']);

            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('nonce', $html);
            // The bundle must never place a Google script on a page: it cannot know whether the
            // visitor consented to it.
            self::assertStringNotContainsString('google.com/recaptcha', $html);
        }
    }

    /**
     * A disabled bundle must leave a field the asset does not pick up, rather than one it wires to
     * a site key that is not meant to be used.
     */
    public function testADisabledBundleEmitsNoDataAttributes(): void
    {
        $this->boot(enabled: false);

        foreach (['score', 'checkbox'] as $challenge) {
            $html = $this->render(['challenge' => $challenge]);

            self::assertStringContainsString('type="hidden"', $html);
            self::assertStringNotContainsString('data-artack-recaptcha', $html);
            self::assertStringNotContainsString('recaptcha-enterprise__widget', $html);
        }
    }

    /**
     * Google refuses a replayed token with DUPE. Re-rendering the stored one would make every
     * further attempt fail, locking the user out of the form for good.
     */
    public function testASubmittedFormNeverRendersTheSpentToken(): void
    {
        foreach (['score', 'checkbox'] as $challenge) {
            $this->boot(challenge: $challenge);

            $form = $this->factory->create(RecaptchaEnterpriseType::class);
            $form->submit('aspenttoken123');

            $html = $this->renderer->searchAndRenderBlock($form->createView(), 'widget');

            self::assertStringNotContainsString('aspenttoken123', $html);
            self::assertStringNotContainsString('value=', $html);
        }
    }

    /**
     * The field inherits from HiddenType, whose hidden_row block renders the widget alone. Without
     * a row block of our own the violation is raised and then silently dropped, so the visitor is
     * refused with no message at all.
     */
    public function testTheViolationIsRendered(): void
    {
        foreach (['score', 'checkbox'] as $challenge) {
            $this->boot(challenge: $challenge);

            $form = $this->factory->create(RecaptchaEnterpriseType::class);
            $form->submit('');
            $form->addError(new FormError('The captcha did not validate.'));

            $html = $this->renderer->searchAndRenderBlock($form->createView(), 'row');

            self::assertStringContainsString('The captcha did not validate.', $html);
            self::assertStringContainsString('type="hidden"', $html);
        }
    }

    public function testTheConfiguredDefaultChallengeIsUsed(): void
    {
        $this->boot(challenge: RecaptchaEnterpriseType::CHALLENGE_CHECKBOX);

        self::assertStringContainsString('data-artack-recaptcha="checkbox"', $this->render());
    }

    private function boot(
        bool $enabled = true,
        string $challenge = RecaptchaEnterpriseType::CHALLENGE_SCORE,
    ): void {
        $this->enabled = $enabled;

        $twig = new Environment(new FilesystemLoader([
            __DIR__.'/../../src/Resources/views',
            dirname((new ReflectionClass(FormExtension::class))->getFileName() ?: '').'/../Resources/views/Form',
        ]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension());

        $engine = new TwigRendererEngine(['form_div_layout.html.twig', self::THEME], $twig);
        $this->renderer = new FormRenderer($engine);

        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => fn (): FormRenderer => $this->renderer,
        ]));

        $this->factory = Forms::createFormFactoryBuilder()
            ->addType(new RecaptchaEnterpriseType(self::SITE_KEY, $enabled, $challenge))
            ->getFormFactory()
        ;
    }

    /**
     * `challenge` is a bundle setting rather than a field option, so it is applied by rebuilding
     * the type instead of being passed to the field.
     *
     * @param array<string, mixed> $options
     */
    private function render(array $options = []): string
    {
        $challenge = is_string($options['challenge'] ?? null) ? (string) $options['challenge'] : null;
        unset($options['challenge']);

        if (null !== $challenge) {
            $this->boot($this->enabled, $challenge);
        }

        $view = $this->factory->create(RecaptchaEnterpriseType::class, null, $options)->createView();

        return $this->renderer->searchAndRenderBlock($view, 'widget');
    }
}
