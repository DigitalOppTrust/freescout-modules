<p class="dothelp-intro">
    <em>Manage → DOTLog</em>. Administrators only. One filterable timeline of what the mail
    pipeline actually did, so that "why did nobody get an email about ticket 97?" is answered by
    reading one page instead of four separate logs.
</p>

<h3>What it records</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Event</th><th>Meaning</th></tr>
    </thead>
    <tbody>
        <tr><td><code>conversation.created</code></td><td>A customer email became a new ticket.</td></tr>
        <tr><td><code>thread.customer</code> / <code>thread.agent</code> / <code>thread.note</code></td><td>A message or note was added.</td></tr>
        <tr>
            <td><code>conversation.assigned</code></td>
            <td>
                Assignment changed <strong>via the core event</strong> — the same event that
                drives FreeScout's notifications.
            </td>
        </tr>
        <tr><td><code>conversation.status</code></td><td>Status changed to active, pending, closed or spam.</td></tr>
        <tr>
            <td><code>mail.sent</code></td>
            <td>An email was handed to the mail server — recipient, subject and type. Never the body.</td>
        </tr>
        <tr><td><code>triage.*</code></td><td>What DOTTriage decided: queued, assigned, suggested, closed as noise, failed.</td></tr>
    </tbody>
</table>

<h3>The diagnostic trick</h3>

<div class="dothelp-callout">
    <p>
        <strong>An absent entry is the finding.</strong>
    </p>
    <p>
        A ticket that changed hands with <em>no</em> <code>conversation.assigned</code> entry
        means whatever assigned it bypassed the core event — and therefore bypassed the
        notification email. That is precisely the class of bug this log exists to expose.
    </p>
    <p class="dothelp-callout-last">
        Likewise, an expected <code>mail.sent</code> that is not there means the send failed or
        was never attempted. The error itself lives in <em>Manage → Logs</em>, not here.
    </p>
</div>

<h3>Looking up one ticket</h3>

<p>
    There is no link from the conversation screen. Go to <em>Manage → DOTLog</em> and type the
    ticket number or conversation id into the search box — it matches either, and strips a
    leading <code>#</code>. Filtering by event accepts a group prefix, so typing
    <code>triage</code> shows every triage event.
</p>

<p>
    Note that the <em>Ticket</em> column links by internal conversation id, which is not always
    the same number shown elsewhere in FreeScout. On this desk they differ by two.
</p>

<h3>What it deliberately does not store</h3>

<ul>
    <li>
        <strong>Message bodies, ever.</strong> Subjects and recipients are recorded for sent
        mail; the content is not.
    </li>
    <li>
        <strong>Nothing editable.</strong> Entries are immutable — there is no edit path.
    </li>
</ul>

<p>
    Entries are pruned nightly, keeping <strong>21 days</strong> by default. These are debugging
    records, not ticket data: pruning them never touches a conversation. The retention period is
    set on the DOTLog page itself.
</p>

<p>
    Capture can be switched off without losing the ability to read history — a banner appears on
    the page when it is, and pruning keeps honouring the retention promise regardless.
</p>
