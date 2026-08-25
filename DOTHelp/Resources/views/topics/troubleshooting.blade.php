<p class="dothelp-intro">
    Most "something is broken" moments on this desk turn out to be the system working as
    designed. Work down the relevant list before escalating — and if you do escalate, saying
    which of these you already checked saves everyone a round trip.
</p>

<h3>"This ticket should have closed by now"</h3>
<p>
    Nearly always working-time arithmetic or the hourly sweep interval. The full checklist is on
    <a href="{{ route('dothelp.topic', 'auto-close') }}">Tickets that close themselves</a> — the
    short version: an agent must have replied, the customer must not have replied after them,
    weekends do not count, and the sweep runs on the hour.
</p>

<h3>"Nothing is being assigned to me"</h3>
<ol class="dothelp-steps">
    <li>Do you have a triage profile with a written description? Without one you are invisible to routing.</li>
    <li>Are you marked available? Unavailable is used for leave and excludes you entirely.</li>
    <li>Are you at your open-ticket cap? At capacity, routing skips you.</li>
    <li>Is the desk in suggest-only mode? Then nobody is being assigned anything automatically — every ticket gets a note instead.</li>
</ol>

<h3>"A real customer request was closed as spam or noise"</h3>
<p>
    Reopen it and answer normally. Then say so — if a genuine request matched the machine-mail
    rules, that is a rule to adjust, and it will keep happening otherwise. Include the ticket
    number; the note on the ticket records which category matched.
</p>

<h3>"The customer says they never got my reply"</h3>
<p>
    This is the one worth escalating with detail, because the answer is usually in the mail
    pipeline rather than in FreeScout. An administrator can check the DOTLog timeline for the
    ticket: a missing <code>mail.sent</code> entry means the send never happened, which is a
    different problem from a delivered message that landed in a junk folder.
</p>

<h3>"A ticket changed hands and nobody was notified"</h3>
<p>
    Also a DOTLog question, and a known class of bug the log was built to catch. Report the
    ticket number.
</p>

<h3>"The whole site is showing an error"</h3>
<p>
    Not something you can cause by working tickets. Report it immediately — the modules are
    built so that a fault inside one degrades to a log entry rather than taking the site down,
    so a site-wide error is genuinely unusual and worth flagging fast.
</p>

<h3>What to include when you report something</h3>

<ul>
    <li><strong>The ticket number</strong>, or several if it is a pattern.</li>
    <li><strong>What you expected and what happened</strong> — in that order, and separately.</li>
    <li><strong>The exact wording of any note</strong> the system left on the ticket. Those notes name the rule that fired.</li>
    <li><strong>When</strong> it happened, roughly. The debugging log keeps three weeks, so old incidents may be unreconstructable.</li>
</ul>

<div class="dothelp-callout">
    <p class="dothelp-callout-last">
        <strong>Do not wait for permission to fix a ticket.</strong> Reopen it, reassign it,
        answer it. Reporting the underlying problem and unblocking the customer are two separate
        jobs, and the second one is yours to do immediately.
    </p>
</div>
