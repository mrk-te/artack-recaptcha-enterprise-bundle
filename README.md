artack/recaptcha-enterprise-bundle
=================================

> Symfony integration for Google reCAPTCHA Enterprise (Assessments API).

[![Latest Release](https://img.shields.io/packagist/v/artack/recaptcha-enterprise-bundle.svg)](https://packagist.org/packages/artack/recaptcha-enterprise-bundle)
[![MIT License](https://img.shields.io/packagist/l/artack/recaptcha-enterprise-bundle.svg)](http://opensource.org/licenses/MIT)
[![Total Downloads](https://img.shields.io/packagist/dt/artack/recaptcha-enterprise-bundle.svg)](https://packagist.org/packages/artack/recaptcha-enterprise-bundle)

Developed by [ARTACK WebLab GmbH](https://www.artack.ch) in Zurich, Switzerland.

Features
--------

- Provides the **RecaptchaEnterpriseType** form type, rendering either the invisible score challenge
  or the "I'm not a robot" checkbox, and carrying the token in a field the constraint can actually see.
- Ships a **RecaptchaEnterprise** validation constraint for attributes and PHP configuration, including configurable
  score threshold and action names.
- Automatically resolves the client IP, User-Agent and requested URI from Symfony's request stack and forwards
  them to Google when available, so the risk analysis has more than a bare token to work with.
- Registers the form theme automatically, so no manual Twig configuration is required.
- Separates deciding from calling: a `Verifier` holding the policy, and a gateway port with a single HTTP
  implementation, so an unreachable API is never mistaken for an invalid token.

Requirements
------------

| Requirement | Supported versions |
|---|---|
| PHP | 8.2, 8.3, 8.4 |
| Symfony | 5.4 LTS, 6.4 LTS, 7.4 LTS, 8.x |

Every combination is covered by the CI matrix, together with a `--prefer-lowest` build that proves the declared minimums
actually install and work.

Symfony 5.4 is end of life upstream but supported here on purpose. Its components raise PHP deprecations that the bundle
cannot fix, so the test suite does not fail on deprecations.

Installation
------------

Install the bundle via [Composer](https://getcomposer.org):

```shell
$ composer require artack/recaptcha-enterprise-bundle
```

A [Flex recipe](https://github.com/symfony/recipes-contrib/tree/main/artack/recaptcha-enterprise-bundle) registers
the bundle in `config/bundles.php`, writes `config/packages/artack_recaptcha_enterprise.yaml` and adds the three
environment variables to `.env`. Flex applies the highest recipe version not above the installed one, so the `0.1`
recipe covers the whole `0.x` line. It cannot add the loader to your layout, which is the third step below.

> ⚠️ This bundle is being used in production, but hasn't reached version 1.0 yet. Therefore, there can be breaking
> changes between minor versions. I'd recommend that you require the bundle only with the current minor version like
> `composer require artack/recaptcha-enterprise-bundle:0.2.*`, and read "Upgrading from 0.2.0" before moving on.

### Installing without a recipe

Flex registers bundles from a recipe manifest only — `"type": "symfony-bundle"` alone does **not** make it happen.
So a fork, a renamed package or an install from a VCS repository gets no recipe, and the two steps it performs
have to be done by hand:

```php
// config/bundles.php
return [
    // ...
    Artack\RecaptchaEnterpriseBundle\ArtackRecaptchaEnterpriseBundle::class => ['all' => true],
];
```

Then create `config/packages/artack_recaptcha_enterprise.yaml` as shown below. The symptom of forgetting the first
step is that the configuration key is rejected as unrecognised, since an unregistered bundle has no extension.

### Adding the loader to your layout

**The bundle never places Google's script on a page — the application does.** This is part of installing the
bundle, not an optional extra: with no loader there is no token, so the constraint refuses every submission as
`MISSING` and the visitor is locked out of the form.

Expose the site key to Twig:

```yaml
# config/packages/twig.yaml
twig:
    globals:
        recaptcha_site_key: '%artack_recaptcha_enterprise.site_key%'
```

Then add the loader once per page, in your layout, after the visitor has consented to Google:

```twig
{# templates/base.html.twig — score challenge #}
<script src="https://www.google.com/recaptcha/enterprise.js?render={{ recaptcha_site_key }}&onload=___artackRecaptchaOnload"
        async defer></script>
```

The checkbox challenge uses `render=explicit` instead of the site key. See "Loading enterprise.js" for that
variant, for the `hl=` language parameter, for the `___artackRecaptchaOnload` readiness contract, and for what an
application must do while consent is absent.

Configuration
-------------

Create `config/packages/artack_recaptcha_enterprise.yaml` with your Google project credentials:

```yaml
# config/packages/artack_recaptcha_enterprise.yaml
artack_recaptcha_enterprise:
    enabled: '%env(resolve:ARTACK_RECAPTCHA_ENTERPRISE_ENABLED)%' # defaults to true
    site_key: '%env(resolve:ARTACK_RECAPTCHA_ENTERPRISE_SITE_KEY)%'
    project_id: '%env(resolve:ARTACK_RECAPTCHA_ENTERPRISE_PROJECT_ID)%'
    api_key: '%env(resolve:ARTACK_RECAPTCHA_ENTERPRISE_API_KEY)%'
    min_score: 0.5 # default score threshold used by the validator when none is provided
    challenge: score # score (default) or checkbox, see "Choosing the challenge"
    on_error: deny # deny (default) or allow, see "When Google cannot be reached"
    http_client_service: artack_recaptcha_enterprise.client # see "Configuring the HTTP client"

when@dev:
    artack_recaptcha_enterprise:
        enabled: false # disable reCAPTCHA in dev environments
```

`site_key`, `project_id` and `api_key` are required. `min_score` defaults to `0.5` and is used when a constraint
does not define its own threshold; set it to `0` to disable the score check entirely, which is what checkbox keys
without score based protection need.

### Configuring the HTTP client

The bundle declares its own scoped client, `artack_recaptcha_enterprise.client`, and calls Google through it:

```yaml
framework:
    http_client:
        scoped_clients:
            artack_recaptcha_enterprise.client:
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
            artack_recaptcha_enterprise.client:
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
artack_recaptcha_enterprise:
    http_client_service: my_app.google_client
```

### Choosing the challenge

`challenge` sets what the form type renders, for the whole application. It is not a form option:
`site_key` is a single global value and the two challenges need different key types, so a field asking
for the other one would send the wrong key and Google would refuse it with `KEY_MISMATCH`.

| Value | What the visitor sees | What Google returns |
|---|---|---|
| `score` (default) | Nothing. The token is fetched on submit. | A risk analysis score, judged against `min_score`. |
| `checkbox` | The "I'm not a robot" checkbox. | A valid or invalid token; a score only if the key is configured for it. |

> ⚠️ The two are **not interchangeable**: a score key and a checkbox key are different key types in the Google
> console. Pointing `site_key` at the wrong one makes every assessment fail with `KEY_MISMATCH`.

Checkbox keys often carry no risk analysis. Since the score check fails closed, set `min_score: 0` unless the key
is explicitly configured to return a score:

```yaml
artack_recaptcha_enterprise:
    challenge: checkbox
    site_key: '%env(ARTACK_RECAPTCHA_ENTERPRISE_SITE_KEY)%' # must be a checkbox key
    min_score: 0
```

The checkbox is rendered explicitly rather than through the usual `<div class="g-recaptcha">`. Auto-rendering
posts the token in a `g-recaptcha-response` field at the root of the form data, outside the Symfony field name
prefix, where the constraint would never see it. Explicit rendering writes the token into the bundle's own hidden
field instead, so the validator, the verifier and the constraint stay unaware of which challenge was used.

Checkbox tokens expire after about two minutes, and the field is cleared when that happens, so a stale token is
never submitted.

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
> `getClientIp()` returns the proxy's address, so Google scores every visitor from a single internal IP and the
> risk analysis degrades as traffic grows. A private address such as `10.x`, `172.16-31.x` or `192.168.x` in
> `event.userIpAddress` is the symptom.

`requestedUri` is the full URI including the query string. If your form pages carry anything sensitive there,
that value reaches Google.

### Verification result

`VerifierInterface` can be used outside the validator. It is stateless: the verdict is the returned `Result`, never
something read back from the service afterwards.

```php
use Artack\RecaptchaEnterpriseBundle\Verifier\VerifierInterface;

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
`$violation->getCause()` reaches it without calling the verifier again.

Usage
-----

Render the token field in a Symfony form:

```php
use Artack\RecaptchaEnterpriseBundle\Form\RecaptchaEnterpriseType;
use Artack\RecaptchaEnterpriseBundle\Validator\RecaptchaEnterprise;
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
| `script_csp_nonce` | `null` | both | Nonce applied to every emitted script tag |
| `theme` | `'light'` | checkbox | `light` or `dark` |
| `size` | `'normal'` | checkbox | `normal` or `compact` |

`challenge` is **not** a form option. Google supports one `enterprise.js` load per page and its `render=`
parameter takes one value, so the challenge is a bundle setting and every field on a page shares it.

The Twig theme is prepended automatically. In the `score` challenge the bundle calls
`grecaptcha.enterprise.execute` on submit, fills the hidden field and resubmits with `requestSubmit()`, which
preserves the clicked button and runs the other submit listeners. In the `checkbox` challenge the token is written
into the hidden field by the widget callback as soon as the visitor solves it.

To restyle one challenge without touching the other, override the `recaptcha_enterprise_score_widget`
or `recaptcha_enterprise_checkbox_widget` block rather than `recaptcha_enterprise_widget`, which only
dispatches between them. Both call `recaptcha_enterprise_bootstrap`, which emits the readiness queue every
field waits on.

### Showing the error

Render the field with `form_row()`, or let `form_widget(form)` render the whole form. Either way the theme's
`recaptcha_enterprise_row` block emits the violation above the field:

```twig
{{ form_row(form.recaptchaToken) }}
```

Calling `form_widget(form.recaptchaToken)` on its own renders the field **without** the error, as with any
Symfony field. The field inherits from `HiddenType`, whose `hidden_row` block renders the widget alone — so
without the bundle's own row block the visitor would be refused with no message at all. Errors do not reach
`form_errors(form)` either: `error_bubbling` defaults to `false` on a non-compound field.

Set `error_bubbling: true` on the field if you would rather collect the message in the form-level summary.

### Loading enterprise.js

**The bundle never loads Google's script — the application does.** The bundle cannot know whether the visitor
consented to Google, and a script placed on the page without consent is the application's liability, so this is
deliberately not configurable: a flag would still ship a default that loads it.

"Adding the loader to your layout" gives the minimum; this is the full contract. Add the loader once per page,
after consent, with `onload=___artackRecaptchaOnload`:

```twig
{# score: the site key is bound to the loader at load time #}
<script src="https://www.google.com/recaptcha/enterprise.js?render={{ site_key }}&hl=fr&onload=___artackRecaptchaOnload"
        async defer></script>

{# checkbox: the widgets are rendered explicitly, one per field #}
<script src="https://www.google.com/recaptcha/enterprise.js?render=explicit&hl=fr&onload=___artackRecaptchaOnload"
        async defer></script>
```

The two tags are not interchangeable: the `render=` value follows the `challenge` setting, and a page must never
carry both — which the single `site_key` already prevents. `hl=` is yours to set, and omitting it lets Google
detect the language from the browser.

`___artackRecaptchaOnload` is public API. It is the only supported readiness signal: `grecaptcha.enterprise.ready()`
does not queue callbacks registered before the library exists, so the bundle queues everything itself and drains
the queue when the callback fires. Nothing depends on the two scripts landing in a given order — a library that is
already there is detected directly, and a callback that fired before the bundle's own script ran is caught by a
short poll.

Any number of fields of the configured challenge can then appear on one page: several score fields share the
single bound key, several checkbox fields each render into their own container. A visitor who submits before the
library has landed is safe — the submission is held and replayed, rather than throwing and leaving the form
silently dead.

> ⚠️ **GDPR: with no loader there is no token**, so the constraint refuses every submission and the visitor is
> locked out of the form. `on_error: allow` does not rescue this — it covers an unreachable Google, while a
> missing token is a legitimate `MISSING` refusal. An application that omits the script until consent is given
> must also skip the constraint until then, with a validation group or by not adding the field at all.

### When the token cannot be fetched

If `grecaptcha.enterprise.execute()` rejects, or the loader never arrives within ten seconds — blocked, offline,
or held back by a consent manager — the bundle dispatches a cancelable `artack-recaptcha:error` event on the form
and then submits with an empty token, which the server refuses as `MISSING`. Cancel the event to keep
the submission blocked and handle it yourself:

```js
form.addEventListener('artack-recaptcha:error', function (event) {
    event.preventDefault(); // the form stays unsubmitted; show your own message
});
```

Submitting an empty token is deliberate: it is a refusal the application already reports through the constraint,
whereas a form left in a prevented state gives the visitor nothing at all.

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

The default stack is the lowest supported one, PHP 8.2 with `--prefer-lowest --prefer-stable`, which is what proves
the declared requirements hold. Override it to work against a newer stack:

```shell
$ make update-latest PHP_VERSION=8.4
$ make test PHP_VERSION=8.4
```

`composer.lock` is not committed. This is a library, so consumers resolve their own dependency versions and a committed
lock file would only mislead the matrix build.

Upgrading from 0.2.0
--------------------

`0.2.0` is the last release, and everything below changed since. The bundle is pre-1.0, so these breaks land
without a deprecation cycle.

**One change is required**: the application now loads `enterprise.js` itself, see "Loading enterprise.js". The
configuration keys are otherwise untouched — `enabled`, `site_key`, `project_id`, `api_key` and `min_score` keep
their names and meanings, and `on_error` and `http_client_service` are new and optional.

### Behaviour

- **A re-rendered form no longer carries its token back.** The hidden field used to be re-rendered with
  the submitted value, and Google refuses a replayed token with `DUPE` — so any validation failure elsewhere
  on the form locked the visitor out for good. The field now always renders empty.
- **The assessment call goes through a scoped client**, `artack_recaptcha_enterprise.client`, which the bundle
  prepends onto `framework.http_client` with a two second timeout and a five second `max_duration`. The call
  previously inherited `default_socket_timeout`, so an unresponsive Google held the worker instead of reaching
  the `on_error` policy. Redeclare that key to change the timeouts or anything else about the transport.
- **The bundle no longer loads `enterprise.js`.** It cannot know whether the visitor consented to Google, so the
  application adds the loader itself, with `onload=___artackRecaptchaOnload`, and decides when. Without it there
  is no token and every submission is refused as `MISSING`. This also fixes the two score fields on one page that
  used to produce two `enterprise.js` tags, which Google does not support. See "Loading enterprise.js".
- **The score check now fails closed.** An assessment carrying no risk analysis used to pass the threshold
  silently; it is now refused. Set `min_score: 0` to keep the old behaviour, which is also what checkbox keys
  without score based protection need.
- **An unreachable Google is no longer reported as an invalid token.** It used to surface as a failed assessment;
  it is now its own outcome, governed by `on_error` and defaulting to `deny` — the same refusal as before, but
  distinguishable and logged.
- **The violation is now rendered.** The field inherits from `HiddenType`, so `form_row()` fell through
  to `hidden_row`, which emits the widget alone — the message was raised and silently dropped. The theme now
  defines `recaptcha_enterprise_row`. If you worked around this with your own row block, drop it.
- **The default message is now `The captcha did not validate.`**, previously
  `You may be sending automated requests.` Pass `message:` to the constraint to keep the old wording.
- **Violations now carry context**: the `{{ reason }}` and `{{ score }}` parameters, the `Result` as the violation
  cause, and one of `RecaptchaEnterprise::INVALID_TOKEN_ERROR`, `LOW_SCORE_ERROR` or `UNAVAILABLE_ERROR`
  as the code. Custom messages using those placeholders keep working; nothing is required to keep the plain message.

### API

- **`Artack\RecaptchaEnterpriseBundle\Service\` is removed** — `IpResolver`, `IpResolverInterface`,
  `UserAgentResolver` and `UserAgentResolverInterface`. `Verifier` reads the client IP and User-Agent
  from the request stack directly. Applications that decorated or replaced those services must drop that wiring.
- **`Verifier` takes a `GatewayInterface`** instead of the project id, site key, API key and the two resolvers.
  Code relying on the container or on `VerifierInterface` is unaffected; code instantiating `Verifier` by hand
  must build a `HttpGateway` first.
- **`Result` changed shape.** `$success`, `$valid`, `$action`, `$score` and `$raw` are unchanged, but `$raw` moved
  in the constructor and `$invalidReason`, `$error` and `getInvalidReasonName()` were added. Build one with named
  arguments. `$invalidReason` is an `InvalidReason` enum case, not a string or an int.
- **`VerifierInterface::getLatestResult()` is removed.** The verifier is a shared service, so holding the last
  result leaked it across requests in a long-running worker and was ambiguous once two fields were validated in
  one request. Use the `Result` returned by `verify()`, or `$violation->getCause()` inside a validator.
- **`InvalidReason::fromName()` is now `fromApiValue()`**, which is what it always did: it matches on the value
  the API sent, not on the PHP case name.
- **The `challenge` and `locale` form options are removed.** `challenge` is a bundle setting, which it already
  was; a per-field override never worked, since it kept the single global `site_key` and so sent a key of the
  wrong type. `locale` is gone entirely: it only ever produced the loader's `hl=`, which the application now
  writes on its own script tag.
- **`RecaptchaEnterpriseType` takes a third constructor argument**, the challenge. Only code instantiating the
  type by hand is affected.

### Features

- **`requestedUri` is now sent** with the assessment, taken from `Request::getUri()`. It is an optional input
  to the risk analysis; see "What the bundle sends to Google" if the query string is sensitive on your pages.
- The `checkbox` challenge is new. Nothing changes for existing applications: `challenge` defaults to `score`,
  which is the behaviour 0.2.0 had.
- A failed or timed-out token fetch now dispatches a cancelable `artack-recaptcha:error` event on the form
  instead of leaving the submission blocked with no message.
- The checkbox container's class is `recaptcha-enterprise__widget`. It was `g-recaptcha-enterprise`, which read
  as a Google-owned hook.

### Requirements

- Symfony `5.4`, `6.4` and `7.4` are now supported alongside `8.x`; `7.0` to `7.3` are not, the supported 7.x line
  is the LTS. PHP stays at `^8.2`.
- `symfony/http-foundation` is now an explicit requirement. It was already installed in practice, through
  `symfony/framework-bundle`.

License
-------

This bundle is released under the [MIT License](LICENSE).
