<p class="dothelp-intro">
    A sweep runs <strong>every hour</strong> and closes conversations that no longer need a
    human. There are three separate rules, and they are deliberately different in how much they
    are trusted.
</p>

<h3>The three rules</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Rule</th><th>Closes</th><th>Requires</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Non-support mail</strong></td>
            <td>Auto-replies, newsletters, bounces, no-reply notifications, self-sent mail.</td>
            <td>A header match. No judgement, no AI.</td>
        </tr>
        <tr>
            <td><strong>Customer stopped replying</strong></td>
            <td>Tickets where an agent replied and the customer never came back.</td>
            <td>
                An agent reply, then a period of silence in <em>working</em> time — currently
                <strong>five working days</strong>.
            </td>
        </tr>
        <tr>
            <td><strong>Looks resolved</strong></td>
            <td>Tickets a model reads and judges finished.</td>
            <td>
                An agent reply and high model confidence, plus quiet: <strong>one working
                day</strong> when the agent spoke last, or about <strong>an hour</strong> when
                the customer signed off ("it is, thank you") and there is nothing left to
                wait for.
            </td>
        </tr>
    </tbody>
</table>

<div class="dothelp-callout">
    <p>
        <strong>Every close leaves an internal note</strong> saying which rule fired and why.
        Closing as noise emails nobody. Closing for inactivity or as resolved sends the
        customer the usual rating request, the same one a manual close sends — so a wrong
        close is visible to them, and the reopen link in that email is how they answer it.
    </p>
    <p class="dothelp-callout-last">
        That is the whole safety argument. The cost of a wrong close is a customer who thinks
        they were ignored — so the design leans on the fact that a wrong close undoes itself the
        moment they say anything.
    </p>
</div>

<h3>What is never closed automatically</h3>

<ul>
    <li>
        <strong>A ticket nobody has replied to.</strong> Silence on an unanswered ticket is not
        the customer losing interest, it is us failing to respond. Closing it would hide that.
    </li>
    <li>
        <strong>A ticket where the customer replied last and said something that needs
        answering.</strong> A question, a new problem or a "thanks, but one more thing" leaves
        the ball with us. A plain sign-off does not, and the resolved rule may close it — the
        model reads the message rather than assuming from who spoke last.
    </li>
</ul>

<h3>Why a ticket you expected to close has not</h3>

<p>
    This comes up often enough to be worth a checklist. Work down it in order:
</p>

<ol class="dothelp-steps">
    <li>
        <strong>Has an agent actually replied?</strong> A note is not a reply. Only a real
        outgoing message to the customer starts the clock.
    </li>
    <li>
        <strong>Did the customer reply after that?</strong> If their reply asked anything, the
        inactivity rule will not touch it — correctly so. The resolved rule still looks, and
        closes only if the model reads the reply as a sign-off with nothing outstanding.
    </li>
    <li>
        <strong>Count the working days, not the calendar days.</strong> This is the usual
        answer. A reply sent Thursday afternoon has only aged two working days by Monday
        afternoon, not four.
    </li>
    <li>
        <strong>Has the quiet period actually elapsed?</strong> The resolved rule needs a full
        working day of silence after an agent reply before it will even ask the model. The
        exception is a customer sign-off, which only waits about an hour.
    </li>
    <li>
        <strong>Wait for the next sweep.</strong> It runs hourly, on the hour. Becoming eligible
        at 15:47 means closing at 16:00, not instantly.
    </li>
    <li>
        <strong>Was the model simply not sure?</strong> The resolved rule needs high confidence
        and answers "no" whenever it is unsure — an unanswered question or an unaddressed point
        in the thread will keep it open. It will then fall to the inactivity rule later.
    </li>
</ol>

<div class="dothelp-callout dothelp-callout-quiet">
    <p class="dothelp-callout-last">
        <strong>A worked example.</strong> An agent replies at 15:47 on a Tuesday. The earliest
        the resolved rule can act is the 16:00 sweep on Wednesday — a full working day later. If
        the model declines, the inactivity rule takes over: five working days from Tuesday
        afternoon lands the following Tuesday, so the ticket closes then. In between, it is
        simply open, and there is nothing wrong with it.
    </p>
</div>

<h3>If a ticket was closed wrongly</h3>

<p>
    Reopen it. Nothing prevents you, the history is intact, and no apology to the customer is
    needed because they were never told anything. If you find a pattern — one kind of genuine
    request repeatedly closed as noise — tell an administrator, because that is a rule needing
    adjustment rather than a ticket needing fixing.
</p>

<h3>Closing is not deleting</h3>

<p>
    Closed tickets keep every message and attachment and stay searchable indefinitely. There is
    a separate, permanent deletion mechanism for old closed tickets, but it is
    <strong>switched off</strong> on this desk and only ever runs when someone deliberately runs
    it by hand on the server. Nothing you or the hourly sweep does can destroy a ticket.
</p>
