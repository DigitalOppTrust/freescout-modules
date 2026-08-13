<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * OAuth and RBAC storage for the MCP server.
 *
 * Secrets are stored hashed, never in plaintext: support.db.gz is written to
 * disk nightly and copied into /var/backups, so a plaintext token column would
 * put working credentials into every backup.
 */
class CreateMcpTables extends Migration
{
    public function up()
    {
        // ── Access control on users ──────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            // Default off, and deliberately independent of the admin role:
            // being a FreeScout admin grants no MCP access whatsoever.
            $table->boolean('mcp_enabled')->default(false);

            // low = aggregates only, medium = + conversations w/o PII,
            // high = + customer names, emails and phone numbers.
            $table->string('mcp_access_level', 10)->default('low');
        });

        // ── OAuth clients (Claude registers itself, RFC 7591) ────────
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('client_id', 80)->unique();

            // Null for public clients using PKCE, which is what Claude is.
            $table->string('secret_hash', 255)->nullable();

            $table->string('name', 191);
            $table->text('redirect_uris');           // JSON array, exact-matched
            $table->boolean('is_confidential')->default(false);
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });

        // ── Authorization codes ──────────────────────────────────────
        Schema::create('mcp_auth_codes', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->integer('user_id')->unsigned()->index();
            $table->string('client_id', 80)->index();
            $table->text('scopes')->nullable();
            $table->boolean('revoked')->default(false);

            // PKCE. S256 only - 'plain' is accepted by the spec but offers no
            // protection, so it is rejected at the endpoint.
            $table->string('code_challenge', 128)->nullable();
            $table->string('code_challenge_method', 10)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ── Access and refresh tokens ────────────────────────────────
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->integer('user_id')->unsigned()->index();
            $table->string('client_id', 80)->index();
            $table->text('scopes')->nullable();
            $table->boolean('revoked')->default(false);

            // Snapshot of the level at issue time. The live user flag is still
            // checked on every request - this is for the audit trail, so a
            // later downgrade does not rewrite what a token could do then.
            $table->string('access_level', 10)->default('low');

            $table->timestamp('expires_at')->nullable();

            // Usage, so a dormant or unexpected token is visible.
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->integer('use_count')->unsigned()->default(0);

            $table->timestamps();

            $table->index(['user_id', 'revoked']);
        });

        Schema::create('mcp_refresh_tokens', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->string('access_token_id', 100)->index();
            $table->boolean('revoked')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ── Request log ──────────────────────────────────────────────
        Schema::create('mcp_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->nullable()->index();
            $table->string('token_id', 100)->nullable()->index();
            $table->string('method', 64)->nullable();     // JSON-RPC method
            $table->string('tool', 64)->nullable();
            $table->string('access_level', 10)->nullable();
            $table->boolean('allowed')->default(true);
            $table->string('denied_reason', 191)->nullable();
            $table->integer('duration_ms')->unsigned()->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mcp_requests');
        Schema::dropIfExists('mcp_refresh_tokens');
        Schema::dropIfExists('mcp_tokens');
        Schema::dropIfExists('mcp_auth_codes');
        Schema::dropIfExists('mcp_clients');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mcp_enabled', 'mcp_access_level']);
        });
    }
}
