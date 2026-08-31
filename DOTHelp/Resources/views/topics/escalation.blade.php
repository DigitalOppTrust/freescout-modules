<p class="dothelp-intro">
    Escalation is what chases a ticket that has been assigned but not answered. It runs
    automatically, in working time, and it does two things in order: it tells someone, then it
    moves the ticket.
</p>

<h3>How it works</h3>

<p>
    Each agent profile names an <strong>escalation target</strong> and a window. A clock starts
    when a ticket is assigned to you — by triage or by a person — and again every time the
    customer writes back. It stops the moment you send the customer a reply.
</p>

<p class="dothelp-note">
    The window is a setting, not a fixed rule. Administrators set a default for everyone at
    Manage → Triage → Escalation, and can give an individual agent a different one on their
    own page. If yours seems wrong for the work you do, that is a conversation worth having
    rather than something you have to live with.
</p>

<ol class="dothelp-steps">
    <li>
        <strong>Nudge.</strong> If you have not replied within your window, your escalation
        target gets an email and a <em>Triage</em> note appears on the ticket saying so. The
        ticket is still yours.
    </li>
    <li>
        <strong>Transfer.</strong> If it is still unanswered a couple of hours later, ownership
        moves to the target, they are notified as the new assignee, and <em>their</em> clock
        starts against <em>their</em> escalation target.
    </li>
</ol>

<p>
    Chains are bounded so a ticket cannot bounce indefinitely, a ticket never escalates back to
    someone it has already been through, and configuring a loop (A escalates to B, B escalates
    back to A) is rejected when saved. The check runs every 30 minutes, so a window can be
    overrun by up to half an hour.
</p>

<h3>Working time, not wall clock</h3>

<p>
    Every window is counted in <strong>working time</strong>, which currently means
    <em>weekends are skipped</em>. A one-working-day window on a ticket that arrives Friday
    afternoon expires Monday afternoon, not Saturday.
</p>

<p>
    This matters because it is the same calendar the reports use, so a ticket is never
    "breaching" on one screen and fine on another. Public holidays and working <em>hours</em>
    are not modelled — a window that expires at 02:00 expires at 02:00.
</p>

<div class="dothelp-callout dothelp-callout-warn">
    <p class="dothelp-callout-last">
        <strong>Only replies sent from FreeScout stop the clock.</strong> If you answer the
        customer from your own mailbox, the system sees a customer message, not a reply — the
        clock keeps running and you will be escalated for a ticket you answered. See
        <a href="{{ route('dothelp.topic', 'replying') }}">Replying</a>.
    </p>
</div>

<h3>What catches a stalled ticket</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Situation</th><th>What handles it</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>A ticket is assigned and nobody has replied</td>
            <td>Escalation, as above — provided the assignee's profile names a target.</td>
        </tr>
        <tr>
            <td>Nobody has answered a ticket and nobody owns it</td>
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
    </tbody>
</table>

<p>
    The practical consequence: <strong>the oldest ticket in Unassigned is still the one thing
    worth checking every day</strong>, because escalation only watches tickets that have an
    owner. Administrators can see every ticket currently on the clock under
    Manage → Triage → Escalation.
</p>
