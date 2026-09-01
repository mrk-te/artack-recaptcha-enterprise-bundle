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
    private const THEME = 'Form/recaptcha_enterprise_widget.html.twig';

    private FormFactoryInterface $factory;
    private FormRenderer $renderer;
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->boot();
    }

    public function testTheScoreChallengeRendersTheInvisibleIntegration(): void
    {
        $html = $this->render();

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString("grecaptcha.enterprise.execute('".self::SITE_KEY."'", $html);
        self::assertStringNotContainsString('grecaptcha.enterprise.render', $html);
    }

    public function testTheScoreChallengeResubmitsWithTheClickedButton(): void
    {
        $html = $this->render();

        self::assertStringContainsString('form.requestSubmit(submitter)', $html);
        // The sentinel that lets the resubmit through is script state, never the field value: a
        // stored token would be a spent one.
        self::assertStringContainsString('if (resubmitting)', $html);
        self::assertStringNotContainsString('if (field.value)', $html);
    }

    public function testTheScoreChallengeWaitsForTheLibraryBeforeSubmitting(): void
    {
        $html = $this->render();

        // Calling grecaptcha.enterprise.ready() directly throws when the loader has not landed,
        // which would leave the form prevented and silently dead.
        self::assertStringContainsString('window.___artackRecaptcha.whenReady(', $html);
        self::assertStringNotContainsString('grecaptcha.enterprise.ready(function', $html);
    }

    public function testTheScoreChallengeHandlesAFailedExecution(): void
    {
        $html = $this->render();

        self::assertStringContainsString('.catch(abandon)', $html);
        // A loader that never arrives must not take the submission down with it.
        self::assertStringContainsString('window.setTimeout(abandon', $html);
        self::assertStringContainsString("new CustomEvent('artack-recaptcha:error'", $html);
    }

    public function testTheScoreChallengeOmitsAnAbsentAction(): void
    {
        self::assertStringContainsString(
            "grecaptcha.enterprise.execute('".self::SITE_KEY."')",
            $this->render(),
        );

        self::assertStringContainsString(
            "grecaptcha.enterprise.execute('".self::SITE_KEY."', {action: 'contact'})",
            $this->render(['action_name' => 'contact']),
        );
    }

    public function testTheScoreChallengeIsTheDefault(): void
    {
        self::assertSame($this->render(), $this->render(['challenge' => 'score']));
    }

    public function testTheCheckboxChallengeRendersTheExplicitIntegration(): void
    {
        $html = $this->render([
            'challenge' => 'checkbox',
            'action_name' => 'contact',
            'theme' => 'dark',
            'size' => 'compact',
        ]);

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('_widget" class="recaptcha-enterprise__widget"', $html);
        self::assertStringContainsString('grecaptcha.enterprise.render(', $html);
        self::assertStringContainsString("sitekey: '".self::SITE_KEY."'", $html);
        self::assertStringContainsString("action: 'contact'", $html);
        self::assertStringContainsString("theme: 'dark'", $html);
        self::assertStringContainsString("size: 'compact'", $html);
        self::assertStringNotContainsString('grecaptcha.enterprise.execute', $html);
    }

    public function testTheCheckboxChallengeClearsTheFieldWhenTheTokenExpires(): void
    {
        $html = $this->render(['challenge' => 'checkbox']);

        self::assertStringContainsString("'expired-callback'", $html);
        self::assertStringContainsString("'error-callback'", $html);
    }

    /**
     * The bundle must never place a Google script on a page: it cannot know whether the visitor
     * consented to it. The application adds the loader, after consent, and any number of fields
     * then share the queue this bootstrap defines.
     */
    public function testNeitherChallengeLoadsGoogle(): void
    {
        foreach (['score', 'checkbox'] as $challenge) {
            $html = $this->render(['challenge' => $challenge]);

            self::assertStringNotContainsString('google.com/recaptcha', $html);
            self::assertStringNotContainsString('createElement', $html);
            self::assertStringContainsString('window.___artackRecaptcha', $html);
        }
    }

    /**
     * The loader's onload= is the documented readiness signal, so the callback is public API and
     * its name may not drift. The poll covers a library that landed before the bootstrap ran.
     */
    public function testTheReadinessSignalsAreBothAvailable(): void
    {
        foreach (['score', 'checkbox'] as $challenge) {
            $html = $this->render(['challenge' => $challenge]);

            self::assertStringContainsString('window.___artackRecaptchaOnload = drain', $html);
            self::assertStringContainsString('window.setInterval(', $html);
        }
    }

    public function testTheCheckboxChallengeOmitsAnAbsentAction(): void
    {
        $html = $this->render(['challenge' => 'checkbox']);

        self::assertStringNotContainsString('action:', $html);
    }

    public function testTheCspNonceIsAppliedToEveryScript(): void
    {
        foreach (['score', 'checkbox'] as $challenge) {
            $html = $this->render(['challenge' => $challenge, 'script_csp_nonce' => 'anonce123']);

            // The bootstrap and the field script, which are the only two scripts emitted.
            self::assertSame(2, mb_substr_count($html, 'nonce="anonce123"'));
            self::assertSame(2, mb_substr_count($html, '<script'));
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
            // A browser restoring form state on back-navigation is the other way it comes back.
            self::assertStringContainsString("field.value = ''", $html);
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

    public function testADisabledBundleRendersOnlyTheHiddenField(): void
    {
        $this->boot(enabled: false);

        foreach (['score', 'checkbox'] as $challenge) {
            $html = $this->render(['challenge' => $challenge]);

            self::assertStringContainsString('type="hidden"', $html);
            self::assertStringNotContainsString('<script', $html);
        }
    }

    public function testTheConfiguredDefaultChallengeIsUsed(): void
    {
        $this->boot(challenge: RecaptchaEnterpriseType::CHALLENGE_CHECKBOX);

        self::assertStringContainsString('grecaptcha.enterprise.render(', $this->render());
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
