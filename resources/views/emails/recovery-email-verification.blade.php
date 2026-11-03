<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Verify recovery email</title></head>
<body style="font-family:Arial,sans-serif;color:#222;background:#fbf7f0;padding:28px;">
    <h2 style="color:#7a1717;">Verify your recovery request email</h2>
    <p>Hello {{ $fullName }},</p>
    <p>Confirm that you can access this inbox for your HVFRMS password recovery request.</p>
    <p><a href="{{ $verificationUrl }}" style="display:inline-block;background:#7a1717;color:#fff;padding:12px 20px;border-radius:8px;">Verify email</a></p>
    <p>This verification link expires in 30 minutes. Opening it shows a confirmation page.</p>
    <p>After verifying your email, staff must contact their same-branch manager for identity verification before admin approval. Managers contact admin for identity verification and approval.</p>
    <p>Approval sends a separate one-time password reset link valid for 12 hours. This does not change your account's registered email.</p>
    <p>If you did not request this, ignore this message.</p>
</body></html>
