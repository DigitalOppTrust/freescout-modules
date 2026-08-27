<p class="dothelp-intro">
    <strong>DOTTriage</strong> is the module you will actually notice. It reads every incoming
    email and decides what to do with it: close it as machine noise, route it to whoever handles
    that kind of thing, or admit it does not know and leave it for a human.
</p>

<h3>What it does, in order</h3>

<ol class="dothelp-steps">
    <li>
        <strong>Header check.</strong> Auto-replies, newsletters, bounce notices, no-reply
        system mail and mail from our own address are closed straight away. No AI, no cost.
    </li>
    <li>
        <strong>The model.</strong> The ticket goes to Claude with a list of who handles what
        — each agent's <em>Handles</em> description. It returns a person, a confidence between
        0 and 1, and one sentence of reasoning. There is no keyword shortcut: the description
        is the whole of the routing rule, which is why its wording matters.
    </li>
    <li>
        <strong>Act, or just advise.</strong> Depending on configuration and confidence, the
        ticket is either assigned or merely annotated with a suggestion.
    </li>
</ol>

<h3>The notes you will see</h3>

<p>
    Every decision leaves an internal note on the ticket, headed <strong>Triage</strong>. These
    are notes, not replies — <strong>customers never see them</strong>. The wording tells you
    exactly what happened:
</p>

<table class="table dothelp-table">
    <thead>
        <tr><th>The note says</th><th>Which means</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><em>Assigned to Alex Mensah by triage (model, confidence 0.85). …</em></td>
            <td>It was confident and assignment is on. The ticket is now theirs.</td>
        </tr>
        <tr>
            <td><em>Triage suggests Alex Mensah (model) — not assigned because confidence 0.62
                is below the 0.75 threshold. …</em></td>
            <td>
                It has an opinion but not enough conviction. <strong>The ticket is still
                unassigned and needs you.</strong>
            </td>
        </tr>
        <tr>
            <td><em>Triage found no clear match. …</em></td>
            <td>Nobody's description fitted. Pick it up yourself or pass it on.</td>
        </tr>
        <tr>
            <td><em>Closed automatically — Not a support request. …</em></td>
            <td>Machine mail. Reopen it if that was wrong.</td>
        </tr>
        <tr>
            <td><em>Triage failed: …</em></td>
            <td>
                The API call errored. The ticket is untouched and unassigned — treat it as a
                normal unrouted ticket and mention it to an administrator if it keeps happening.
            </td>
        </tr>
    </tbody>
</table>

<h3>What "confidence" actually gates</h3>

<p>
    Confidence only decides whether the suggestion is <em>applied</em>. It does not change how
    the ticket is presented to you or how urgent it is. A 0.95 and a 0.55 both produce a note;
    only the first is likely to produce an assignment.
</p>

<h3>Correcting it</h3>

<p>
    Just reassign the ticket the normal way. There is no special screen and no undo button to
    find — the module notices the reassignment and records it as an <em>override</em>, which is
    how routing accuracy gets measured. Reassigning to the same person triage picked is not
    counted as a correction.
</p>

<div class="dothelp-callout">
    <p class="dothelp-callout-last">
        <strong>Overriding is useful, not rude.</strong> A cluster of overrides on one pair of
        people is the clearest signal that somebody's profile description needs rewording. It is
        a five-minute fix, and it only happens if you keep correcting the routing rather than
        quietly absorbing the work.
    </p>
</div>

<h3>Why you might never be auto-assigned anything</h3>

<p>
    Triage can only route to people who have a <strong>profile</strong> — a written description
    of what they handle. Without one you are invisible to routing. You are also skipped if you
    are marked unavailable (used for leave) or already at your configured ticket cap.
</p>

<p>
    Profiles are set by an administrator under <strong>Manage → Triage</strong>. If you are new,
    ask for one, and be specific about what it says: <em>"billing enquiries, invoices, refunds
    and failed payments"</em> routes far better than <em>"general queries"</em>.
</p>

<h3>Things it deliberately does not do</h3>

<ul>
    <li>
        <strong>It does not re-route replies on assigned tickets.</strong> Once a ticket is
        yours, a customer's follow-up will not yank it to somebody else mid-conversation.
    </li>
    <li>
        <strong>It does not email anybody on the customer's behalf.</strong> The only mail
        triage can cause is FreeScout's ordinary "assigned to you" notification.
    </li>
    <li>
        <strong>It does not read a ticket twice.</strong> One decision per conversation.
    </li>
</ul>
