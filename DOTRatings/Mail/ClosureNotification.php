<?php

namespace Modules\DOTRatings\Mail;

use Illuminate\Mail\Mailable;
use Modules\DOTRatings\Services\ClosureNotifier;

/**
 * The "your ticket is closed" email, carrying a rating link.
 *
 * Modelled closely on core's App\Mail\AutoReply, which is the only place in
 * FreeScout that sends a customer an email outside the reply flow and gets it
 * to thread correctly.
 *
 * The Message-ID is the load-bearing detail. FetchEmails matches an incoming
 * reply back to a conversation by parsing In-Reply-To for
 * FS_autoreply-{thread_id}-{hash} and checking the hash against
 * MailHelper::getMessageIdHash($thread_id). Get this format wrong and replies
 * to a closure email arrive as brand new tickets instead of reopening the old
 * one - which is the whole feature.
 */
class ClosureNotification extends Mailable
{
    public $conversation;

    public $mailbox;

    public $customer;

    public $rating;

    /** The thread this email threads onto. */
    public $anchor;

    /** manual | inactivity | resolved */
    public $reason;

    public function __construct($conversation, $mailbox, $customer, $rating, $anchor, $reason)
    {
        $this->conversation = $conversation;
        $this->mailbox      = $mailbox;
        $this->customer     = $customer;
        $this->rating       = $rating;
        $this->anchor       = $anchor;
        $this->reason       = $reason;
    }

    public function build()
    {
        \MailHelper::prepareMailable($this);

        $this->setHeaders();

        $params = [
            'conversation' => $this->conversation,
            'mailbox'      => $this->mailbox,
            'customer'     => $this->customer,
            'explanation'  => $this->explanation(),
            'rate_url'     => route('dotratings.rate', ['token' => $this->rating->token]),
        ];

        return $this->subject('Re: '.$this->conversation->subject)
            ->view('dotratings::emails.closed', $params)
            ->text('dotratings::emails.closed_text', $params);
    }

    /**
     * Why the ticket was closed, in the customer's language.
     *
     * Deliberately a fixed sentence per reason rather than the explanation
     * triage recorded. That text is written for agents reviewing the module's
     * behaviour - it names confidence scores and internal rules, and some of
     * it is model output. None of that belongs in a customer's inbox.
     */
    protected function explanation()
    {
        switch ($this->reason) {
            case ClosureNotifier::REASON_INACTIVITY:
                return 'We closed it because we had not heard back from you since our last reply.';

            case ClosureNotifier::REASON_RESOLVED:
                return 'We closed it because it looks like your question has been answered.';

            default:
                return 'Our support team has marked it as complete.';
        }
    }

    /**
     * Set headers.
     *
     * Swift headers cannot be set through addCustomHeaders() on this version;
     * they have to go through withSwiftMessage(), and the Message-ID
     * specifically through setId(). Core carries the same note.
     */
    public function setHeaders()
    {
        $messageId = \MailHelper::MESSAGE_ID_PREFIX_AUTO_REPLY
            .'-'.$this->anchor->id
            .'-'.\MailHelper::getMessageIdHash($this->anchor->id)
            .'@'.$this->mailbox->getEmailDomain();

        $headers = ['Message-ID' => $messageId];

        // Thread under the existing conversation in the customer's mail
        // client. Only possible when we know the anchor's own Message-ID -
        // threads created in the web UI may not have one yet.
        if (!empty($this->anchor->message_id)) {
            $headers['In-Reply-To'] = '<'.$this->anchor->message_id.'>';
            $headers['References']  = '<'.$this->anchor->message_id.'>';
        }

        // Tell well-behaved mail servers not to answer this message. Without
        // it, an out-of-office responder replies to the closure email, that
        // reply reopens the ticket, and the customer looks like they came
        // back when they did not.
        $headers['Auto-Submitted'] = 'auto-generated';
        $headers['X-Auto-Response-Suppress'] = 'All';

        $this->withSwiftMessage(function ($swiftmessage) use ($headers) {
            $swiftmessage->setId($headers['Message-ID']);

            $swiftHeaders = $swiftmessage->getHeaders();
            foreach ($headers as $header => $value) {
                if ($header !== 'Message-ID') {
                    $swiftHeaders->addTextHeader($header, $value);
                }
            }
        });
    }
}
