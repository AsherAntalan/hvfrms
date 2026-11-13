<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your HVFRMS password reset code</title>
</head>
<body style="margin:0;padding:0;background:#5C1010;font-family:Arial,Helvetica,sans-serif;color:#2B2B2B;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#5C1010;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#FBF7F0;border-radius:16px;overflow:hidden;border:1px solid #E7E0D4;">
                    <tr>
                        <td style="background:#7A1717;padding:22px 28px;text-align:center;">
                            <div style="color:#D4A94A;font-size:13px;font-weight:bold;letter-spacing:.12em;">HIDDEN VIETNAM</div>
                            <div style="color:#ffffff;font-size:12px;margin-top:2px;">Franchise Management System</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px 10px;">
                            <h1 style="margin:0 0 10px;color:#7A1717;font-size:20px;">Your password reset code</h1>
                            <p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
                                Hi {{ $fullName }}, enter this one-time code on the password reset page to choose a new password.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:6px 32px 18px;">
                            <div style="display:inline-block;background:#F3E7CC;border:1px solid #E7D7AE;border-radius:12px;padding:16px 30px;font-size:34px;font-weight:bold;letter-spacing:.3em;color:#5C1010;">{{ $code }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 22px;">
                            <div style="background:#F3E7CC;border:1px solid #E7D7AE;border-radius:10px;padding:11px 14px;font-size:13px;color:#5C1010;">
                                This code expires in {{ $expiresMinutes }} minutes.
                            </div>
                            <p style="margin:14px 0 0;font-size:12px;color:#6E635C;line-height:1.6;">
                                If you didn't request this, you can ignore this email. Your password will not change. Never share this code with anyone.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#F3EEE4;padding:14px 32px;text-align:center;border-top:1px solid #E7E0D4;">
                            <span style="font-size:11px;color:#8A8A8A;">HVFRMS &bull; Secure account recovery</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
