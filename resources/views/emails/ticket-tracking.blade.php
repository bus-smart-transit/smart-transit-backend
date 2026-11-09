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
                Your payment went through. Type your tracking code at <strong>Track my bus</strong> to see where your bus is, no account needed.
                Tracking opens about {{ $opensMinutesBefore }} minutes before departure.
              </p>

              @if(! empty($travellerName)){{-- Batch 51 item 7: booked for someone else --}}
              <p style="margin:0 0 20px;font-size:14px;color:#0f172a;background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:10px 14px;">
                Booked for <strong>{{ $travellerName }}</strong>@if(! empty($travellerContact)) ({{ $travellerContact }})@endif. Please send them this ticket, or the QR and the Ticket ID.
              </p>
              @endif

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
                <p style="margin:4px 0 12px;font-size:13px;color:#475569;">{{ $item['route'] }} &middot; {{ $item['date'] }} &middot; {{ $item['time'] }}@if(($item['tickets'] ?? '1') !== '1') &middot; {{ $item['tickets'] }} tickets, one bus @endif</p>
                @if(! empty($item['code']))
                {{-- Batch 49 item 5: the short code is the main way to follow the bus. --}}
                <p style="margin:0 0 4px;font-size:12px;color:#475569;">Your tracking code</p>
                <p style="margin:0 0 6px;font-family:Consolas,Menlo,monospace;font-size:24px;font-weight:700;letter-spacing:3px;color:#0f172a;">{{ $item['code'] }}</p>
                <p style="margin:0 0 12px;font-size:13px;color:#475569;">Type it at <a href="{{ $item['lookup'] }}" style="color:#0f766e;">Track my bus</a>.</p>
                @endif
                <p style="margin:0;">
                  <a href="{{ $item['link'] }}" style="display:inline-block;border:1px solid #cbd5e1;color:#0f172a;text-decoration:none;font-weight:600;font-size:13px;padding:6px 14px;border-radius:8px;">Open map</a>
                </p>
              </div>
              @endforeach

              @if(count($qrCodes) > 0)
              <p style="margin:20px 0 8px;font-size:15px;font-weight:700;color:#0f172a;">Your boarding QR</p>
              <p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.5;">
                Show {{ count($qrCodes) > 1 ? 'each QR' : 'this QR' }} to the Chauffeur when you board.
                @if(count($qrCodes) === 1 && str_starts_with($qrCodes[0]['label'], 'Group QR'))
                  One scan boards everyone in this purchase, so travel together. If some of you will arrive separately, book them as separate tickets.
                @endif
              </p>
              @foreach($qrCodes as $qr)
              <div style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:12px;text-align:center;">
                <img src="{{ isset($message) ? $message->embedData(base64_decode($qr['png']), 'ticket-qr-'.$loop->index.'.png', 'image/png') : 'data:image/png;base64,'.$qr['png'] }}" alt="{{ $qr['label'] }}" width="200" height="200" style="display:block;margin:0 auto;">
                <p style="margin:8px 0 0;font-size:13px;color:#0f172a;">{{ $qr['label'] }}</p>
                @if(! empty($qr['ids'])){{-- Batch 51 item 8: the Chauffeur types it when the QR cannot be shown --}}
                <p style="margin:6px 0 0;font-size:12px;color:#475569;">{{ count($qr['ids']) > 1 ? 'Ticket IDs' : 'Ticket ID' }} (give it to the Chauffeur if you cannot show the QR)</p>
                <p style="margin:2px 0 0;font-family:Consolas,Menlo,monospace;font-size:18px;font-weight:700;letter-spacing:2px;color:#0f172a;">{{ implode(', ', $qr['ids']) }}</p>
                @endif
              </div>
              @endforeach
              @endif

              <p style="margin:16px 0 0;font-size:13px;color:#6b7280;line-height:1.5;">
                Anyone who has the code or the link can see where that bus is, so please do not share them. If one gets out, sign in
                to your account, open the ticket and choose <strong>New tracking link</strong>: the old code and link stop working.
                @if(count($qrCodes) > 0)
                Anyone who has the QR in this email can board with it, so keep this email to yourself.
                @endif
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
