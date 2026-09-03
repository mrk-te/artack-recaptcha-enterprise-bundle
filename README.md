codein/recaptcha-enterprise-bundle
==================================

> Symfony integration for Google reCAPTCHA Enterprise (Assessments API).

[![Latest Release](https://img.shields.io/packagist/v/codein/recaptcha-enterprise-bundle.svg)](https://packagist.org/packages/codein/recaptcha-enterprise-bundle)
[![MIT License](https://img.shields.io/packagist/l/codein/recaptcha-enterprise-bundle.svg)](http://opensource.org/licenses/MIT)
[![Total Downloads](https://img.shields.io/packagist/dt/codein/recaptcha-enterprise-bundle.svg)](https://packagist.org/packages/codein/recaptcha-enterprise-bundle)

Based on [artack/recaptcha-enterprise-bundle](https://github.com/artack/recaptcha-enterprise-bundle) 0.2.0,
forked and maintained by [Codéin](https://www.codein.fr). Originally developed by
[ARTACK WebLab GmbH](https://www.artack.ch) in Zurich, Switzerland, and released under the MIT licence,
which this fork keeps along with the original copyright notice.

What changed from artack/recaptcha-enterprise-bundle
----------------------------------------------------

- **PHP version reduced to 8.1**, down from 8.2.
- **Symfony support starting with version 5.4**, alongside the 6.4 and 7.4 LTS lines and 8.x.
- **Added support for the checkbox mode**, the "I'm not a robot" widget, beside the invisible score challenge.
- **The challenge is chosen once for the whole application** through the `challenge` setting: a score key
  and a checkbox key are different key types, and `site_key` is a single global value.
- **Google's JavaScript resources are no longer loaded automatically** and must be added to the layout by hand,
  so the application decides when — which is what makes GDPR consent manageable.
- **Three interfaces are exposed as autowired services:**
  - `Assessment\GatewayInterface` — the call to Google, depending solely on the Symfony HTTP client, no SDK.
  - `Verifier\VerifierInterface` — verifying the response, outside the constraint if you need it.
  - `CaptchaFailure\FinderInterface` — adding your own logic when a captcha is refused.

See "Upgrading from artack/recaptcha-enterprise-bundle:0.2.0" for the full list of breaking changes.

Installation
------------

### Requirements

| Requirement | Supported versions |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 |
| Symfony | 5.4 LTS, 6.4 LTS, 7.4 LTS, 8.x |

### Installing with the Flex recipe

```shell
$ composer config extra.symfony.allow-contrib true
$ composer require codein/recaptcha-enterprise-bundle
```

The first command is needed once per application: contributed recipes are disabled in the Symfony skeleton.
The [recipe](https://github.com/symfony/recipes-contrib) then registers the bundle, writes
`config/packages/codein_recaptcha_enterprise.yaml`, adds the environment variables to `.env`, and inserts
the bundle's own asset tag into `templates/base.html.twig`. It never adds Google's tag, which depends on consent.

> ⚠️ **The application loads Google's `enterprise.js` itself.** With no loader there is no token, so the
> constraint refuses every submission and the visitor is locked out of the form. Read "Adding the scripts to
> your layout" before deploying.

### Installing without a recipe

Flex enables the bundle by itself, but it writes no configuration when there is no recipe. Create
`config/packages/codein_recaptcha_enterprise.yaml` as shown under "Configuration", and add the three
environment variables it reads to your `.env`:

```dotenv
CODEIN_RECAPTCHA_ENTERPRISE_PROJECT_ID=
CODEIN_RECAPTCHA_ENTERPRISE_SITE_KEY=
CODEIN_RECAPTCHA_ENTERPRISE_API_KEY=
```

If the configuration key is rejected as unrecognised, the bundle is not registered — check `config/bundles.php`
and add it by hand, which is what an install from a VCS repository of a package renamed locally may need:

```php
// config/bundles.php
return [
    // ...
    Codein\RecaptchaEnterpriseBundle\CodeinRecaptchaEnterpriseBundle::class => ['all' => true],
];
```

### Adding the scripts to your layout

The bundle ships the submission handling as an asset and **never places Google's script on a page —
the application does**. Both tags are part of installing the bundle, not an optional extra: with no loader there is
no token, so the constraint refuses every submission as `MISSING` and the visitor is locked out of the form.

Publish the asset, which Flex's `auto-scripts` already does on every `composer install`:

```shell
$ php bin/console assets:install public
```

Expose the site key to Twig:

```yaml
# config/packages/twig.yaml
twig:
    globals:
        recaptcha_site_key: '%codein_recaptcha_enterprise.site_key%'
```

Then add both scripts once per page, in your layout, the Google one only after the visitor has consented:

```twig
{# score: the site key is bound to the loader at load time #}
<script src="https://www.google.com/recaptcha/enterprise.js?render={{ site_key }}&hl=fr&onload=codeinRecaptchaOnload"
        async defer></script>

{# checkbox: the widgets are rendered explicitly, one per field #}
<script src="https://www.google.com/recaptcha/enterprise.js?render=explicit&hl=fr&onload=codeinRecaptchaOnload"
        async defer></script>

{# both challenges: the bundle's submission handling, no consent needed #}
<script src="{{ asset('bundles/codeinrecaptchaenterprise/recaptcha-enterprise.js') }}" defer></script>
```

The bundle's own asset carries no personal data and may be loaded unconditionally; only the Google tag is subject
to consent. The checkbox challenge uses `render=explicit` instead of the site key. See "Loading the scripts" for
that variant, for the `hl=` language parameter, for the `codeinRecaptchaOnload` readiness contract, and for what
an application must do while consent is absent.

Configuration
-------------

Create `config/packages/codein_recaptcha_enterprise.yaml` with your Google project credentials:

```yaml
# config/packages/codein_recaptcha_enterprise.yaml
codein_recaptcha_enterprise:
    enabled: true # set to false to skip every assessment
    site_key: '%env(CODEIN_RECAPTCHA_ENTERPRISE_SITE_KEY)%'
    project_id: '%env(CODEIN_RECAPTCHA_ENTERPRISE_PROJECT_ID)%'
    api_key: '%env(CODEIN_RECAPTCHA_ENTERPRISE_API_KEY)%'
    min_score: 0.5 # default score threshold used by the validator when none is provided
    challenge: score # score (default) or checkbox, see "Choosing the challenge"
    on_error: deny # deny (default) or allow, see "When Google cannot be reached"
    http_client_service: codein_recaptcha_enterprise.client # see "Configuring the HTTP client"

when@dev:
    codein_recaptcha_enterprise:
        enabled: false # disable reCAPTCHA in dev environments
```

`site_key`, `project_id` and `api_key` are required. `min_score` defaults to `0.5` and is used when a constraint
does not define its own threshold; set it to `0` to disable the score check entirely, which is what the checkbox
challenge normally wants — see "Choosing the challenge".

> ⚠️ Google has eleven score levels between `0.0` and `1.0`, but a project **without a billing account only ever
> receives four of them: `0.1`, `0.3`, `0.7` and `0.9`**. A threshold of `0.5` there means `0.7` in practice,
> which is much stricter than it reads.

### Configuring the HTTP client

The bundle declares its own scoped client, `codein_recaptcha_enterprise.client`, and calls Google through it:

```yaml
framework:
    http_client:
        scoped_clients:
            codein_recaptcha_enterprise.client:
                base_uri: 'https://recaptchaenterprise.googleapis.com'
                timeout: 2.0
                max_duration: 5.0
```

The timeouts matter: left to `default_socket_timeout`, an unresponsive Google holds the worker
instead of reaching the `on_error` policy quickly.

Redeclare that key in your own `framework.yaml` to change anything about the transport — an application's
configuration wins over what a bundle prepends. Every `scoped_clients` option applies, not just the two above:

```yaml
framework:
    http_client:
        scoped_clients:
            codein_recaptcha_enterprise.client:
                base_uri: 'https://recaptchaenterprise.googleapis.com'
                timeout: 5.0
                proxy: '%env(HTTPS_PROXY)%'
                verify_peer: false # e.g. behind a TLS-inspecting corporate proxy
                retry_failed:
                    max_retries: 2
```

This is why the bundle exposes no `timeout`, `proxy` or `verify_peer` setting of its own: forwarding transport
options one by one would always lag behind what the HTTP client already supports.

Use `http_client_service` to point the gateway at an entirely different client instead — an existing scoped
client, a decorated one, or plain `http_client`:

```yaml
codein_recaptcha_enterprise:
    http_client_service: my_app.google_client
```

### Choosing the challenge

`challenge` sets what the form type renders, for the whole application. It is not a form option:
`site_key` is a single global value and the two challenges need different key types, so a field asking
for the other one would send the wrong key and Google would refuse it with `KEY_MISMATCH`.

| Value | What the visitor sees | What Google returns |
|---|---|---|
| `score` (default) | Nothing. The token is fetched on submit. | A risk analysis score, judged against `min_score`. |
| `checkbox` | The "I'm not a robot" checkbox. | A valid or invalid token, plus a risk analysis score. |

> ⚠️ The two are **not interchangeable**: a score key and a checkbox key are different key types in the Google
> console. Pointing `site_key` at the wrong one makes every assessment fail with `KEY_MISMATCH`.

Checkbox keys are scored too — Google returns a score "regardless of the key type" — but on this challenge
the verdict is the validity of the token: the visitor solved the challenge Google itself decided to set. A threshold on
top of that refuses someone who passed it, and the widget offers no second attempt, so set `min_score: 0`:

```yaml
codein_recaptcha_enterprise:
    challenge: checkbox
    site_key: '%env(CODEIN_RECAPTCHA_ENTERPRISE_SITE_KEY)%' # must be a checkbox key
    min_score: 0
```

The checkbox is rendered explicitly rather than through the usual `<div class="g-recaptcha">`. Auto-rendering
posts the token in a `g-recaptcha-response` field at the root of the form data, outside the Symfony field name
prefix, where the constraint would never see it. Explicit rendering writes the token into the bundle's own hidden
field instead, so the validator, the verifier and the constraint stay unaware of which challenge was used.

Checkbox tokens expire after about two minutes, and the field is cleared when that happens, so a stale token is
never submitted.

Usage
-----

Render the token field in a Symfony form:

```php
use Codein\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use Codein\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterprise;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class)
            ->add('message', TextareaType::class)
            ->add('recaptchaToken', RecaptchaEnterpriseType::class, [
                'action_name' => 'contact', // sent to Google; also matched when validating
                'constraints' => [
                    new RecaptchaEnterprise(
                        minScore: 0.7, // optional
                        actionName: 'contact',
                    ),
                ],
            ]);
    }
}
```

### Form options

| Option | Default | Applies to | Description |
|---|---|---|---|
| `action_name` | `null` | both | Sent to Google, and matched by the constraint when it also sets `actionName` |
| `theme` | `'light'` | checkbox | `light` or `dark` |
| `size` | `'normal'` | checkbox | `normal` or `compact` |

`challenge` is **not** a form option. Google supports one `enterprise.js` load per page and its `render=`
parameter takes one value, so the challenge is a bundle setting and every field on a page shares it.

The Twig theme is prepended automatically. It emits no JavaScript: the field carries `data-codein-recaptcha`
attributes, and the shipped asset acts on them. In the `score` challenge it calls `grecaptcha.enterprise.execute`
on submit, fills the hidden field and resubmits with `requestSubmit()`, which preserves the clicked button
and runs the other submit listeners. In the `checkbox` challenge the token is written into the hidden field by
the widget callback as soon as the visitor solves it.

To restyle one challenge without touching the other, override the `recaptcha_enterprise_score_widget`
or `recaptcha_enterprise_checkbox_widget` block rather than `recaptcha_enterprise_widget`, which only
dispatches between them. Keep the `data-codein-recaptcha` attributes on the input and, for checkbox,
the `data-codein-recaptcha-container` div: they are how the asset finds the field.

### Loading the scripts

**The bundle never loads Google's script — the application does.** The bundle cannot know whether the visitor
consented to Google, and a script placed on the page without consent is the application's liability, so this is
deliberately not configurable: a flag would still ship a default that loads it.

"Adding the scripts to your layout" gives the minimum; this is the full contract. Add the loader once per page,
after consent, with `onload=codeinRecaptchaOnload`, alongside the bundle's own asset:

```twig
{# score: the site key is bound to the loader at load time #}
<script src="https://www.google.com/recaptcha/enterprise.js?render={{ site_key }}&hl=fr&onload=codeinRecaptchaOnload"
        async defer></script>

{# checkbox: the widgets are rendered explicitly, one per field #}
<script src="https://www.google.com/recaptcha/enterprise.js?render=explicit&hl=fr&onload=codeinRecaptchaOnload"
        async defer></script>

{# both challenges: the bundle's submission handling, no consent needed #}
<script src="{{ asset('bundles/codeinrecaptchaenterprise/recaptcha-enterprise.js') }}" defer></script>
```

The two tags are not interchangeable: the `render=` value follows the `challenge` setting, and a page must never
carry both — which the single `site_key` already prevents. `hl=` is yours to set, and omitting it lets Google
detect the language from the browser.

`codeinRecaptchaOnload` is public API. It is the only supported readiness signal: `grecaptcha.enterprise.ready()`
does not queue callbacks registered before the library exists, so the asset queues everything itself and drains
the queue when the callback fires. Nothing depends on the two scripts landing in a given order — a library that is
already there is detected directly, and a callback that fired before the asset ran is caught by a short poll.

Any number of fields of the configured challenge can then appear on one page: several score fields share
the single bound key, several checkbox fields each render into their own container. A visitor who submits before
the library has landed is safe — the submission is held and replayed, rather than throwing and leaving the form
silently dead.

The asset also exposes `window.codeinRecaptcha`:

| Member | Purpose |
|---|---|
| `refresh(root)` | Wire up fields added after load — Turbo, Stimulus, an AJAX-loaded modal. Idempotent, so calling it on the whole document again is safe. |
| `whenReady(callback)` | Run a callback once `grecaptcha.enterprise` exists, for application code of your own. |

```js
// after injecting a form into the page
window.codeinRecaptcha.refresh(modal);
```

> ⚠️ **GDPR: with no loader there is no token**, so the constraint refuses every submission and the visitor is
> locked out of the form. `on_error: allow` does not rescue this — it covers an unreachable Google, while
> a missing token is a legitimate `MISSING` refusal. An application that omits the script until consent is given
> must also skip the constraint until then, with a validation group or by not adding the field at all.

### When the token cannot be fetched

If `grecaptcha.enterprise.execute()` rejects, or the loader never arrives within ten seconds — blocked, offline,
or held back by a consent manager — the bundle dispatches a cancelable `codein-recaptcha:error` event on the form
and then submits with an empty token, which the server refuses as `MISSING`. Cancel the event to keep
the submission blocked and handle it yourself:

```js
form.addEventListener('codein-recaptcha:error', function (event) {
    event.preventDefault(); // the form stays unsubmitted; show your own message
});
```

Submitting an empty token is deliberate: it is a refusal the application already reports through the constraint,
whereas a form left in a prevented state gives the visitor nothing at all.

### Showing the error

Render the field with `form_row()`, or let `form_widget(form)` render the whole form. Either way the theme's
`recaptcha_enterprise_row` block emits the violation above the field:

```twig
{{ form_row(form.recaptchaToken) }}
```

Calling `form_widget(form.recaptchaToken)` on its own renders the field **without** the error, as with any
Symfony field. The field inherits from `HiddenType`, whose `hidden_row` block renders the widget alone — so
without the bundle's own row block the visitor would be refused with no message at all. Errors do not reach
`form_errors(form)` either: `HiddenType` passes them to the parent, and the type sets `error_bubbling` back
to `false` so the message stays beside the widget.

Set `error_bubbling: true` on the field if you would rather collect the message in the form-level summary.

### Handling a failed captcha

The message the visitor sees is deliberately vague. Everything the application needs to react — the score,
the reason Google gave, whether Google answered at all — is attached to the violation, and the finder reads it back:

```php
use Codein\RecaptchaEnterpriseBundle\CaptchaFailure\FinderInterface;

public function __construct(private readonly FinderInterface $captchaFailures) {}

// ...
if ($form->isSubmitted() && !$form->isValid() && $this->captchaFailures->has($form)) {
    $failure = $this->captchaFailures->get($form);
    // react to $failure, then re-render the form
}
```

`has()` answers the question, `get()` returns the failure and throws a `NoFailureException` when there is none —
there is no null to guard against. Pass the form you validated: the whole tree is searched, so the captcha field
is never named and renaming it breaks nothing. Passing the field itself works too.

A failure is exactly one of three:

| | Meaning | `getScore()` | `getInvalidReason()` |
|---|---|---|---|
| `isInvalidToken()` | No token, or Google refused the one submitted | `null` | The reason, e.g. `EXPIRED` |
| `isLowScore()` | Genuine token, risk analysis below the threshold | The score | `null` |
| `isUnavailable()` | Google could not be asked at all | `null` | `null` |

`isUnavailable()` never fires under `on_error: allow`, which refuses nothing. The raw assessment stays available
as `$failure->result` or `$failure->getResult()`, and the violation as `$failure->violation`
or `$failure->getViolation()`.

The finder never looks at the challenge — it reads what the validator recorded, and that validator has a single
code path — but the two challenges do not fail the same way, so what is worth handling differs.

#### With the score challenge

All three outcomes are reachable. The interesting one is the low score: the token was genuine and Google
assessed it, the risk analysis simply stayed under `min_score`. There is nothing the visitor can do about it, so
the useful reaction is on your side — log it, raise a flag, ask for a second factor:

```php
if ($failure->isLowScore()) {
    $logger->warning('reCAPTCHA refused {score} on {action}', [
        'score' => $failure->getScore(),
        'action' => $failure->result->action,
    ]);
} elseif ($failure->isUnavailable()) {
    $this->addFlash('warning', 'The captcha service is unreachable, please try again in a moment.');
}
```

The failure then carries a full assessment — note that `success` and `valid` are both `true`, which is what
separates a low score from a refused token:

```text
violation  code = LOW_SCORE_ERROR, parameters {{ reason }} = "NONE", {{ score }} = "0.1"
result     success = true, valid = true, action = "contact", score = 0.1,
           invalidReason = null, error = null, raw = [the whole payload]
```

`$failure->result->raw` holds Google's untouched answer, so the risk analysis `reasons` are there for a log
line even though the bundle does not model them.

A low score is also where the hybrid pattern belongs. Rather than refusing outright, an application can step
the visitor up to a check of its own — an e-mail with a validation link, a moderation queue, a delayed publication.
Call `VerifierInterface::verify()` yourself for that, and do not also attach the constraint: the second
assessment of the same token comes back as `DUPE`.

#### With the checkbox challenge

With `min_score: 0` the score is never held to a threshold, so `isLowScore()` never fires, and a refused token
carries no risk analysis, so `getScore()` stays `null` — a property of the refusal rather than of checkbox keys,
which Google scores like any other. What to handle instead is that refused token, since the checkbox is only
valid for about two minutes:

```php
if ($failure->isInvalidToken()) {
    $this->addFlash('warning', match ($failure->getInvalidReason()) {
        InvalidReason::EXPIRED => 'Please tick the checkbox again, it expired.',
        InvalidReason::MISSING => 'Please tick the checkbox.',
        default => 'The captcha could not be verified, please try again.',
    });
}
```

Both reasons produce the same failure shape, but not the same story: `MISSING` never reached Google at all —
the field was empty, so `raw` is empty too — whereas `EXPIRED` comes back from a real assessment:

```text
violation  code = INVALID_TOKEN_ERROR, parameters {{ reason }} = "EXPIRED", {{ score }} = "null"
result     success = false, valid = false, action = null, score = null,
           invalidReason = InvalidReason::EXPIRED, error = null, raw = [the whole payload]
```

One trap: `isLowScore()` is unreachable here only because `min_score` is `0`. Leave the default `0.5` on
a checkbox key and a visitor who ticked the box is still refused whenever Google scores the interaction
below the threshold — with no second attempt to offer, since the challenge was already passed. Raise
the threshold above `0` only if the application answers a low score with something other than a refusal.

### Verification result

`VerifierInterface` can be used outside the validator. It is stateless: the verdict is the returned `Result`, never
something read back from the service afterwards.

```php
use Codein\RecaptchaEnterpriseBundle\Verifier\VerifierInterface;

public function __construct(private readonly VerifierInterface $verifier) {}

// ...
$result = $this->verifier->verify($token, 'contact');

$result->success;              // whether the token may be accepted, score aside
$result->valid;                // what Google said about the token itself
$result->score;                // null when the assessment carried no risk analysis
$result->invalidReason;        // an InvalidReason enum case, or null
$result->getInvalidReasonName(); // e.g. "EXPIRED"
$result->error;                // set only when no assessment could be obtained at all
$result->raw;                  // the untouched payload
```

Inside a validator, the same `Result` is attached to the violation as its cause, so
`$violation->getCause()` reaches it without calling the verifier again. From a controller, reach for
the finder described in "Handling a failed captcha" rather than unwrapping the form errors by hand.

Two shapes are easy to misread. An empty token never reaches Google: `verify('')` answers `MISSING` without
an HTTP call. And `success === true` together with a non-null `error` is an accepted outage under
`on_error: allow` — there is no score to judge, so treat it as a case to step up rather than as a pass.

### When Google cannot be reached

A network failure, a rate limit or a Google outage says nothing about the token, so the bundle treats it as its own
outcome instead of reporting a valid token as invalid. `on_error` decides what happens then:

| Value | Behaviour |
|---|---|
| `deny` (default) | The submission is refused. Safe, but a Google outage blocks every form. |
| `allow` | The submission passes without an assessment. Keeps forms working, and lets bots through while the outage lasts. |

Either way the failure is logged at error level, and the violation raised by `deny` carries
the `RecaptchaEnterprise::UNAVAILABLE_ERROR` code so it can be told apart from a genuinely refused token.

### What the bundle sends to Google

Beyond the token and the site key, the assessment event carries whatever the current request can supply.
All of it is optional to Google and omitted when empty:

| Field | Source | Why it matters |
|---|---|---|
| `expectedAction` | the `action_name` form option | Rejects a token minted for another action |
| `userIpAddress` | `Request::getClientIp()` | Feeds IP reputation into the risk analysis |
| `userAgent` | the `User-Agent` header | Feeds device signals into the risk analysis |
| `requestedUri` | `Request::getUri()` | Tells Google which page triggered the assessment |

> ⚠️ **Configure `framework.trusted_proxies`.** Behind a reverse proxy, a load balancer or a Docker network,
> `getClientIp()` returns the proxy's address, so Google scores every visitor from a single internal IP
> and the risk analysis degrades as traffic grows. A private address such as `10.x`, `172.16-31.x` or `192.168.x` in
> `event.userIpAddress` is the symptom.

`requestedUri` is the full URI including the query string. If your form pages carry anything sensitive there,
that value reaches Google.

Architecture
------------

Verification is split in two, so that deciding and talking to Google never mix:

- `Verifier` holds every decision: the empty token short circuit, the expected action check, the score-free
  outage policy. It knows nothing about HTTP.
- `GatewayInterface` is the port to Google. An implementation only translates the wire format
  into an `Assessment` value object, and raises a domain exception when there is no assessment to translate.

`HttpGateway` is the one implementation, calling the REST Assessments API through Symfony's HTTP client.
The official `google/cloud-recaptcha-enterprise` SDK is deliberately not used: the bundle makes a single unary call,
for which the SDK adds only a protobuf and gRPC stack, and its `google/gax` dependency requires
`ramsey/uuid ^4`, which cannot be installed alongside applications held at `ramsey/uuid` 3.x — Ibexa 4.6 among
them. Another gateway can be added behind the port without the domain noticing.

The gateway throws, rather than returning a failed assessment, whenever Google did not answer with one:

| Exception | Cause |
|---|---|
| `TransportException` | Network failure, undecodable body, rate limit (429) or server error (5xx) — all transient |
| `AuthenticationException` | 401 or 403: a missing, wrong or unauthorised API key |
| `InvalidRequestException` | 400 or 404: an unknown project or a malformed event |

All three implement `AssessmentExceptionInterface`. `Verifier` catches it, so no exception ever escapes into form
validation.

The split holds on the way back too: the `CaptchaFailure\Finder` only reads what the validator recorded on
the violation. It decides nothing, which is why it needs no configuration and no dependencies.

Development
-----------

Everything runs in Docker, so no local PHP or Composer is needed:

```shell
$ make install   # build the image and install the bundle and tool dependencies
$ make test      # run the test suite
$ make phpstan   # run the static analysis (level 9)
$ make cs        # check the coding standards
$ make cs-fix    # fix the coding standards
$ make qa        # run all of the above
```

The default stack is the lowest supported one, PHP 8.1 with `--prefer-lowest --prefer-stable`, which is what proves
the declared requirements hold. Override it to work against a newer stack:

```shell
$ make update-latest PHP_VERSION=8.4
$ make test PHP_VERSION=8.4
```

`composer.lock` is not committed. This is a library, so consumers resolve their own dependency versions and a committed
lock file would only mislead the matrix build.

Issues and pull requests are welcome on
[Codein-Labs/recaptcha-enterprise-bundle](https://github.com/Codein-Labs/recaptcha-enterprise-bundle).

Upgrading from artack/recaptcha-enterprise-bundle:0.2.0
------------------------------------------------------

Start with the rename. Every identifier carrying the old vendor changed, and nothing else in this table has any
behaviour attached to it:

| What | Old | New |
|---|---|---|
| Composer package | `artack/recaptcha-enterprise-bundle` | `codein/recaptcha-enterprise-bundle` |
| PHP namespace | `Artack\RecaptchaEnterpriseBundle\` | `Codein\RecaptchaEnterpriseBundle\` |
| Bundle class | `ArtackRecaptchaEnterpriseBundle` | `CodeinRecaptchaEnterpriseBundle` |
| Configuration key | `artack_recaptcha_enterprise` | `codein_recaptcha_enterprise` |
| Configuration file | `config/packages/artack_recaptcha_enterprise.yaml` | `config/packages/codein_recaptcha_enterprise.yaml` |
| Environment variables | `ARTACK_RECAPTCHA_ENTERPRISE_*` | `CODEIN_RECAPTCHA_ENTERPRISE_*` |
| Published asset | `bundles/artackrecaptchaenterprise/…` | `bundles/codeinrecaptchaenterprise/…` |
| Readiness callback | `artackRecaptchaOnload` | `codeinRecaptchaOnload` |
| JavaScript global | `window.artackRecaptcha` | `window.codeinRecaptcha` |
| DOM attributes | `data-artack-recaptcha*` | `data-codein-recaptcha*` |
| Failure event | `artack-recaptcha:error` | `codein-recaptcha:error` |
| Twig theme namespace | `@ArtackRecaptchaEnterprise` | `@CodeinRecaptchaEnterprise` |

Then the changes a rename does not cover. "What changed from artack/recaptcha-enterprise-bundle" explains why
each of these moved; this is the checklist:

- **Add the script tags to your layout.** The bundle no longer loads `enterprise.js`, and it no longer emits any
  inline JavaScript. Without both tags every submission is refused as `MISSING`. See "Adding the scripts to your
  layout". This is the one step that cannot be skipped.
- **Run `assets:install`.** The submission handling is a published asset now.
- **Drop any wiring around `Artack\RecaptchaEnterpriseBundle\Service\`.** `IpResolver`, `IpResolverInterface`,
  `UserAgentResolver` and `UserAgentResolverInterface` are gone; the verifier reads the request stack directly.
- **Replace `VerifierInterface::getLatestResult()`** with the `Result` that `verify()` returns, or with
  `$violation->getCause()` inside a validator.
- **Rebuild any hand-constructed `Verifier`.** It takes a `GatewayInterface` instead of the project id, site key,
  API key and the two resolvers. Code going through the container is unaffected.
- **Rebuild any hand-constructed `Result`.** `$raw` moved in the constructor and `$invalidReason`, `$error`
  and `getInvalidReasonName()` were added; `$invalidReason` is an `InvalidReason` enum case. Use named arguments.
- **Rename `InvalidReason::fromName()` to `fromApiValue()`**, which is what it always did.
- **Remove the `locale`, `script_csp_nonce` and per-field `challenge` form options.** `hl=` now goes on your own
  script tag, there is no inline script left to nonce, and `challenge` is a bundle setting.
- **Drop any custom row block** you added to make the violation appear; the theme defines
  `recaptcha_enterprise_row` itself. Set `error_bubbling: true` on the field if you relied on the message
  reaching `form_errors(form)`.
- **Review `min_score`.** The score check fails closed now: an assessment with no risk analysis is refused
  rather than passed. `min_score: 0` restores the old behaviour.
- **Pass `message:` to the constraint** to keep the old default wording, `You may be sending automated
  requests.`

Nothing else in an existing integration needs to change. `RecaptchaEnterpriseType`, the `RecaptchaEnterprise`
constraint and `VerifierInterface::verify()` keep their names and their signatures.

License
-------

This bundle is released under the [MIT License](LICENSE).
