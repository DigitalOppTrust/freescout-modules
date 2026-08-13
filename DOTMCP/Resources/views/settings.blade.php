@extends('layouts.app')
@section('title', 'MCP')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dotmcp/css/module.css') }}">
@endsection
@section('content')
<div class="container">
    <h2 class="subheader">MCP</h2>
    @include('partials/flash_messages')

    @if (!$keysReady)
        <div class="alert alert-warning">
            <strong>Not ready.</strong> The OAuth signing keypair has not been generated.
            @if ($isAdmin)
                <form method="POST" action="{{ route('mcp.settings.keys') }}" style="display:inline;">
                    {{ csrf_field() }}
                    <button type="submit" class="btn btn-primary btn-xs">Generate keypair</button>
                </form>
            @else
                An administrator needs to generate it.
            @endif
        </div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Connection</strong></div>
        <div class="panel-body">
            <p>Add this as a custom connector in Claude:</p>
            <code class="mcp-endpoint">{{ $endpoint }}</code>
            <p class="mcp-meta" style="margin:10px 0 0 0;">
                Claude will send you here to sign in and approve. Access is read-only —
                it cannot reply to customers or change anything.
            </p>
        </div>
    </div>

    @if ($isAdmin)
    <h3 class="subheader">Who can use MCP</h3>
    <div class="descr-block">
        <p>
            MCP access is separate from being an administrator — it must be granted
            explicitly. Users without it cannot connect and do not see this page.
        </p>
        <p>
            <strong>Low</strong> sees aggregate figures only.
            <strong>Medium</strong> also sees conversations, with customer details hidden.
            <strong>High</strong> sees everything including customer names and emails.
        </p>
    </div>

    <table class="table table-striped">
        <thead>
            <tr><th>User</th><th>MCP access</th><th>Level</th><th></th></tr>
        </thead>
        <tbody>
        @foreach ($users as $u)
            <tr>
                <td>
                    <strong>{{ $u->getFullName() }}</strong><br>
                    <span class="mcp-meta">{{ $u->email }}</span><br>
                    <span class="mcp-state {{ $u->mcp_enabled ? 'on' : 'off' }}">
                        <span class="dot"></span>{{ $u->mcp_enabled ? 'Enabled' : 'No access' }}
                    </span>
                    @if ($u->mcp_enabled)
                        <span class="mcp-level {{ $u->mcp_access_level ?: 'low' }}">{{ $u->mcp_access_level ?: 'low' }}</span>
                    @endif
                </td>
                <form method="POST" action="{{ route('mcp.settings.user') }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="user_id" value="{{ $u->id }}">
                    <td>
                        <label style="font-weight:normal;">
                            <input type="checkbox" name="mcp_enabled" value="1"
                                {{ $u->mcp_enabled ? 'checked' : '' }}> Enabled
                        </label>
                    </td>
                    <td>
                        <select name="mcp_access_level" class="form-control input-sm">
                            @foreach (['low','medium','high'] as $lvl)
                                <option value="{{ $lvl }}"
                                    {{ ($u->mcp_access_level ?: 'low') === $lvl ? 'selected' : '' }}>
                                    {{ ucfirst($lvl) }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td><button type="submit" class="btn btn-default btn-xs">Save</button></td>
                </form>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <h3 class="subheader">{{ $isAdmin ? 'Active connections' : 'Your connections' }}</h3>
    @if (count($tokens))
        <table class="table table-striped">
            <thead>
                <tr><th>User</th><th>Level</th><th>Last used</th><th>Uses</th><th></th></tr>
            </thead>
            <tbody>
            @foreach ($tokens as $t)
                <tr>
                    <td>{{ $t->user ? $t->user->getFullName() : 'user '.$t->user_id }}</td>
                    <td><span class="mcp-level {{ $t->access_level }}">{{ $t->access_level }}</span></td>
                    <td>
                        {!! $t->last_used_at ? $t->last_used_at->diffForHumans() : '<span class="mcp-never">never</span>' !!}
                    </td>
                    <td>{{ $t->use_count }}</td>
                    <td>
                        <form method="POST" action="{{ route('mcp.settings.revoke') }}"
                              onsubmit="return confirm('Revoke this connection?');">
                            {{ csrf_field() }}
                            <input type="hidden" name="token_id" value="{{ $t->id }}">
                            <button type="submit" class="btn btn-link btn-xs text-danger">Revoke</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="text-muted">No active connections.</p>
    @endif
</div>
@endsection
