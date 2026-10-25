<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset your PWDWork password</title></head>
<body style="margin:0;background:#eaf1f7;color:#1b2b40;font-family:Arial,sans-serif;line-height:1.65">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #cadae5;border-radius:16px">
<tr><td style="padding:24px 28px;background:#142d4e;color:#ffffff;border-radius:16px 16px 0 0;font-size:24px;font-weight:bold">PWDWork<span style="display:block;font-size:13px;font-weight:normal;color:#ddf3f1">Inclusive jobs. Real possibility.</span></td></tr>
<tr><td style="padding:28px">
<h1 style="font-size:24px;line-height:1.3;margin:0 0 18px;color:#142d4e">Reset your password</h1>
<p>We received a request to reset the password for your PWDWork account.</p>
<p>Use the button below to choose a new password. This link expires in {{ $expiresMinutes }} minutes and can be used once.</p>
<p style="margin:26px 0"><a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 24px;background:#167d8d;color:#ffffff;border-radius:8px;text-decoration:none;font-weight:bold">Reset password</a></p>
<p>If you did not request this, you can ignore this email. Your password will stay the same.</p>
<p style="padding:14px;background:#eaf8f5;border-radius:8px">Keep this link private. PWDWork will never ask you to send your password or reset link by email.</p>
<p style="font-size:13px;color:#536579">If the button does not work, copy this entire link into your browser:</p>
<p style="font-size:13px;overflow-wrap:anywhere;word-break:break-all"><a href="{{ $resetUrl }}" style="color:#116c7a">{{ $resetUrl }}</a></p>
<p>Thank you,<br><strong>The PWDWork team</strong></p>
</td></tr></table>
</td></tr></table>
</body></html>
