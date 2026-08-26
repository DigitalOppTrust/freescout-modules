<?php

namespace Modules\DOTRatings\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTRatings\Entities\Rating;

/**
 * The customer-facing rating page. No authentication: the recipient of a
 * closure email has no account and never will.
 *
 * Security posture, since this is the only part of the help desk a stranger
 * can reach:
 *
 *   - The token is 256 bits of randomness and expires. Guessing one is not a
 *     realistic attack; the route throttle covers the unrealistic case.
 *   - The page shows a ticket number and a mailbox name. Never the subject,
 *     never any message content, never the customer's name or address. A
 *     leaked link therefore discloses nothing worth having.
 *   - Unknown and expired tokens render the same page, so probing cannot
 *     distinguish "never existed" from "expired".
 *   - GET never writes. Mail scanners follow links before a human sees them.
 */
class PublicRatingsController extends Controller
{
    /** Show the rating form. Never mutates anything. */
    public function show(Request $request, $token)
    {
        $rating = Rating::findUsable($token);

        if (!$rating) {
            return response()->view('dotratings::public.invalid');
        }

        // ?stars=N from the email only preselects the form. The rating is
        // not recorded until the customer submits it.
        $preselect = (int) $request->get('stars');
        if ($preselect < 1 || $preselect > 5) {
            $preselect = $rating->rating;
        }

        return response()->view('dotratings::public.rate', [
            'rating'    => $rating,
            'preselect' => $preselect,
            'number'    => $this->ticketNumber($rating),
            'brand'     => $this->brand($rating),
        ]);
    }

    /** Record a rating. Re-submission while the token is valid updates it. */
    public function submit(Request $request, $token)
    {
        $rating = Rating::findUsable($token);

        if (!$rating) {
            return response()->view('dotratings::public.invalid');
        }

        $stars = (int) $request->input('rating');
        if ($stars < 1 || $stars > 5) {
            return redirect()->route('dotratings.rate', ['token' => $token])
                ->with('error', 'Please choose a rating from one to five stars.');
        }

        $comment = trim((string) $request->input('comment', ''));
        if (mb_strlen($comment) > 2000) {
            $comment = mb_substr($comment, 0, 2000);
        }

        $isUpdate = $rating->rated_at !== null;

        $rating->rating   = $stars;
        $rating->comment  = $comment ?: null;
        $rating->rated_at = now();
        $rating->save();

        $this->noteRating($rating, $stars, $comment, $isUpdate);

        return response()->view('dotratings::public.thanks', [
            'rating' => $rating,
            'stars'  => $stars,
            'token'  => $token,
            'number' => $this->ticketNumber($rating),
            'brand'  => $this->brand($rating),
        ]);
    }

    /**
     * Reopen the ticket with a message.
     *
     * Thread::createExtended is core's own path for an inbound customer
     * message: it creates the thread, sets the conversation active, re-files
     * it into the right folder, updates the counters and fires
     * conversation.customer_replied so agents get notified. Doing any of that
     * by hand here would drift from core the first time it changes.
     */
    public function reopen(Request $request, $token)
    {
        $rating = Rating::findUsable($token);

        if (!$rating) {
            return response()->view('dotratings::public.invalid');
        }

        $message = trim((string) $request->input('message', ''));

        if ($message === '') {
            return redirect()->route('dotratings.rate', ['token' => $token])
                ->with('error', 'Please tell us what you still need help with.');
        }

        if (mb_strlen($message) > 5000) {
            $message = mb_substr($message, 0, 5000);
        }

        $conversation = $rating->conversation;
        if (!$conversation) {
            return response()->view('dotratings::public.invalid');
        }

        $customer = $conversation->customer;
        if (!$customer) {
            \Log::warning('[Ratings] cannot reopen conversation '.$conversation->id
                .': no customer record');

            return response()->view('dotratings::public.invalid');
        }

        $thread = \App\Thread::createExtended([
            'type'        => \App\Thread::TYPE_CUSTOMER,
            'body'        => nl2br(e($message)),
            'customer_id' => $customer->id,
            'source_via'  => \App\Thread::PERSON_CUSTOMER,
        ], $conversation, $customer);

        if (!$thread) {
            \Log::error('[Ratings] Thread::createExtended refused the reopen for conversation '
                .$conversation->id);

            return response()->view('dotratings::public.invalid');
        }

        $rating->reopened_at = now();
        $rating->save();

        \Log::info('[Ratings] conversation '.$conversation->id.' reopened by the customer');

        return response()->view('dotratings::public.reopened', [
            'number' => $this->ticketNumber($rating),
            'brand'  => $this->brand($rating),
        ]);
    }

    /**
     * Record the rating on the ticket so agents see it in context rather than
     * only in a report.
     */
    protected function noteRating($rating, $stars, $comment, $isUpdate)
    {
        $conversation = $rating->conversation;
        if (!$conversation) {
            return;
        }

        $body = '<strong>Ratings</strong><br>'
            .'Customer '.($isUpdate ? 'updated their rating to' : 'rated this support')
            .' '.str_repeat('★', $stars).str_repeat('☆', 5 - $stars)
            .' ('.$stars.'/5).';

        if ($comment !== '') {
            $body .= '<br><br>'.nl2br(e($comment));
        }

        try {
            $thread = new \App\Thread();
            $thread->conversation_id = $conversation->id;
            $thread->type            = \App\Thread::TYPE_NOTE;
            $thread->status          = \App\Thread::STATUS_NOCHANGE;
            $thread->state           = \App\Thread::STATE_PUBLISHED;
            $thread->body            = $body;
            $thread->source_via      = \App\Thread::PERSON_USER;
            $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
            $thread->customer_id     = $conversation->customer_id;
            $thread->save();
        } catch (\Throwable $e) {
            \Log::warning('[Ratings] could not note rating on conversation '
                .$conversation->id.': '.$e->getMessage());
        }
    }

    /** Ticket number for display, falling back to the id if need be. */
    protected function ticketNumber($rating)
    {
        $conversation = $rating->conversation;

        return $conversation ? $conversation->number : $rating->conversation_id;
    }

    /**
     * The name to show at the top of the page.
     *
     * The mailbox name, not the customer's name: this page is reached with a
     * token, and echoing back personal details would make a leaked link more
     * valuable than it needs to be.
     */
    protected function brand($rating)
    {
        $conversation = $rating->conversation;

        if ($conversation && $conversation->mailbox) {
            return $conversation->mailbox->name;
        }

        return config('app.name', 'Support');
    }
}
