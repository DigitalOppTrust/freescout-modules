<p class="dothelp-intro">
    Eight modules run on this desk, all prefixed <code>DOT</code>. Only one of them changes your
    daily work. This page exists so that when someone says "that's DOTLog", you know whether it
    concerns you.
</p>

<table class="table dothelp-table dothelp-modules">
    <thead>
        <tr><th>Module</th><th>What it does</th><th>Do you touch it?</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>DOTTriage</strong></td>
            <td>
                Routes incoming mail to the right person, closes machine mail on arrival, and
                runs the hourly sweep that closes finished and abandoned tickets.
            </td>
            <td>
                <span class="dothelp-yes">Yes — daily.</span> Its notes appear on your tickets.
                <a href="{{ route('dothelp.topic', 'triage') }}">Read the page</a>.
            </td>
        </tr>
        <tr>
            <td><strong>DOTSSO</strong></td>
            <td>
                How you sign in. Your DOT Google account replaces a help desk password —
                see <a href="{{ route('dothelp.topic', 'signing-in') }}">Signing in</a>.
            </td>
            <td>
                <span class="dothelp-yes">Every time you sign in.</span> There is nothing to
                configure, but it is why there is no password box.
            </td>
        </tr>
        <tr>
            <td><strong>DOTRatings</strong></td>
            <td>
                Emails the customer when their ticket closes, asking for a 1–5 star rating.
                Replying to that email reopens the ticket.
            </td>
            <td>
                <span class="dothelp-no">No.</span> It sends itself. Worth knowing because a
                reopened ticket is often a reply to this email, not a new problem.
            </td>
        </tr>
        <tr>
            <td><strong>DOTTheme</strong></td>
            <td>
                Branding only — the DO Trust logo, brand colours and the Montserrat typeface.
                It deliberately does not move or change anything.
            </td>
            <td>
                <span class="dothelp-no">No.</span> There is nothing to configure and nothing
                behaves differently because of it.
            </td>
        </tr>
        <tr>
            <td><strong>DOTReports</strong></td>
            <td>
                Volume, routing accuracy, response and resolution times, and per-agent workload.
            </td>
            <td>
                <span class="dothelp-admin">Administrators only.</span> You will not see the
                menu item.
            </td>
        </tr>
        <tr>
            <td><strong>DOTLog</strong></td>
            <td>
                A single timeline of what the mail pipeline did — what arrived, what triage
                decided, what email was actually sent.
            </td>
            <td>
                <span class="dothelp-admin">Administrators only.</span> Worth knowing it exists:
                it is what answers "was that notification ever sent?"
            </td>
        </tr>
        <tr>
            <td><strong>DOTMCP</strong></td>
            <td>
                Lets an AI client such as Claude read help desk statistics, with per-person
                permission and read-only access.
            </td>
            <td>
                <span class="dothelp-maybe">Only if granted.</span> Off for everyone by default.
            </td>
        </tr>
        <tr>
            <td><strong>DOTHelp</strong></td>
            <td>This handbook.</td>
            <td><span class="dothelp-yes">You are here.</span></td>
        </tr>
    </tbody>
</table>

<h3>How they relate</h3>

<p>
    DOTTriage is the only module that acts on tickets. Everything else either observes it
    (DOTReports, DOTLog), exposes it (DOTMCP), or is cosmetic (DOTTheme). That is a deliberate
    design choice: the modules that could damage a ticket are kept to one, and the rest are
    built so their worst failure is a broken page.
</p>

<p>
    They do share vocabulary. "Working time" means the same thing in the triage timers and in
    the resolution reports, because both use the same calendar — the reports cannot disagree
    with the automation about whether a ticket was slow.
</p>

<h3>If a module breaks</h3>

<p>
    Each one is wrapped so that a fault inside it becomes a log entry rather than taking the
    whole site down, and each has an off switch that an administrator can use without
    uninstalling anything. If the desk is behaving strangely, that is worth mentioning — but you
    will not be the cause of it.
</p>
