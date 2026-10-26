<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your trip was cancelled</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
          <tr>
            <td style="background:#153a6b;padding:28px 32px;">
              <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">SmartTransit</p>
              <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,0.85);">Trip cancelled</p>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px;font-size:15px;color:#374151;">Hi <strong>{{ $name }}</strong>,</p>
              <p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6;">
                The operator cancelled <strong>{{ $route }}</strong> on {{ $date }} at {{ $time }}. We are sorry for the trouble.
                You do not need to do anything.
              </p>

              @if($needsReview)
              <p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6;">
                Your refund for {{ $tickets === 1 ? 'your ticket' : "your {$tickets} tickets" }} is being checked by the operator first,
                because part of the trip had already been travelled. You will get the money back to {{ $wallet }} once it is checked.
              </p>
              @else
              <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
                <p style="margin:0;font-size:14px;color:#166534;">Refund: <strong>&#8369;{{ number_format($amount, 2) }}</strong> to {{ $wallet }}</p>
                <p style="margin:4px 0 0;font-size:13px;color:#166534;">For {{ $tickets === 1 ? 'your ticket' : "your {$tickets} tickets" }}. It can take a few days to show in your account.</p>
              </div>
              @endif

              @if($redirected && $accountEmail)
              <p style="margin:0 0 16px;font-size:13px;color:#92400e;"><strong>Note:</strong> these tickets were bought with {{ $accountEmail }}</p>
              @endif

              <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">To travel another time, please book a new ticket.</p>
            </td>
          </tr>
          <tr>
            <td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;">
              <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">SmartTransit</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
