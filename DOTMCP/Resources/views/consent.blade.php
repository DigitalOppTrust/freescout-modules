{{--
    Standalone page - deliberately does NOT extend FreeScout's layout.

    The app layout loads FreeScout's global JS bundle, which binds submit
    handlers and calls preventDefault in ~100 places. On the consent screen
    that swallowed the click: the POST still reached the server and issued a
    code, but the browser never followed the 302, so it looked like nothing
    happened. Clicking again then failed because the request was consumed.

    An OAuth consent screen is a boundary page shown to an external client.
    It has no business inheriting the helpdesk's JavaScript.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorise access — DO Trust Support</title>
    <link rel="stylesheet" href="{{ asset('modules/dotmcp/css/module.css') }}">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            background: #f5f7f9;
            margin: 0;
            padding: 40px 20px;
            color: #2c3e50;
            line-height: 1.5;
        }
        .card {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e0e4e8;
            border-radius: 4px;
            overflow: hidden;
        }
        .card-head {
            padding: 16px 24px;
            border-bottom: 1px solid #e0e4e8;
            font-weight: 600;
        }
        .card-body { padding: 24px; }
        .scopes {
            background: #f5f9fc;
            border: 1px solid #d8e6f0;
            border-radius: 3px;
            padding: 14px 16px;
            margin: 18px 0;
        }
        .scopes ul { margin: 8px 0 0 0; padding-left: 18px; }
        .scopes li { margin-bottom: 4px; }
        .actions { margin-top: 24px; }
        button {
            font: inherit;
            cursor: pointer;
            border-radius: 3px;
            padding: 9px 20px;
            border: 1px solid transparent;
        }
        .btn-allow {
            background: #2e7d32;
            border-color: #2e7d32;
            color: #fff;
            font-weight: 600;
        }
        .btn-allow:hover { background: #256428; }
        .btn-deny {
            background: transparent;
            color: #7f8c8d;
            margin-left: 8px;
        }
        .btn-deny:hover { color: #2c3e50; text-decoration: underline; }
        .muted { color: #7f8c8d; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-head">Authorise access</div>
        <div class="card-body">

            <p>
                <strong>{{ $client->getName() }}</strong> is requesting access to the
                DO Trust support desk on your behalf.
            </p>

            <p class="muted">
                Signed in as <strong>{{ $user->getFullName() }}</strong> ({{ $user->email }})
            </p>

            <div class="scopes">
                <strong>What it will be able to do</strong>
                <ul>
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
                    It <strong>cannot</strong> reply to customers, reassign tickets, or
                    change anything.
                </p>
            </div>

            <p class="muted">
                Your access level is
                <span class="mcp-level {{ $accessLevel }}">{{ $accessLevel }}</span>.
                You can revoke this at any time from Manage → MCP.
            </p>

            <form method="POST" action="{{ route('mcp.oauth.approve') }}" class="actions">
                {{ csrf_field() }}
                <button type="submit" name="action" value="allow" class="btn-allow">Allow</button>
                <button type="submit" name="action" value="deny" class="btn-deny">Deny</button>
            </form>

        </div>
    </div>
</body>
</html>
