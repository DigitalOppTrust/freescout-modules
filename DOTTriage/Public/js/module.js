/* Triage module scripts */
(function () {
    'use strict';

    // Connection test on the settings page: shows a live result without a
    // full page reload, so the key can be checked as soon as it is set.
    var btn = document.getElementById('triage-test-connection');
    if (!btn) {
        return;
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        var out = document.getElementById('triage-connection-result');
        btn.disabled = true;
        out.innerHTML = '<span class="triage-meta">Testing…</span>';

        fetch(btn.dataset.url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var cls = d.ok ? 'ok' : (d.status === 'rate_limited' ? 'warn' : 'fail');
                out.innerHTML =
                    '<span class="triage-status ' + cls + '"><span class="dot"></span>' +
                    (d.ok ? 'Connected' : 'Not connected') + '</span> ' +
                    '<span class="triage-meta">' + d.detail +
                    (d.latency_ms ? ' (' + d.latency_ms + 'ms)' : '') + '</span>';
            })
            .catch(function () {
                out.innerHTML = '<span class="triage-status fail"><span class="dot"></span>Test failed</span>';
            })
            .finally(function () { btn.disabled = false; });
    });
})();
