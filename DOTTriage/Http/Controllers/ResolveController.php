<?php

namespace Modules\DOTTriage\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\DOTTriage\Services\ResolvedFolder;

/**
 * Manual Resolved-folder membership, from the conversation's More Actions
 * menu. Unlike the settings screens this is for every agent, not just
 * admins - resolving a ticket is day-to-day support work.
 *
 * Reopening is deliberately NOT here: setting the status back to Active is
 * core FreeScout, and the module's status_changed guard already takes a
 * reopened ticket out of the Resolved folder.
 */
class ResolveController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /** Close the conversation (if open) and put it in Resolved. */
    public function resolve($id)
    {
        $conversation = $this->authorized($id);

        if (!$conversation->isClosed()) {
            $conversation->changeStatus(\App\Conversation::STATUS_CLOSED, auth()->user());
        }

        ResolvedFolder::add($conversation);
        $conversation->mailbox->updateFoldersCounters();

        return redirect()->route('conversations.view', ['id' => $conversation->id])
            ->with('flash_success', __('Conversation marked as resolved.'));
    }

    /** Take it out of Resolved. The conversation stays closed. */
    public function unresolve($id)
    {
        $conversation = $this->authorized($id);

        ResolvedFolder::remove($conversation);
        $conversation->mailbox->updateFoldersCounters();

        return redirect()->route('conversations.view', ['id' => $conversation->id])
            ->with('flash_success', __('Conversation removed from Resolved.'));
    }

    protected function authorized($id)
    {
        $conversation = \App\Conversation::findOrFail($id);

        $user = auth()->user();
        if (!$user->isAdmin() && !$user->hasAccessToMailbox($conversation->mailbox_id)) {
            abort(403);
        }

        return $conversation;
    }
}
