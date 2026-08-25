<p class="dothelp-intro">
    <strong>Reports</strong> in the main navigation, or <em>Manage → Reports</em>. Administrators
    only. Four tabs, a shared period selector, and a CSV export on every tab that runs the same
    queries as the screen — so an export always matches what you were looking at.
</p>

<h3>The tabs</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Tab</th><th>Answers</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Overview</strong></td>
            <td>
                How much mail arrived, how much was routed automatically, how long resolution
                takes. Includes a volume chart and a day×hour heatmap of when mail actually
                lands.
            </td>
        </tr>
        <tr>
            <td><strong>Triage</strong></td>
            <td>
                The coverage funnel, routing accuracy, confidence calibration, the override
                matrix, model spend and failures.
            </td>
        </tr>
        <tr>
            <td><strong>Resolution</strong></td>
            <td>
                First response and resolution times — elapsed and working — plus backlog age,
                reopens and first-contact resolution.
            </td>
        </tr>
        <tr>
            <td><strong>Team</strong></td>
            <td>Per-agent workload, time tickets spend unassigned.</td>
        </tr>
    </tbody>
</table>

<h3>Reading the numbers honestly</h3>

<p>
    This module goes out of its way to state its own blind spots. Take those statements
    seriously — they are the difference between a figure you can act on and one that merely
    looks authoritative.
</p>

<ul>
    <li>
        <strong>Resolution times report their own coverage.</strong> Not every closed ticket can
        be timed; where the close timestamp is missing the module falls back to status history,
        and says on the page how many it could time and how many it excluded. The excluded ones
        are not a random sample — automatic closes are the fast ones — so a median that hid the
        gap would read slow and be believed.
    </li>
    <li>
        <strong>Small samples are flagged, not hidden.</strong> Below about twenty data points a
        percentile is marked unreliable, and the Team table appends <code>(n=4)</code> to thin
        medians. A median resolution time from three tickets is noise.
    </li>
    <li>
        <strong>Routing accuracy counts only applied decisions.</strong> A suggestion nobody
        acted on tells you nothing about whether the model was right, so it is excluded rather
        than counted as a success.
    </li>
    <li>
        <strong>The triage coverage denominator is every conversation received</strong> — not
        just the ones triage attempted. Anything else would let the module score well by
        declining to try.
    </li>
    <li>
        <strong>Model spend is an order-of-magnitude estimate</strong> derived from token counts
        and configured rates. It is not a billing figure.
    </li>
    <li>
        <strong>The Team tab is workload, not performance.</strong> The page says so itself, at
        the top, before any data: one hard ticket can be worth thirty password resets, and
        whoever takes the difficult work looks slower in every column. Use it for spotting
        overload, not ranking people.
    </li>
</ul>

<div class="dothelp-callout dothelp-callout-warn">
    <p class="dothelp-callout-last">
        <strong>Two figures currently read as zero and should not be trusted.</strong> The
        escalation counts on the Triage and Team tabs will always be empty, because escalation
        does not run — see <a href="{{ route('dothelp.topic', 'escalation') }}">Escalation</a>.
        Separately, the "Reopened by a human" counter on the Triage settings screen is never
        written to, so it stays at zero regardless of how many tickets are actually reopened.
        The Resolution tab's own "Reopened after closing" figure <em>is</em> real — it is derived
        from ticket history rather than that counter.
    </p>
</div>

<h3>Where the timings come from</h3>

<p>
    Everything is derived from the message history rather than the convenience columns on the
    conversation, because those columns change meaning with configuration flags and are not
    designed for reporting. Notes and system line items are excluded from reply counts — a note
    is not a response, and counting one would let the team look responsive while the customer
    heard nothing.
</p>

<p>
    Spam, deleted and imported conversations are excluded everywhere, including from message
    counts.
</p>
