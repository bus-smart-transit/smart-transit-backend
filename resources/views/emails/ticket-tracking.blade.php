<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Follow your bus</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">

          <tr>
            <td style="background:linear-gradient(135deg,#16a34a,#22c55e);padding:28px 32px;">
              <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">SmartTransit</p>
              <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,0.85);">Follow your bus</p>
            </td>
          </tr>

          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px;font-size:15px;color:#374151;">
                Hi <strong>{{ $name }}</strong>,
              </p>
              <p style="margin:0 0 20px;font-size:15px;color:#374151;line-height:1.6;">
                Your payment went through. Use {{ count($items) > 1 ? 'these links' : 'this link' }} to see where your bus is, no account needed.
                Tracking opens about {{ $opensMinutesBefore }} minutes before departure.
              </p>

              @if($redirected && $accountEmail)
              <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;margin-bottom:20px;border-radius:4px;">
                <p style="margin:0;font-size:13px;color:#92400e;">
                  <strong>Note:</strong> These tickets were bought with <strong>{{ $accountEmail }}</strong>
                </p>
              </div>
              @endif

              @foreach($items as $item)
              <div style="border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;">
                <p style="margin:0;font-size:14px;font-weight:700;color:#0f172a;">{{ $item['from'] }} &rarr; {{ $item['to'] }}</p>
                <p style="margin:4px 0 12px;font-size:13px;color:#475569;">{{ $item['route'] }} &middot; {{ $item['date'] }} &middot; {{ $item['time'] }}</p>
                <p style="margin:0 0 8px;">
                  <a href="{{ $item['link'] }}" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:10px 22px;border-radius:8px;">Track this bus</a>
                </p>
                <p style="margin:0;font-size:12px;color:#6b7280;word-break:break-all;">{{ $item['link'] }}</p>
              </div>
              @endforeach

              <p style="margin:16px 0 0;font-size:13px;color:#6b7280;line-height:1.5;">
                Anyone who has a link can see where that bus is, so please do not share it. If a link gets out, sign in to your
                account, open the ticket and choose <strong>New tracking link</strong>: the old link stops working.
                This email does not contain your ticket QR code.
              </p>
            </td>
          </tr>

          <tr>
            <td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;">
              <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">
                &copy; {{ date('Y') }} SmartTransit &middot; This is an automated message, please do not reply.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
