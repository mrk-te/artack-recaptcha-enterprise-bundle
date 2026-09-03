/*
 * codein/recaptcha-enterprise-bundle — submission handling for the form theme.
 *
 * Install it with `assets:install`, then load it from your layout:
 *
 *     <script src="{{ asset('bundles/codeinrecaptchaenterprise/recaptcha-enterprise.js') }}" defer></script>
 *
 * The bundle never loads Google's enterprise.js: the application adds that tag itself, after the
 * visitor has consented, with `onload=codeinRecaptchaOnload`.
 */
(function () {
    'use strict';

    if (window.codeinRecaptcha) {
        return;
    }

    var FIELD = '[data-codein-recaptcha]';
    var READY_TIMEOUT = 10000;
    var POLL_INTERVAL = 100;

    var queue = [];
    var loaded = false;
    var poll = null;

    function library() {
        return Boolean(window.grecaptcha && window.grecaptcha.enterprise);
    }

    function drain() {
        loaded = true;
        window.clearInterval(poll);

        var pending = queue;
        queue = [];
        pending.forEach(function (callback) {
            callback();
        });
    }

    /*
     * grecaptcha.enterprise.ready() does not queue callbacks registered before the library exists,
     * so everything needing it goes through here instead. The loader's onload= is the documented
     * readiness signal; the poll covers a loader that fired it before this file ran, which happens
     * whenever the application puts its own tag first.
     */
    function whenReady(callback) {
        if (loaded || library()) {
            grecaptcha.enterprise.ready(callback);

            return;
        }

        queue.push(function () {
            grecaptcha.enterprise.ready(callback);
        });

        if (null === poll) {
            poll = window.setInterval(function () {
                if (library()) {
                    drain();
                }
            }, POLL_INTERVAL);
        }
    }

    function fields(root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(FIELD));
    }

    function scoreFields(form) {
        return fields(form).filter(function (field) {
            return 'checkbox' !== field.getAttribute('data-codein-recaptcha');
        });
    }

    function parameters(field) {
        var action = field.getAttribute('data-codein-recaptcha-action');

        // An unset action must be omitted, not sent as an empty string.
        return action ? {action: action} : {};
    }

    function renderCheckbox(field) {
        var container = document.querySelector('[data-codein-recaptcha-container="' + field.id + '"]');

        if (!container) {
            return;
        }

        whenReady(function () {
            var options = {
                sitekey: field.getAttribute('data-codein-recaptcha-sitekey'),
                theme: container.getAttribute('data-codein-recaptcha-theme') || 'light',
                size: container.getAttribute('data-codein-recaptcha-size') || 'normal',
                callback: function (token) {
                    field.value = token;
                },
                // Checkbox tokens expire after about two minutes, so the stale one must go rather
                // than be submitted and refused.
                'expired-callback': function () {
                    field.value = '';
                },
                'error-callback': function () {
                    field.value = '';
                }
            };

            var action = field.getAttribute('data-codein-recaptcha-action');

            if (action) {
                options.action = action;
            }

            // getResponse() and reset() need the widget id, which is the only handle on a widget
            // once several of them share a page.
            field.codeinRecaptchaWidget = grecaptcha.enterprise.render(container, options);
        });
    }

    function bindForm(form) {
        if (!form || form.codeinRecaptchaBound) {
            return;
        }

        form.codeinRecaptchaBound = true;

        var resubmitting = false;
        var pending = false;

        form.addEventListener('submit', function (event) {
            if (resubmitting) {
                return;
            }

            event.preventDefault();

            if (pending) {
                return;
            }

            pending = true;

            var submitter = event.submitter;
            var settled = false;
            var timer = null;

            function resubmit() {
                resubmitting = true;

                try {
                    // requestSubmit keeps the clicked button's name and value, and runs the other
                    // submit listeners; submit() does neither.
                    if ('function' === typeof form.requestSubmit) {
                        form.requestSubmit(submitter);
                    } else {
                        form.submit();
                    }
                } finally {
                    resubmitting = false;
                    pending = false;
                }
            }

            function settle() {
                if (settled) {
                    return;
                }

                settled = true;
                window.clearTimeout(timer);
                resubmit();
            }

            function abandon() {
                if (settled) {
                    return;
                }

                /*
                 * Submitting an empty token is a legitimate MISSING refusal that the server already
                 * reports; a form left prevented with no message is not. Cancel the event to keep
                 * the submission blocked and handle it in the application.
                 */
                var failure = new CustomEvent('codein-recaptcha:error', {bubbles: true, cancelable: true});

                if (form.dispatchEvent(failure)) {
                    scoreFields(form).forEach(function (field) {
                        field.value = '';
                    });

                    settle();

                    return;
                }

                settled = true;
                window.clearTimeout(timer);
                pending = false;
            }

            // The loader may never arrive — blocked, offline, or a consent manager holding it back
            // — and the submission must not be lost with it.
            timer = window.setTimeout(abandon, READY_TIMEOUT);

            whenReady(function () {
                try {
                    var tokens = scoreFields(form).map(function (field) {
                        var key = field.getAttribute('data-codein-recaptcha-sitekey');

                        return grecaptcha.enterprise.execute(key, parameters(field)).then(function (token) {
                            field.value = token;
                        });
                    });

                    Promise.all(tokens).then(settle).catch(abandon);
                } catch (error) {
                    abandon();
                }
            });
        });
    }

    function initialise(field) {
        if (field.codeinRecaptchaBound) {
            return;
        }

        field.codeinRecaptchaBound = true;

        // A back-navigation can have the browser restore a spent token into the field, and Google
        // refuses a replayed token with DUPE.
        field.value = '';

        if ('checkbox' === field.getAttribute('data-codein-recaptcha')) {
            renderCheckbox(field);

            return;
        }

        bindForm(field.form);
    }

    window.codeinRecaptchaOnload = drain;

    window.codeinRecaptcha = {
        whenReady: whenReady,
        // Fields added after load — Turbo, Stimulus, an AJAX-loaded modal — are picked up here.
        refresh: function (root) {
            fields(root).forEach(initialise);
        }
    };

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', function () {
            window.codeinRecaptcha.refresh();
        });
    } else {
        window.codeinRecaptcha.refresh();
    }
})();
