<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset your password</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">

          <tr>
            <td style="background:linear-gradient(135deg,#16a34a,#22c55e);padding:28px 32px;">
              <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">SmartTransit</p>
              <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,0.85);">Password reset</p>
            </td>
          </tr>

          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px;font-size:15px;color:#374151;">
                Hi <strong>{{ $name }}</strong>,
              </p>
              <p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;">
                We received a request to reset your password. Choose a new one with the button below. The link expires in <strong>{{ $expiresInMinutes }} minutes</strong> and can be used once.
              </p>

              @if($redirected && $accountEmail)
              <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;margin-bottom:24px;border-radius:4px;">
                <p style="margin:0;font-size:13px;color:#92400e;">
                  <strong>Note:</strong> This link was generated for the account <strong>{{ $accountEmail }}</strong>
                </p>
              </div>
              @endif

              <p style="margin:0 0 24px;text-align:center;">
                <a href="{{ $resetUrl }}" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:12px 28px;border-radius:8px;">Reset password</a>
              </p>

              <p style="margin:0 0 8px;font-size:13px;color:#6b7280;line-height:1.5;">
                If the button does not work, copy this address into your browser:<br>
                <span style="word-break:break-all;color:#0f766e;">{{ $resetUrl }}</span>
              </p>
              <p style="margin:16px 0 0;font-size:13px;color:#6b7280;">
                If you did not ask for this, you can ignore this email. Your password has not changed.
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
