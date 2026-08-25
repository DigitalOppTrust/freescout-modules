<p class="dothelp-intro">
    Welcome. This page tells you what you are looking at and what to do in your first week.
    You do not need to understand the automation to be useful on day one — the desk works
    fine if you simply read tickets and answer them.
</p>

<h3>What this system is</h3>

<p>
    Customers email <code>support@dotrust.org</code>. That address is fetched into
    <strong>FreeScout</strong>, a shared inbox where each email thread becomes a
    <strong>conversation</strong> — what most people call a ticket. Everyone on the team sees
    the same queue, so a ticket is visibly either waiting, owned by someone, or finished.
</p>

<p>
    Around FreeScout we run a few modules of our own, all prefixed <code>DOT</code>. Only one
    of them changes what you see day to day: <strong>DOTTriage</strong> reads each new email
    and decides which of us should handle it. The rest are reporting and plumbing.
</p>

<h3>Your first week</h3>

<ol class="dothelp-steps">
    <li>
        <strong>Log in and find the queue.</strong> The folder list down the left side of the
        <em>Mailbox</em> page is where you live. Read
        <a href="{{ route('dothelp.topic', 'folders') }}">Folders and statuses</a> to know what
        each one means.
    </li>
    <li>
        <strong>Watch before you answer.</strong> Open a few closed tickets and read how
        colleagues replied — tone, length, how much they explain. That is faster than any
        style guide.
    </li>
    <li>
        <strong>Take one ticket end to end.</strong> Assign it to yourself, reply, and let it
        close. Read <a href="{{ route('dothelp.topic', 'ticket-lifecycle') }}">The life of a
        ticket</a> first so you know what happens after you hit send.
    </li>
    <li>
        <strong>Ask for your Triage profile.</strong> Automatic routing only sends you work
        once an administrator has written a description of what you handle. Until then you
        will never be auto-assigned anything. See
        <a href="{{ route('dothelp.topic', 'triage') }}">How tickets reach you</a>.
    </li>
    <li>
        <strong>Learn the two automatic behaviours that will surprise you</strong> — tickets
        arriving pre-assigned with an explanatory note, and tickets closing themselves days
        later. Both are covered in this handbook and neither is a bug.
    </li>
</ol>

<h3>The one thing to internalise</h3>

<div class="dothelp-callout">
    <p>
        <strong>Nothing this system does automatically is irreversible, and it never emails the
        customer on its own.</strong>
    </p>
    <p>
        Automatic routing can be corrected by reassigning. Automatic closing can be undone by
        reopening — and if the automation got it wrong, the customer's next reply reopens the
        ticket by itself. No automatic action has ever sent a message to a customer.
    </p>
    <p class="dothelp-callout-last">
        So when something looks odd, you are allowed to just fix it. You cannot make it worse
        by touching it.
    </p>
</div>

<h3>Who is on the desk</h3>

<p>
    FreeScout has two kinds of account. <strong>Users</strong> (most of us) work the queue.
    <strong>Administrators</strong> can additionally reach the <em>Manage</em> menu, the
    reports, and the module settings. If a page in this handbook is marked
    <span class="dothelp-tag">admin</span>, you are reading about a screen you cannot open —
    which is fine, it is here so you know the capability exists and who to ask.
</p>

<h3>Where to go next</h3>

<p>
    <a href="{{ route('dothelp.topic', 'ticket-lifecycle') }}">The life of a ticket</a> is the
    single most useful page here. After that,
    <a href="{{ route('dothelp.topic', 'daily-work') }}">Your daily routine</a> is a practical
    checklist you can keep open for a week.
</p>
