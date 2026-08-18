<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One row per pipeline event: mail fetched, ticket created, triage decision,
 * assignment, notification sent, and so on.
 *
 * No updated_at - log rows are written once and never edited. `context` is
 * text rather than a JSON column so the table works on every MariaDB version
 * FreeScout supports.
 */
class CreateDotlogEntriesTable extends Migration
{
    public function up()
    {
        Schema::create('dotlog_entries', function (Blueprint $table) {
            $table->increments('id');

            // Dotted event key, e.g. 'thread.customer', 'triage.assigned',
            // 'mail.sent'. The first segment groups events in the UI filter.
            $table->string('event', 40)->index();

            // 'info' | 'warning' | 'error'
            $table->string('level', 10)->default('info');

            $table->integer('conversation_id')->unsigned()->nullable()->index();
            $table->integer('mailbox_id')->unsigned()->nullable();
            $table->integer('thread_id')->unsigned()->nullable();

            // The acting or affected user, when there is one.
            $table->integer('user_id')->unsigned()->nullable();

            // Human-readable one-liner shown in the log view.
            $table->string('message', 998);

            // JSON-encoded structured detail; never message bodies.
            $table->text('context')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dotlog_entries');
    }
}
