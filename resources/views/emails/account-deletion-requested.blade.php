<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your account will be deleted</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
          <tr>
            <td style="background:#153a6b;padding:28px 32px;">
              <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">SmartTransit</p>
              <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,0.85);">Account deletion</p>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px;font-size:15px;color:#374151;">Hi <strong>{{ $name }}</strong>,</p>
              <p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6;">
                You asked us to delete your SmartTransit account. We signed you out on every device.
              </p>

              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
                <p style="margin:0;font-size:14px;color:#991b1b;">Your personal details will be erased on <strong>{{ $deletesOn }}</strong>.</p>
                <p style="margin:4px 0 0;font-size:13px;color:#991b1b;">This cannot be undone after that day.</p>
              </div>

              <p style="margin:0 0 16px;font-size:14px;color:#374151;line-height:1.6;">
                Changed your mind? Just sign in again within {{ $graceDays }} days and your account stays.
              </p>
              <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">
                Your past tickets and payments are kept for our records, but without your name or contact details. Your reward points will be lost.
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;">
              <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">If you did not ask for this, sign in now and change your password.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
