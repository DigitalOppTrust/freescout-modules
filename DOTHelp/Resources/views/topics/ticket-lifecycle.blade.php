<p class="dothelp-intro">
    This is what happens between a customer pressing send and the ticket disappearing from the
    queue. Most of it is FreeScout; the shaded steps are ours.
</p>

<div class="dothelp-figure">
    <svg viewBox="0 0 720 330" role="img" aria-labelledby="lifecycle-title" class="dothelp-svg">
        <title id="lifecycle-title">The path of an incoming email through the help desk</title>

        <defs>
            <marker id="dh-head" viewBox="0 0 8 8" refX="7" refY="4"
                    markerWidth="6" markerHeight="6" orient="auto">
                <path d="M0 1 L7 4 L0 7 z" fill="#9FB0BC"/>
            </marker>
        </defs>

        <!-- 1. arrival -->
        <rect x="10" y="14" width="150" height="46" rx="4" class="dh-box"/>
        <text x="85" y="33" class="dh-t">Customer emails</text>
        <text x="85" y="49" class="dh-t dh-sm">support@dotrust.org</text>

        <path d="M85 60 L85 84" class="dh-arrow"/>

        <rect x="10" y="84" width="150" height="42" rx="4" class="dh-box"/>
        <text x="85" y="110" class="dh-t">Fetched into FreeScout</text>

        <path d="M85 126 L85 150" class="dh-arrow"/>

        <rect x="10" y="150" width="150" height="42" rx="4" class="dh-box dh-ours"/>
        <text x="85" y="169" class="dh-t">Is it really support?</text>
        <text x="85" y="183" class="dh-t dh-sm">DOTTriage reads headers</text>

        <!-- branch to noise -->
        <path d="M160 171 L214 171" class="dh-arrow"/>
        <text x="187" y="164" class="dh-t dh-lbl">no</text>
        <rect x="214" y="150" width="140" height="42" rx="4" class="dh-box dh-closed"/>
        <text x="284" y="169" class="dh-t">Closed as noise</text>
        <text x="284" y="183" class="dh-t dh-sm">note explains why</text>

        <path d="M85 192 L85 216" class="dh-arrow"/>
        <text x="97" y="209" class="dh-t dh-lbl dh-left">yes</text>

        <!-- 2. routing -->
        <rect x="10" y="216" width="150" height="42" rx="4" class="dh-box dh-ours"/>
        <text x="85" y="235" class="dh-t">Who should take it?</text>
        <text x="85" y="249" class="dh-t dh-sm">keyword, then model</text>

        <path d="M160 237 L214 237" class="dh-arrow"/>

        <rect x="214" y="216" width="140" height="42" rx="4" class="dh-box"/>
        <text x="284" y="235" class="dh-t">Assigned, or left</text>
        <text x="284" y="249" class="dh-t dh-sm">in Unassigned</text>

        <!-- 3. human -->
        <path d="M354 237 L408 237" class="dh-arrow"/>

        <rect x="408" y="216" width="150" height="42" rx="4" class="dh-box dh-human"/>
        <text x="483" y="242" class="dh-t"><tspan font-weight="600">You reply</tspan></text>

        <path d="M483 216 L483 192" class="dh-arrow"/>

        <rect x="408" y="150" width="150" height="42" rx="4" class="dh-box"/>
        <text x="483" y="169" class="dh-t">Waiting on customer</text>
        <text x="483" y="183" class="dh-t dh-sm">Active or Pending</text>

        <!-- customer replies loop -->
        <path d="M408 171 L378 171 L378 237 L408 237" class="dh-arrow dh-dash"/>
        <text x="352" y="205" class="dh-t dh-lbl">they reply</text>

        <path d="M483 150 L483 126" class="dh-arrow"/>

        <rect x="408" y="84" width="150" height="42" rx="4" class="dh-box dh-ours"/>
        <text x="483" y="103" class="dh-t">Silence for days</text>
        <text x="483" y="117" class="dh-t dh-sm">the closing sweep</text>

        <path d="M558 105 L604 105" class="dh-arrow"/>

        <rect x="604" y="84" width="106" height="42" rx="4" class="dh-box dh-closed"/>
        <text x="657" y="110" class="dh-t">Closed</text>

        <!-- reopen -->
        <path d="M657 126 L657 290 L483 290 L483 258" class="dh-arrow dh-dash"/>
        <text x="570" y="304" class="dh-t dh-lbl">customer replies → reopens</text>

        <!-- legend -->
        <rect x="10" y="285" width="12" height="12" rx="2" class="dh-box dh-ours"/>
        <text x="28" y="295" class="dh-t dh-lbl dh-left">our modules</text>
        <rect x="120" y="285" width="12" height="12" rx="2" class="dh-box dh-human"/>
        <text x="138" y="295" class="dh-t dh-lbl dh-left">you</text>
    </svg>
</div>

<h3>Step by step</h3>

<h4>1. The email arrives</h4>
<p>
    FreeScout fetches <code>support@dotrust.org</code> on a schedule. A message from a new
    thread becomes a new conversation; a reply to an existing thread is appended to the
    conversation it belongs to.
</p>

<h4>2. Is this actually a support request?</h4>
<p>
    Before anything else, DOTTriage looks at the email's <em>headers</em> — not its content —
    for the fingerprints of machine mail: out-of-office auto-replies, newsletters, delivery
    failure notices, no-reply system notifications, and mail our own address sent to itself.
    These are closed immediately with a note saying which category matched.
</p>
<p>
    This is a cheap, deterministic check. No AI is involved and it costs nothing. It is also
    the reason your queue is not full of "Automatic reply: Out of Office".
</p>

<h4>3. Who should handle it?</h4>
<p>
    Real support mail then gets routed. Two mechanisms, in order:
</p>
<ul>
    <li>
        <strong>Keywords</strong> — if an administrator has given someone keywords like
        <code>invoice, refund</code>, a match routes the ticket instantly. Free and
        deterministic.
    </li>
    <li>
        <strong>The model</strong> — otherwise the ticket's subject and body are sent to
        Claude along with a list of agents and what each one handles. It replies with a
        suggested person, a confidence score, and one sentence of reasoning.
    </li>
</ul>
<p>
    Either way a note appears on the ticket explaining what was decided and why. See
    <a href="{{ route('dothelp.topic', 'triage') }}">How tickets reach you</a> for what those
    notes look like and how to correct a bad decision.
</p>

<h4>4. You answer it</h4>
<p>
    This part is entirely yours. Nothing automated writes to a customer, ever. Take ownership
    by assigning the ticket to yourself if it is not already, reply, and set the status that
    reflects reality — see <a href="{{ route('dothelp.topic', 'folders') }}">Folders and
    statuses</a>.
</p>
<p>
    <strong>Reply from inside the ticket.</strong> That is what makes the message part of this
    chain at all — it goes out as the desk address, lands on the ticket, and the customer's
    answer comes back here. Replying from your own mailbox or CCing the customer takes the
    conversation out of the system entirely, and every step after this one stops working. See
    <a href="{{ route('dothelp.topic', 'replying') }}">Replying to customers</a>.
</p>

<h4>5. It closes — often by itself</h4>
<p>
    When the exchange is over, close it. If nobody does, the desk eventually closes it for
    you: a ticket where you replied and the customer never came back is closed after a set
    period of quiet, and a ticket that reads as finished can be closed on the model's
    judgement. Both leave a note. Neither emails the customer.
</p>
<p>
    Full rules, including how to work out why a specific ticket has <em>not</em> closed, are in
    <a href="{{ route('dothelp.topic', 'auto-close') }}">Tickets that close themselves</a>.
</p>

<h4>6. And it can come back</h4>
<p>
    If a customer replies to a closed ticket, FreeScout reopens it and it returns to the
    queue. This is the safety net under every automatic close: a wrong close corrects itself
    the moment the customer says anything.
</p>

<div class="dothelp-callout">
    <p class="dothelp-callout-last">
        <strong>The exception worth knowing.</strong> If the thing that lands on a closed
        ticket is itself machine mail — an auto-reply bouncing back — DOTTriage recognises it,
        leaves a note, and puts the status back. A holiday auto-responder cannot drag a
        finished ticket back into your queue.
    </p>
</div>
