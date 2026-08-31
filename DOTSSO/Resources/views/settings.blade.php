@extends('layouts.app')
@section('title', 'Single Sign-On')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dotsso/css/module.css') }}">
@endsection

@section('content')
<div class="container">
    <h2 class="subheader">Single Sign-On</h2>
    @include('partials/flash_messages')

    <div class="descr-block">
        <p>
            Staff sign in with their Google Workspace account instead of a password.
            Two things must both be true for a sign-in to succeed: the Google account
            belongs to the <strong>{{ $domain ?: 'configured' }}</strong> workspace, and it
            already matches an active user on this help desk. Signing in with Google
            never creates an account.
        </p>
    </div>

    @if ($enforcing)
        <div class="alert alert-info">
            <strong>SSO is enforced.</strong> Password sign-in is refused
            @if ($breakglass)
                for everyone except {{ implode(', ', $breakglass) }}.
            @else
                for all users. If SSO stops working, recovery needs shell access:
                <code>php artisan dotsso:disable</code>
            @endif
        </div>
    @elseif ($enabled)
        <div class="alert alert-warning">
            <strong>SSO is on but not enforced.</strong> The Google button is on the login
            page and passwords still work. Sign out and sign in with Google once to prove
            it works before enforcing.
        </div>
    @endif

    <form method="POST" action="{{ route('dotsso.settings.save') }}">
        {{ csrf_field() }}

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Google client</strong></div>
            <div class="panel-body">
                <div class="form-group">
                    <label>Authorised redirect URI</label>
                    <div><code class="dotsso-uri">{{ $redirectUri }}</code></div>
                    <p class="dotsso-meta">
                        Paste this into the OAuth client in Google Cloud Console. It must
                        match exactly, including https and the absence of a trailing slash.
                    </p>
                </div>

                <div class="form-group">
                    <label for="client_id">Client ID</label>
                    <input type="text" class="form-control" id="client_id" name="client_id"
                           value="{{ $clientId }}" placeholder="…apps.googleusercontent.com">
                </div>

                <div class="form-group">
                    <label for="client_secret">Client secret</label>
                    <input type="password" class="form-control" id="client_secret"
                           name="client_secret" autocomplete="new-password"
                           placeholder="{{ $secretSet ? 'Set — leave blank to keep it' : 'Not set' }}">
                    <p class="dotsso-meta">Encrypted before it is stored. Leave blank to keep the current one.</p>
                </div>

                <div class="form-group">
                    <label for="domain">Workspace domain</label>
                    <input type="text" class="form-control" id="domain" name="domain"
                           value="{{ $domain }}" placeholder="dotrust.org">
                    <p class="dotsso-meta">
                        Checked against the signed <code>hd</code> claim on Google's token,
                        not against the email address. Leaving this empty removes the
                        workspace restriction and is not recommended.
                    </p>
                </div>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Rollout</strong></div>
            <div class="panel-body">
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="enabled" value="1" {{ $enabled ? 'checked' : '' }}>
                        <strong>Show the Google button on the login page</strong>
                    </label>
                    <p class="dotsso-meta">Passwords keep working. Safe to turn on.</p>
                </div>

                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="enforce" value="1" {{ $enforcing ? 'checked' : '' }}>
                        <strong>Refuse password sign-in</strong>
                    </label>
                    <p class="dotsso-meta">
                        Only turn this on after a successful Google sign-in. It is refused
                        unless SSO is switched on, fully configured and has a domain set.
                    </p>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    <h3 class="subheader">Who can sign in</h3>
    <div class="descr-block">
        <p>
            Checked against the rules SSO applies. Anyone marked <strong>no</strong> will be
            refused — resolve that before refusing password sign-in.
        </p>
    </div>

    <table class="table table-condensed">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Can sign in with Google</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>
                        {{ $user['name'] }}
                        @if ($user['admin'])<span class="dotsso-meta">(admin)</span>@endif
                    </td>
                    <td>{{ $user['email'] }}</td>
                    <td>
                        @if (!$user['ok'])
                            <span class="dotsso-no">no — {{ $user['why'] }}</span>
                        @elseif (!$user['domain'])
                            <span class="dotsso-no">no — not a {{ $domain }} address</span>
                        @else
                            <span class="dotsso-yes">yes</span>
                            @if ($user['invited'])
                                <span class="dotsso-meta">— invite will be activated on first sign-in</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
