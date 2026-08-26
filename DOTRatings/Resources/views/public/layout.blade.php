{{--
    Standalone layout for the customer-facing pages.

    Deliberately does not extend layouts.app: that template assumes an
    authenticated user and pulls in the whole admin UI - navigation, the
    mailbox switcher, agent-only links. None of it belongs in front of a
    member of the public, and some of it would error without a session.

    Self-contained, no external requests: no CDN, no webfont, no analytics.
    A page that emails a third party every time a customer rates their support
    would be a quiet privacy leak.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Rating pages must never turn up in search results. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Support') &middot; {{ $brand ?? 'Support' }}</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --card: #ffffff;
            --ink: #1a1a1a;
            --muted: #6b7280;
            --line: #e8eaed;
            --star: #f0a202;
            --accent: #2f6fb5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #16181c;
                --card: #1f2227;
                --ink: #e8eaed;
                --muted: #9aa0a6;
                --line: #2f333a;
                --accent: #5b9bd5;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 16px 48px;
            background: var(--bg);
            color: var(--ink);
            font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        }
        .card {
            max-width: 560px;
            margin: 0 auto;
            background: var(--card);
            border-radius: 8px;
            padding: 28px;
        }
        .brand {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
            margin: 0 0 20px;
        }
        h1 { font-size: 19px; margin: 0 0 8px; }
        p { margin: 0 0 16px; }
        .muted { color: var(--muted); font-size: 14px; }
        hr { border: 0; border-top: 1px solid var(--line); margin: 26px 0; }
        .alert {
            background: #fdecea;
            color: #8a1c14;
            border-radius: 5px;
            padding: 10px 12px;
            font-size: 14px;
            margin-bottom: 18px;
        }

        /* Stars: radio inputs styled as labels, so the control works with
           the keyboard and without JavaScript. Reverse row order lets CSS
           highlight every star up to the checked one using a sibling
           selector, which only looks backwards. */
        .stars {
            display: flex;
            flex-direction: row-reverse;
            justify-content: center;
            gap: 6px;
            margin: 4px 0 6px;
        }
        .stars input { position: absolute; opacity: 0; width: 0; height: 0; }
        .stars label {
            font-size: 34px;
            line-height: 1;
            color: var(--line);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
        }
        .stars input:checked ~ label,
        .stars label:hover,
        .stars label:hover ~ label { color: var(--star); }
        .stars input:focus + label { outline: 2px solid var(--accent); }
        .scale {
            display: flex;
            justify-content: space-between;
            max-width: 260px;
            margin: 0 auto 20px;
            font-size: 12px;
            color: var(--muted);
        }

        textarea {
            width: 100%;
            min-height: 96px;
            padding: 10px 12px;
            font: inherit;
            color: var(--ink);
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 5px;
            resize: vertical;
        }
        button {
            width: 100%;
            padding: 12px;
            font: inherit;
            font-weight: 600;
            color: #fff;
            background: var(--accent);
            border: 0;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 12px;
        }
        button:hover { filter: brightness(1.08); }
        .ticket { text-align: center; font-size: 13px; color: var(--muted); margin-top: 22px; }
        details { margin-top: 4px; }
        summary { cursor: pointer; color: var(--accent); font-size: 14px; }
    </style>
</head>
<body>
    <p class="brand">{{ $brand ?? 'Support' }}</p>
    <div class="card">
        @if (session('error'))
            <div class="alert">{{ session('error') }}</div>
        @endif

        @yield('content')
    </div>
</body>
</html>
