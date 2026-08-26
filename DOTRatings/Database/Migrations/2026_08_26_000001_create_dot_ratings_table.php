<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One row per closure email sent, which doubles as the rating record.
 *
 * The row is created when the email is sent, not when a rating arrives, so
 * that "how many people we asked" and "how many answered" are both readable
 * from one table - a rating table alone cannot tell you the response rate.
 *
 * The token is the customer's only credential. It is random, single-purpose
 * and expiring, and the page it unlocks deliberately shows nothing but a
 * ticket number, so a leaked link discloses nothing.
 */
class CreateDotRatingsTable extends Migration
{
    public function up()
    {
        Schema::create('dot_ratings', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('conversation_id')->unsigned();
            $table->integer('mailbox_id')->unsigned()->nullable();
            $table->integer('customer_id')->unsigned()->nullable();

            // 64 hex characters from random_bytes(32).
            $table->string('token', 64)->unique();

            // manual | inactivity | resolved. Noise closures never get here.
            $table->string('close_reason', 32)->nullable();

            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();

            $table->dateTime('email_sent_at')->nullable();
            $table->dateTime('rated_at')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->dateTime('expires_at');

            $table->timestamps();

            // The resend guard queries by conversation and send time on every
            // close, so this index is on the hot path, not just reporting.
            $table->index(['conversation_id', 'email_sent_at']);
            $table->index('rated_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('dot_ratings');
    }
}
