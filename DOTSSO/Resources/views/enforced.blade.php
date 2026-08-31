{{--
    Rendered into core's login page via 'login_form.before' when SSO is
    enforced.

    Core exposes no hook for hiding the email/password fields, and patching
    the core view would be undone by the next FreeScout upgrade. So the form
    is hidden from here instead: CSS does the work (so the fields never paint,
    rather than flashing and then vanishing), and a small script moves the SSO
    block up into where the form was and drops the now-meaningless required
    attributes so the hidden inputs cannot block submission.

    The password POST is refused server-side regardless of any of this - see
    the login.custom_check filter in DOTSSOServiceProvider. This is honesty
    about what the page can do, not the control itself.
--}}
<style>
    /* Hide the credential fields, the Remember Me box and the Login button.
       Targeted by the wrapper each field sits in so no stray labels remain. */
    form[action$="/login"] .form-group,
    form[action$="/login"] .checkbox {
        display: none !important;
    }

    /* The form itself keeps its box, but with nothing in it there is no
       reason for the margin it would otherwise contribute. */
    form[action$="/login"] {
        margin: 0 !important;
    }

    .dotsso-enforced .dotsso-divider {
        display: none;
    }

    .dotsso-enforced .dotsso-block {
        margin-top: 0;
    }

    .dotsso-lead {
        text-align: center;
        color: #666;
        margin: 0 0 18px 0;
    }
</style>

<div class="dotsso-lead">{{ __('Sign in with your DOT Google account.') }}</div>

<script>
(function () {
    // The hidden email/password inputs keep their required attributes, which
    // would make the (invisible) form unsubmittable if anything ever did
    // trigger it. Strip them once the DOM is up.
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[action$="/login"]');
        if (!form) {
            return;
        }

        form.querySelectorAll('[required]').forEach(function (el) {
            el.removeAttribute('required');
        });

        // Autofocus on a hidden field steals the caret from the page.
        form.querySelectorAll('[autofocus]').forEach(function (el) {
            el.removeAttribute('autofocus');
        });

        document.body.classList.add('dotsso-enforced');
    });
})();
</script>
