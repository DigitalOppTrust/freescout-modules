<p class="dothelp-intro">
    Where every setting lives, and — importantly — which ones cannot be changed from any screen
    at all.
</p>

<h3>Manage → Triage</h3>

<p>The main control panel. It holds:</p>

<ul>
    <li>
        <strong>Agents</strong> — one row per person, showing what they handle, their keywords,
        rotation group, escalation target, SLA window and routing status
        (<em>Routing</em>, <em>Away</em>, <em>Full</em>, or <em>Off</em>). Clicking through opens
        that agent's profile, which is where the "Handles" description is written. That
        description is what the model reasons over, so its specificity determines routing
        quality more than any other setting.
    </li>
    <li>
        <strong>Automatic closing</strong> — the three rules, their timers, the confidence
        threshold, a per-run cap, and an option to never close an assigned ticket. A
        <em>Preview what would close</em> button shows what a sweep would act on right now
        without acting.
    </li>
    <li>
        <strong>Data retention</strong> — permanent deletion of old closed tickets. Switched off,
        and never scheduled: it only ever runs when someone runs it by hand on the server.
    </li>
    <li>
        <strong>Claude connection</strong> — the model in use, whether triage is enabled, whether
        it assigns or only suggests, calls used today, measured accuracy, and a
        <em>Test connection</em> button.
    </li>
</ul>

<div class="dothelp-callout dothelp-callout-warn">
    <p class="dothelp-callout-last">
        <strong>The closing preview is not free.</strong> Its "looks resolved" section asks the
        model about each candidate ticket live, so opening the page makes real API calls. The
        other two sections cost nothing.
    </p>
</div>

<h3>Manage → DOTLog</h3>
<p>Retention period for the debugging timeline, and the log itself. See
   <a href="{{ route('dothelp.topic', 'dotlog') }}">Debugging mail</a>.</p>

<h3>Manage → MCP</h3>
<p>Who may connect an AI client, at what level, and their active connections. See
   <a href="{{ route('dothelp.topic', 'mcp') }}">AI access to the desk</a>.</p>

<h3>Reports</h3>
<p>No settings. Everything is a URL parameter — period, date range, mailbox.</p>

<h3>What no screen can change</h3>

<p>
    These are read from the server's configuration file and require someone with server access.
    They are worth knowing because they explain behaviour that otherwise looks arbitrary:
</p>

<table class="table dothelp-table">
    <thead>
        <tr><th>Setting</th><th>Effect</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>Triage on/off</td>
            <td>
                The master switch. When off, the settings page still works — so the module can be
                diagnosed while switched off — but nothing is routed or swept.
            </td>
        </tr>
        <tr>
            <td>Assign, or only suggest</td>
            <td>
                Whether a confident decision is acted on or merely noted. This is the single
                setting that most changes what agents experience.
            </td>
        </tr>
        <tr>
            <td>Confidence threshold</td>
            <td>How sure the model must be before a suggestion is applied.</td>
        </tr>
        <tr>
            <td>Which model, and the daily call cap</td>
            <td>Cost and behaviour of routing. The cap is a safety valve, not a budget.</td>
        </tr>
        <tr>
            <td>Weekend days</td>
            <td>Which days are skipped by every working-time clock in the system.</td>
        </tr>
        <tr>
            <td>Branding, log capture, MCP availability</td>
            <td>Per-module master switches.</td>
        </tr>
    </tbody>
</table>

<h3>Commands run on the server</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Command</th><th>What it does</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><code>triage:run</code></td>
            <td>
                Triage one ticket or a batch of unassigned ones by hand. Has a dry-run mode that
                shows the decision without applying it or polluting the accuracy figures.
            </td>
        </tr>
        <tr>
            <td><code>triage:sweep</code></td>
            <td>
                The closing sweep. <strong>Runs itself hourly</strong>; run manually it defaults
                to a dry run and must be told explicitly to act.
            </td>
        </tr>
        <tr>
            <td><code>triage:retention</code></td>
            <td>
                Permanent deletion of old closed tickets. Never scheduled, defaults to a dry run,
                and additionally refuses to act unless retention is switched on.
            </td>
        </tr>
        <tr>
            <td><code>dotlog:prune</code></td>
            <td>Trims the debugging log. Runs itself nightly.</td>
        </tr>
    </tbody>
</table>

<p>
    The pattern is consistent and deliberate: <strong>anything that closes or deletes defaults to
    showing you what it would do rather than doing it.</strong> Only the hourly sweep acts on its
    own, because it is the one whose mistakes reverse themselves.
</p>
