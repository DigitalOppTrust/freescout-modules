<p class="dothelp-intro">
    Two different things are easy to confuse: a ticket's <strong>status</strong> (what state it
    is in) and the <strong>folder</strong> it appears in (where you find it). Folders are
    derived from status and assignment — you never set a folder directly.
</p>

<h3>Statuses</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Status</th><th>Means</th><th>Use it when</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Active</strong></td>
            <td>Open, and the ball is with us.</td>
            <td>The default for anything needing work.</td>
        </tr>
        <tr>
            <td><strong>Pending</strong></td>
            <td>Open, but we are waiting on someone else.</td>
            <td>You have replied and need the customer to come back to you.</td>
        </tr>
        <tr>
            <td><strong>Closed</strong></td>
            <td>Finished. Still searchable, still readable.</td>
            <td>The exchange is over. Closing is not deleting.</td>
        </tr>
        <tr>
            <td><strong>Spam</strong></td>
            <td>Junk. Excluded from every report.</td>
            <td>Rarely — most machine mail is closed as non-support instead.</td>
        </tr>
    </tbody>
</table>

<div class="dothelp-callout">
    <p class="dothelp-callout-last">
        <strong>Pending matters more than it looks.</strong> The automatic closing rules treat
        Active and Pending the same way, but <em>you</em> can tell at a glance which tickets are
        genuinely waiting on you and which are parked on a customer. Setting Pending when you
        have replied keeps your own view honest.
    </p>
</div>

<h3>Folders</h3>

<table class="table dothelp-table">
    <thead>
        <tr><th>Folder</th><th>What is in it</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Unassigned</strong></td>
            <td>
                Open tickets nobody owns. <strong>This is the queue that matters.</strong>
                A ticket sitting here has, by definition, nobody responsible for it.
            </td>
        </tr>
        <tr>
            <td><strong>Mine</strong></td>
            <td>Open tickets assigned to you. Your actual workload.</td>
        </tr>
        <tr>
            <td><strong>Assigned</strong></td>
            <td>Open tickets owned by someone — anyone. Useful for seeing team load.</td>
        </tr>
        <tr>
            <td><strong>Starred</strong></td>
            <td>Whatever you have starred, personal to you.</td>
        </tr>
        <tr>
            <td><strong>Drafts</strong></td>
            <td>Replies you started and did not send.</td>
        </tr>
        <tr>
            <td><strong>Closed</strong></td>
            <td>Everything finished, including everything the automation closed.</td>
        </tr>
        <tr>
            <td><strong>Spam</strong> / <strong>Deleted</strong></td>
            <td>Junk and removed tickets.</td>
        </tr>
    </tbody>
</table>

<h3>Why a ticket seems to be in the wrong place</h3>

<p>
    Folders are recalculated whenever status or assignment changes. If a ticket ever looks
    misfiled — closed but still showing under Unassigned, say — that is a real bug worth
    reporting, not something you should work around. It has happened before and was fixed.
</p>

<p>
    One thing that is <em>not</em> a bug: a ticket in <strong>Unassigned</strong> that already
    has a triage note recommending someone. When automatic assignment is off, or the model was
    not confident enough, triage records its opinion without acting on it. The ticket is still
    genuinely unowned and still needs a human to pick it up.
</p>

<div class="dothelp-callout dothelp-callout-quiet">
    <p class="dothelp-callout-last">
        There was briefly a <em>Resolved</em> folder on this desk. It was removed deliberately —
        it duplicated Closed without adding a distinction anyone needed. If you see it mentioned
        in an old note, it no longer exists.
    </p>
</div>
