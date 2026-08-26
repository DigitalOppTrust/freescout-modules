{{--
    The closure email.

    Tables and inline styles, not modern CSS: Outlook renders with Word's HTML
    engine, which ignores flexbox, grid and most of a <style> block. This looks
    dated because the alternative does not arrive intact.

    The stars are five separate links rather than a widget. Anything
    interactive is stripped by mail clients, and a link is the only control
    that reliably survives.
--}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#f4f5f7;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
    <tr>
        <td align="center">
            <table width="560" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px;background:#ffffff;border-radius:6px;padding:32px;">

                <tr>
                    <td style="font-size:17px;font-weight:600;color:#1a1a1a;padding-bottom:16px;">
                        Your ticket #{{ $conversation->number }} has been closed
                    </td>
                </tr>

                <tr>
                    <td style="font-size:15px;line-height:1.55;color:#3d4852;padding-bottom:28px;">
                        {{ $explanation }}
                    </td>
                </tr>

                <tr>
                    <td style="border-top:1px solid #e8eaed;padding-top:26px;font-size:15px;
                               font-weight:600;color:#1a1a1a;padding-bottom:4px;">
                        How did we do?
                    </td>
                </tr>

                <tr>
                    <td style="font-size:14px;line-height:1.5;color:#6b7280;padding-bottom:16px;">
                        Tap a star to rate the support you received.
                    </td>
                </tr>

                <tr>
                    <td align="center" style="padding-bottom:10px;">
                        <table cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                @for ($stars = 1; $stars <= 5; $stars++)
                                    <td style="padding:0 5px;">
                                        <a href="{{ $rate_url }}?stars={{ $stars }}"
                                           style="display:block;width:46px;height:46px;line-height:46px;
                                                  text-align:center;font-size:24px;text-decoration:none;
                                                  color:#f0a202;background:#fdf8ec;border:1px solid #f4e2bc;
                                                  border-radius:5px;">&#9733;</a>
                                    </td>
                                @endfor
                            </tr>
                            <tr>
                                <td style="font-size:11px;color:#9aa0a6;padding-top:6px;">Poor</td>
                                <td colspan="3"></td>
                                <td style="font-size:11px;color:#9aa0a6;padding-top:6px;text-align:right;">Great</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="font-size:13px;color:#9aa0a6;padding-bottom:28px;">
                        You can add a comment on the next page.
                    </td>
                </tr>

                <tr>
                    <td style="border-top:1px solid #e8eaed;padding-top:26px;font-size:15px;
                               font-weight:600;color:#1a1a1a;padding-bottom:4px;">
                        Still need help?
                    </td>
                </tr>

                <tr>
                    <td style="font-size:15px;line-height:1.55;color:#3d4852;">
                        Just reply to this email and your ticket will reopen. There is no need
                        to start a new one.
                    </td>
                </tr>

                <tr>
                    <td style="border-top:1px solid #e8eaed;margin-top:26px;padding-top:20px;
                               font-size:13px;color:#9aa0a6;">
                        {{ $mailbox->name }}
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
