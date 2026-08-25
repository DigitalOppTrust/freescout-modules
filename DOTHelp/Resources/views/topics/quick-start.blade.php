<p class="dothelp-intro">
    Everything you need to answer your first ticket without causing a problem. Five minutes.
    If you only ever read one page of this handbook, make it this one.
</p>

<h3>1. Reply inside the ticket. Never CC the customer.</h3>

<div class="dothelp-callout dothelp-callout-warn">
    <p>
        <strong>Open the ticket, type in the reply box, press send.</strong> That is the only
        correct way to answer a customer.
    </p>
    <p class="dothelp-callout-last">
        Do <strong>not</strong> reply from your own mailbox, do not CC the customer on an
        internal message, and do not add them to an email thread you started elsewhere.
    </p>
</div>

<p>
    When you reply inside the ticket, the message goes out as
    <strong><code>support@dotrust.org</code></strong> — the desk's address, not yours. It is
    recorded on the ticket, the whole team can see it, and the customer's answer comes back to
    the same place.
</p>

<p>Reply from your own inbox or CC them, and all of that breaks at once:</p>

<ul>
    <li>
        <strong>The reply is invisible.</strong> It is not on the ticket, so as far as the desk
        is concerned nobody has answered — and the ticket sits in the queue looking neglected.
    </li>
    <li>
        <strong>Their answer goes to you personally</strong>, not to the desk. If you are on
        leave, it is simply lost.
    </li>
    <li>
        <strong>You have handed out your personal address.</strong> From then on that customer
        writes to you rather than to support, and none of it is tracked.
    </li>
    <li>
        <strong>The automation gets it wrong.</strong> A ticket with no recorded reply is
        treated as unanswered, and the reports will say the customer was never helped.
    </li>
</ul>

<p>
    <strong>If you need a colleague's input</strong>, use a <em>note</em> on the ticket — it is
    internal and the customer never sees it. If you need someone outside the desk involved, use
    <em>Forward</em>, which keeps the thread attached to the ticket.
</p>

<div class="dothelp-callout dothelp-callout-quiet">
    <p class="dothelp-callout-last">
        <strong>Already replied from your own inbox?</strong> It happens. Paste the text of what
        you sent into the ticket as a note so the record is complete, then carry on inside the
        ticket from there. No harm done as long as it does not become the habit.
    </p>
</div>

<h3>2. Find the queue</h3>

<p>
    Open <strong>Mailbox</strong>. The folder list on the left is your workspace. Two folders
    matter today:
</p>

<ul>
    <li><strong>Unassigned</strong> — open tickets nobody owns. This is the real queue.</li>
    <li><strong>Mine</strong> — tickets assigned to you.</li>
</ul>

<p>
    <strong>Assign a ticket to yourself before you answer it.</strong> Otherwise two people
    reply to the same customer.
</p>

<h3>3. Expect notes you did not write</h3>

<p>
    Tickets arrive with an internal note headed <strong>Triage</strong>. That is our routing
    system explaining what it decided — who it thinks should handle this, and how sure it was.
    These are <strong>internal notes; customers never see them</strong>.
</p>

<p>
    If it says <em>"Assigned to …"</em>, the ticket already has an owner. If it says
    <em>"Triage suggests …"</em>, it is only an opinion and the ticket still needs a human to
    pick it up.
</p>

<h3>4. Tickets close themselves, and that is fine</h3>

<p>
    Junk mail is closed on arrival. Tickets where you replied and the customer never came back
    are closed after a few days. Both leave a note explaining why, and
    <strong>neither emails the customer</strong>.
</p>

<h3>5. You cannot break anything</h3>

<div class="dothelp-callout">
    <p class="dothelp-callout-last">
        Assigning, reopening, closing and reassigning are all reversible and all recorded. If a
        ticket was closed wrongly, reopen it — and if the automation got it wrong, the
        customer's next reply reopens it automatically. Fix things when you see them; you do not
        need permission.
    </p>
</div>

@unless (isset($inCourse) && $inCourse)
<h3>That is the five minutes</h3>

<p>
    Go and answer a ticket. When you have half an hour, come back for
    <a href="{{ route('dothelp.course', 'one-hour') }}">the full version</a>.
</p>
@endunless
