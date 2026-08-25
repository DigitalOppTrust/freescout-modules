<p class="dothelp-intro">
    <strong>DOTMCP</strong> lets an AI client — Claude, in practice — read help desk data on
    your behalf, so you can ask questions in plain language instead of building a report. It is
    <strong>read-only</strong>: it cannot reply to customers, reassign tickets, or change
    anything at all.
</p>

<h3>Off unless granted</h3>

<p>
    Access is not implied by being an administrator; it is granted per person, deliberately. A
    user without it cannot connect, does not see the menu entry, and gets a "not found" rather
    than a refusal if they go looking — the module does not advertise itself to people who
    cannot use it.
</p>

<h3>Three access levels</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Level</th><th>Can read</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Low</strong></td>
            <td>
                Aggregate figures only — volume, triage summaries, response times, workload. No
                individual conversation is ever returned.
            </td>
        </tr>
        <tr>
            <td><strong>Medium</strong></td>
            <td>
                The above, plus individual conversations — but with customer names and email
                addresses redacted. Email domains are kept, because "which organisation" is
                often the reportable question.
            </td>
        </tr>
        <tr>
            <td><strong>High</strong></td>
            <td>Everything, including customer names and addresses.</td>
        </tr>
    </tbody>
</table>

<p>
    The tool list an AI client sees is filtered to the caller's level, so a person never sees a
    capability they cannot use — a refusal message would read as a bug rather than a boundary.
</p>

<h3>Connecting</h3>

<ol class="dothelp-steps">
    <li>An administrator enables your account and sets your level under <em>Manage → MCP</em>.</li>
    <li>
        In Claude, add a custom connector pointing at the endpoint shown on that page. It is the
        site address followed by <code>/mcp</code>.
    </li>
    <li>
        Claude sends you to FreeScout to sign in. You will see a consent screen naming the
        client, your account, and exactly what it will be able to read — then <strong>Allow</strong>
        or <strong>Deny</strong>.
    </li>
    <li>
        Ask questions. You can revoke the connection at any time from <em>Manage → MCP</em>.
    </li>
</ol>

<h3>What an administrator should know</h3>

<ul>
    <li>
        <strong>Every call is audited</strong> — who, which tool, what level, whether it was
        allowed, how long it took.
    </li>
    <li>
        <strong>Disabling someone revokes their connections immediately</strong>, and says how
        many it revoked.
    </li>
    <li>
        <strong>Access is not scoped by mailbox or assignee.</strong> Someone at <em>high</em>
        can read any conversation on the desk, not only their own. Grant it accordingly.
    </li>
    <li>
        <strong>Permission is re-checked at every request</strong>, not just at connection time,
        so withdrawing access takes effect at once.
    </li>
</ul>
