<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Login Code</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">

          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#16a34a,#22c55e);padding:28px 32px;">
              <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">SmartTransit</p>
              <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,0.85);">One-Time Password (OTP)</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px;font-size:15px;color:#374151;">
                Hi <strong>{{ $name }}</strong>,
              </p>
              <p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;">
                Use the code below to complete your sign-in. It expires in <strong>10 minutes</strong>
              </p>

              <!-- OTP box -->
              <div style="background:#f8fafc;border:2px dashed #22c55e;border-radius:10px;padding:24px;text-align:center;margin-bottom:24px;">
                <p style="margin:0;font-size:42px;font-weight:800;letter-spacing:14px;color:#16a34a;font-family:monospace;">{{ $otp }}</p>
              </div>

              <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">
                If you did not request this code, contact us at <a href="mailto:support@smarttransit.com">support@smarttransit.com</a>.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;">
              <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">
                © {{ date('Y') }} SmartTransit · This is an automated message, please do not reply.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
