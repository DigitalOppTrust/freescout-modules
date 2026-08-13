{{--
    Standalone, for the same reason as consent.blade.php: this is shown at an
    OAuth boundary, often to someone not logged into the helpdesk, and should
    not depend on FreeScout's layout or JavaScript.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access not available — DO Trust Support</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            background: #f5f7f9; margin: 0; padding: 40px 20px;
            color: #2c3e50; line-height: 1.5;
        }
        .card {
            max-width: 480px; margin: 0 auto; background: #fff;
            border: 1px solid #e0e4e8; border-radius: 4px; overflow: hidden;
        }
        .card-head {
            padding: 16px 24px; border-bottom: 1px solid #e0e4e8;
            font-weight: 600;
        }
        .card-body { padding: 24px; }
        .muted { color: #7f8c8d; font-size: 13px; }
        a.back {
            display: inline-block; margin-top: 16px; padding: 9px 20px;
            background: #f5f7f9; border: 1px solid #d8dde2; border-radius: 3px;
            color: #2c3e50; text-decoration: none;
        }
        a.back:hover { background: #eceff1; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-head">Access not available</div>
        <div class="card-body">
            <p>{{ $reason }}</p>
            <p class="muted">If you believe this is wrong, contact an administrator.</p>
            <a class="back" href="{{ url('/') }}">Back to the help desk</a>
        </div>
    </div>
</body>
</html>
