<div class="dothelp-callout dothelp-callout-warn">
    <p>
        <strong>Read this first: escalation is not currently running.</strong>
    </p>
    <p class="dothelp-callout-last">
        The settings exist, the screens exist, and an administrator can configure who escalates
        to whom — but no code currently creates an escalation, sends a nudge, or reassigns a
        stalled ticket. <strong>Nothing will chase a ticket you forget about.</strong> That
        makes the rest of this page a description of intent, and makes the habits in
        <a href="{{ route('dothelp.topic', 'daily-work') }}">Your daily routine</a> the real
        safety net.
    </p>
</div>

<h3>What is designed, and configured</h3>

<p>
    Each agent profile can name an <strong>escalation target</strong> and a window. The intended
    behaviour is two-stage:
</p>

<ol class="dothelp-steps">
    <li>
        <strong>Nudge.</strong> If the assignee has not replied to the customer within their
        window, the target is notified.
    </li>
    <li>
        <strong>Transfer.</strong> If it is still unanswered a couple of hours later, ownership
        moves to the target.
    </li>
</ol>

<p>
    Chains are bounded so a ticket cannot bounce indefinitely, and configuring a loop
    (A escalates to B, B escalates back to A) is rejected when saved.
</p>

<h3>Working time, not wall clock</h3>

<p>
    Every window in this system is counted in <strong>working time</strong>, which currently
    means <em>weekends are skipped</em>. A one-working-day window on a ticket that arrives
    Friday afternoon expires Monday afternoon, not Saturday.
</p>

<p>
    This matters because it is the same calendar the reports use, so a ticket is never
    "breaching" on one screen and fine on another. Public holidays and working <em>hours</em>
    are not modelled — a window that expires at 02:00 expires at 02:00.
</p>

<h3>What actually catches a stalled ticket today</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Situation</th><th>What handles it</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>Nobody has answered a ticket at all</td>
            <td>
                <strong>Nobody.</strong> It sits in Unassigned until a human notices. Automatic
                closing deliberately refuses to touch it — closing an unanswered ticket would
                hide a failure rather than tidy the queue.
            </td>
        </tr>
        <tr>
            <td>You answered, the customer went quiet</td>
            <td>
                The inactivity rule closes it after the configured period. See
                <a href="{{ route('dothelp.topic', 'auto-close') }}">Tickets that close
                themselves</a>.
            </td>
        </tr>
        <tr>
            <td>A ticket is assigned to someone who has stopped working it</td>
            <td>
                <strong>Nobody, automatically.</strong> Check the <em>Assigned</em> folder
                periodically, or ask an administrator for the backlog figures in the reports.
            </td>
        </tr>
    </tbody>
</table>

<p>
    The practical consequence: <strong>the oldest unanswered ticket in Unassigned is the one
    thing worth checking every day</strong>, because no automation is watching it for you.
</p>
