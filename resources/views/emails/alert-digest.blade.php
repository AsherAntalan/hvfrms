<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
.container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
.header { background: #8B1A1A; color: #fff; padding: 20px; text-align: center; }
.header h1 { margin: 0; font-size: 20px; }
.body { padding: 20px; }
.alert-card { border-left: 4px solid #ccc; padding: 10px 14px; margin-bottom: 12px; background: #fafafa; border-radius: 0 4px 4px 0; }
.alert-card.critical { border-left-color: #dc3545; background: #fff5f5; }
.alert-card.warning { border-left-color: #E6A800; background: #fffbf0; }
.alert-card.info { border-left-color: #2C6E8F; background: #f0f7fa; }
.severity { font-weight: bold; text-transform: uppercase; font-size: 11px; margin-bottom: 4px; }
.severity.critical { color: #dc3545; }
.severity.warning { color: #E6A800; }
.severity.info { color: #2C6E8F; }
.message { color: #333; font-size: 14px; }
.footer { padding: 16px 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>HVFRMS Alert Digest</h1>
    </div>
    <div class="body">
        <p style="color:#555;font-size:14px;">The following {{ count($alerts) }} alert(s) require attention:</p>
        @foreach($alerts as $alert)
        <div class="alert-card {{ $alert->severity }}">
            <div class="severity {{ $alert->severity }}">{{ $alert->severity }}</div>
            <div class="message">{{ $alert->message }}</div>
        </div>
        @endforeach
    </div>
    <div class="footer">
        This is an automated alert from HVFRMS. Log in to view full details and resolve alerts.
    </div>
</div>
</body>
</html>
