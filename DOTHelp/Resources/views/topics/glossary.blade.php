<p class="dothelp-intro">
    The vocabulary this desk uses, in the sense it is used here.
</p>

<dl class="dothelp-glossary">
    <dt>Conversation</dt>
    <dd>One email thread with a customer. What everyone else calls a ticket. Holds every message, note and status change since it arrived.</dd>

    <dt>Thread</dt>
    <dd>A single item inside a conversation — a customer message, an agent reply, or an internal note.</dd>

    <dt>Note</dt>
    <dd>Internal commentary on a ticket. Permanent, visible to the team, <strong>never sent to the customer</strong>. Everything the automation says, it says in a note.</dd>

    <dt>Reply</dt>
    <dd>A message sent to the customer from inside the ticket. Goes out as <code>support@dotrust.org</code>, not your own address. The only correct way to answer someone.</dd>

    <dt>Forward</dt>
    <dd>Sending a ticket's contents to someone outside the desk while keeping their response attached to the ticket. Use it instead of CCing a third party from your own mailbox.</dd>

    <dt>Triage</dt>
    <dd>Deciding what an incoming email is and who should handle it. Here, done automatically by the DOTTriage module.</dd>

    <dt>Confidence</dt>
    <dd>How sure the model is about a routing decision, from 0 to 1. Only affects whether the suggestion is acted on.</dd>

    <dt>Override</dt>
    <dd>A human reassigning a ticket away from whoever triage picked. Recorded automatically and used to measure routing accuracy. Reassigning to the same person is not an override.</dd>

    <dt>Noise / non-support mail</dt>
    <dd>Machine-generated email that is not a support request: auto-replies, newsletters, bounce notices, no-reply notifications. Closed on arrival with a note.</dd>

    <dt>Sweep</dt>
    <dd>The hourly job that closes finished and abandoned conversations.</dd>

    <dt>Working time</dt>
    <dd>Elapsed time with weekends excluded. Every timer in the system uses it, so a Friday afternoon ticket is not treated as neglected by Monday morning.</dd>

    <dt>Elapsed time</dt>
    <dd>Plain wall-clock time — what the customer actually experienced. Reports show both this and working time side by side.</dd>

    <dt>Escalation</dt>
    <dd>Nudges, then reassigns, an assigned ticket that has had no reply within its window. Runs every 30 minutes in working time.</dd>

    <dt>Retention</dt>
    <dd>Permanent deletion of old closed tickets. Distinct from closing, irreversible, switched off here.</dd>

    <dt>Profile</dt>
    <dd>An agent's routing record: what they handle, their escalation target, availability and ticket cap. No profile means no automatic assignment.</dd>

    <dt>Rotation group</dt>
    <dd>A set of agents treated as interchangeable. The model picks the group; the system picks whoever was assigned least recently.</dd>

    <dt>Mailbox</dt>
    <dd>An email address the desk fetches. This desk has one: <code>support@dotrust.org</code>.</dd>

    <dt>Folder</dt>
    <dd>A view of the queue — Unassigned, Mine, Closed and so on. Derived from status and assignment; never set directly.</dd>

    <dt>MCP</dt>
    <dd>The protocol that lets an AI client read desk data with per-person, read-only permission.</dd>
</dl>
