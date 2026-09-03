# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

`codein/recaptcha-enterprise-bundle` — a Symfony bundle integrating Google reCAPTCHA Enterprise through the REST
Assessments API. It is a rewrite-scale fork of `artack/recaptcha-enterprise-bundle:0.2.0`, published by Codéin at
[Codein-Labs/recaptcha-enterprise-bundle](https://github.com/Codein-Labs/recaptcha-enterprise-bundle) and heading
for `1.0.0`. Namespace `Codein\RecaptchaEnterpriseBundle\` → `src/`, config key `codein_recaptcha_enterprise`.

## Commands

**There is no local PHP or Composer.** Everything runs in Docker through the `Makefile`, which mounts the working
directory and runs as `--user 1000:1000` so files come back owned by the host user. Never invoke `php`,
`composer`, `phpunit` or `phpstan` directly.

```shell
make install                 # build the image, install bundle + tool dependencies
make test                    # PHPUnit
make phpstan                 # PHPStan, level 9
make cs / make cs-fix        # PHP-CS-Fixer
make qa                      # phpstan + cs + test — run this before declaring work done
make qa PHP_VERSION=8.4      # same, against a newer runtime
```

The default stack is the lowest supported one: PHP 8.1 with `--prefer-lowest --prefer-stable`. That is the point
of the default, not an accident — it proves the declared minimums install.

`make qa` must be clean before any change is called finished. Deprecation notices in the PHPUnit output are
expected (see below) and are not failures.

## Architecture

Verification is a port/adapter split; keep deciding and calling apart when editing:

- `Assessment\GatewayInterface` — the port to Google. `HttpGateway` is the only implementation: it translates
  the wire format into an `Assessment` value object and throws `AssessmentExceptionInterface`
  (`TransportException`, `AuthenticationException`, `InvalidRequestException`) when there is nothing
  to translate. No HTTP detail leaks past it.
- `Verifier\Verifier` — every decision: the empty-token short circuit, the expected-action check, the `on_error`
  outage policy, the score threshold. It knows nothing about HTTP. `verify()` returns a `Result`; the verifier is
  stateless and nothing is read back from the service afterwards.
- `Validator\RecaptchaEnterpriseValidator` — turns a `Result` into a violation carrying a code
  (`INVALID_TOKEN_ERROR`, `LOW_SCORE_ERROR`, `UNAVAILABLE_ERROR`), the `{{ reason }}` / `{{ score }}` parameters,
  and the `Result` itself as the violation cause.
- `CaptchaFailure\Finder` — reads back only what the validator recorded. It decides nothing, which is why it has
  no dependencies and no configuration.

## Hard constraints

These are deliberate decisions, not oversights. Do not "fix" them without being asked.

- **Symfony 5.4 must stay supported**, alongside 6.4 LTS, 7.4 LTS and 8.x. Symfony 7.0–7.3 are deliberately out.
  This rules out `AbstractBundle` and `DefinitionConfigurator` (6.1+), so the bundle keeps the classic
  Bundle/Extension/Configuration trio. 5.4 components raise PHP deprecations the bundle cannot fix, so the test
  suite does not fail on deprecations.
- **No Google SDK.** `google/cloud-recaptcha-enterprise` pulls `google/gax`, which requires `ramsey/uuid ^4`
  and therefore cannot install alongside applications pinned to `ramsey/uuid` 3.x — Ibexa 4.6 among them. The REST
  gateway exists for this reason.
- **The bundle never loads Google's `enterprise.js`.** The application adds that tag itself, after consent. This
  is a GDPR position and is intentionally not configurable — do not add a flag for it.
- **`composer.lock` is not committed.** This is a library; consumers resolve their own versions.
- **PHPStan runs at level 9** over `src` and `tests`, with `phpVersion` pinned to 8.1–8.4.

## Frontend contract

Three files form one coupled contract; a change to any of them usually needs the other two:

- `src/Resources/views/Form/recaptcha_enterprise_widget.html.twig` — blocks `recaptcha_enterprise_row`,
  `_widget` (dispatches only), `_score_widget`, `_checkbox_widget`. Registered automatically by the extension's
  `prepend()` as `@CodeinRecaptchaEnterprise/Form/…`.
- `src/Resources/public/recaptcha-enterprise.js` — the shipped asset, published
  to `public/bundles/codeinrecaptchaenterprise/` by `assets:install`. The theme emits no inline JavaScript at all.
- The `data-codein-recaptcha*` attributes are the only link between the two. Public JS API:
  `codeinRecaptchaOnload`, `window.codeinRecaptcha.refresh()` / `.whenReady()`, and the cancelable
  `codein-recaptcha:error` event.

The asset has **no automated test** — CI is PHP-only. Changes to it need manual browser verification.

`tests/Form/WidgetRenderingTest.php` asserts the rendered markup and is the fastest signal that the Twig side
broke.

## Conventions

- Comments explain *why*, not what. The existing code documents the reasoning behind non-obvious choices
  (timeouts, fail-closed score, `error_bubbling`); match that density rather than narrating the code.
- Markdown docs follow the `markdown-doc-style` skill: 120-character hard wrap, no line ending on a stop word,
  tables and code fences untouched. Verify with
  `~/.claude/skills/markdown-doc-style/scripts/mdstyle --check <file>`. **Never use `--fix`** — it corrupts
  setext headings and badge links, and this repo uses setext headings throughout `README.md`.
- `notes/` is gitignored: working documents, review reports and plans live there and are not shipped.
- Attribution: the MIT licence keeps the original `ARTACK WebLab` copyright line alongside Codéin's,
  and `composer.json` keeps ARTACK in `authors`. Do not remove either.
