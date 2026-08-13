@extends('layouts.app')
@section('title', 'Authorise MCP access')
@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-6 col-md-offset-3">
            <div class="panel panel-default" style="margin-top:40px;">
                <div class="panel-heading"><strong>Authorise access</strong></div>
                <div class="panel-body">

                    <p>
                        <strong>{{ $client->getName() }}</strong> is requesting access to
                        the DO Trust support desk on your behalf.
                    </p>

                    <p>Signed in as <strong>{{ $user->getFullName() }}</strong> ({{ $user->email }}).</p>

                    <div class="alert alert-info" style="margin-top:15px;">
                        <strong>What it will be able to do</strong>
                        <ul style="margin:8px 0 0 0; padding-left:18px;">
                            <li>Read support statistics and reports</li>
                            @if ($accessLevel === 'high')
                                <li>Read conversations <strong>including customer details</strong></li>
                            @elseif ($accessLevel === 'medium')
                                <li>Read conversations, with customer details hidden</li>
                            @else
                                <li>Read aggregate figures only — no individual conversations</li>
                            @endif
                        </ul>
                        <p style="margin:10px 0 0 0;">
                            It <strong>cannot</strong> reply to customers, reassign tickets,
                            or change anything.
                        </p>
                    </div>

                    <p class="text-muted" style="font-size:12px;">
                        Your access level is <strong>{{ $accessLevel }}</strong>. You can
                        revoke this at any time from Manage → MCP.
                    </p>

                    <form method="POST" action="{{ route('mcp.oauth.approve') }}">
                        {{ csrf_field() }}
                        <button type="submit" name="action" value="allow" class="btn btn-primary">
                            Allow
                        </button>
                        <button type="submit" name="action" value="deny" class="btn btn-link">
                            Deny
                        </button>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>
@endsection
