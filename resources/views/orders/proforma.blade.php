<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Proforma Invoice — {{ $order->order_number }}</title>
    <style>
        :root{
            --maroon:#7A1717; --maroon-dark:#5C1010;
            --ink:#1F1A17; --muted:#6E655C; --label:#6B6158;
            --line:#D7D1C7; --rule:#ECE7DE; --paper:#fff;
        }
        *{box-sizing:border-box}
        html,body{background:#F4F1EA}
        body{margin:0;color:var(--ink);
            font:13.5px/1.55 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;
            font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased}
        ::selection{background:#F0E4E4;color:var(--maroon-dark)}

        .toolbar{max-width:800px;margin:24px auto 0;display:flex;justify-content:space-between;align-items:center;gap:12px}
        .toolbar a{color:var(--maroon);font-weight:600;text-decoration:none;font-size:13px}
        .toolbar a:hover{text-decoration:underline}
        .toolbar button{background:var(--maroon);color:#fff;border:0;border-radius:6px;padding:9px 18px;
            font:inherit;font-weight:700;font-size:13px;cursor:pointer}
        .toolbar button:hover{background:var(--maroon-dark)}

        /* The invoice reads as a plain white document: thin border, minimal lift. */
        .sheet{max-width:800px;margin:14px auto 40px;background:var(--paper);
            border:1px solid var(--line);border-radius:6px;overflow:hidden;
            box-shadow:0 1px 2px rgba(31,26,23,.05)}
        .pad{padding:36px 40px}

        .head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;
            padding-bottom:20px;border-bottom:1px solid var(--line)}
        .brand{display:flex;align-items:center;gap:13px}
        .brand img{width:52px;height:52px;object-fit:contain}
        .issuer-name{font-size:17px;font-weight:800;letter-spacing:-.01em;color:var(--maroon);margin:0}
        .issuer-sub{color:var(--muted);font-size:11px;letter-spacing:.12em;text-transform:uppercase;margin:4px 0 0}
        .doc-title{text-align:right}
        .doc-title h1{font-size:21px;font-weight:800;letter-spacing:.01em;color:var(--maroon);margin:0;line-height:1}
        .doc-meta{margin:5px 0 0;font-size:12px;color:var(--muted)}
        .doc-meta b{color:var(--ink);font-weight:700}

        /* Seller and destination sit as plain columns, not boxed cards. */
        .parties{display:flex;gap:44px;margin:22px 0 0}
        .parties>div{flex:1;min-width:0}
        .label{color:var(--label);font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin:0 0 5px}
        .big{font-size:14.5px;font-weight:700;color:var(--ink);margin:0}
        .parties p{margin:3px 0;font-size:12.5px;color:var(--muted)}
        .parties p b{color:var(--ink);font-weight:600}

        table{width:100%;border-collapse:collapse;margin:26px 0 0}
        thead th{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
            color:#fff;text-align:left;padding:9px 12px;background:var(--maroon)}
        thead th.num{text-align:right}
        tbody td{padding:11px 12px;border-bottom:1px solid var(--rule);vertical-align:top;font-size:13px}
        tbody tr:last-child td{border-bottom:1px solid var(--line)}
        .num{text-align:right;white-space:nowrap}
        .center{text-align:center;color:var(--muted)}
        .prod{font-weight:600;color:var(--ink);overflow-wrap:anywhere}

        .totals{margin:14px 0 0;display:flex;justify-content:flex-end}
        .totals table{width:280px;max-width:100%;margin:0}
        .totals td{padding:6px 12px;border:0;font-size:13px}
        .totals .t-label{text-align:right;color:var(--muted)}
        .totals .t-val{text-align:right;white-space:nowrap;font-weight:600}
        .totals .grand td{border-top:1.5px solid var(--maroon);padding-top:9px}
        .totals .grand .t-label{color:var(--ink);font-weight:700;text-transform:uppercase;letter-spacing:.05em;font-size:11px}
        .totals .grand .t-val{color:var(--maroon);font-size:17px;font-weight:800}

        .adjust{margin:12px 0 0;font-size:12px;color:var(--muted)}

        /* Payment + terms as plain sections under a single rule — no nested cards. */
        .lower{display:flex;gap:44px;margin:26px 0 0;padding-top:20px;border-top:1px solid var(--line);align-items:flex-start}
        .lower>div{flex:1;min-width:0}
        .lower h3{margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--label)}
        .lower p{margin:3px 0;font-size:12.5px;color:var(--muted)}
        .lower p b{color:var(--ink)}
        .pay{display:flex;gap:14px;align-items:flex-start}
        .pay img{width:92px;height:92px;object-fit:contain;border:1px solid var(--line);border-radius:4px;padding:4px;background:#fff;flex:none}
        .proof{margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);font-size:12px;color:var(--muted)}
        .proof b{color:var(--ink)}
        .terms{margin:0;padding-left:16px}
        .terms li{font-size:12.5px;color:var(--muted);margin:4px 0}
        .terms .must{color:var(--ink);font-weight:600}

        .order-notes{margin:16px 0 0;font-size:12.5px;color:var(--muted)}
        .order-notes b{color:var(--ink)}
        footer{margin:24px 0 0;padding-top:14px;border-top:1px solid var(--line);
            display:flex;justify-content:space-between;gap:16px;font-size:11.5px;color:var(--muted);line-height:1.55}
        footer .prepared b{color:var(--ink)}

        @media(max-width:620px){
            .pad{padding:22px 18px}
            .head,.parties,.lower{flex-direction:column;gap:16px}
            .doc-title{text-align:left}
            .totals table{width:100%}
        }
        @media print{
            @page{size:A4 portrait;margin:14mm}
            html,body{background:#fff}
            .toolbar{display:none}
            .sheet{margin:0;border:0;border-radius:0;max-width:none;box-shadow:none}
            .pad{padding:0}
            *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
            thead{display:table-header-group}
            tbody tr{break-inside:avoid}
            .lower,footer,.totals{break-inside:avoid}
        }
    </style>
</head>
<body>
    <nav class="toolbar" aria-label="Invoice actions">
        <a href="{{ route('orders') }}">&larr; Back to orders</a>
        <button type="button" onclick="window.print()">Print / Save PDF</button>
    </nav>

    <main class="sheet">
        <div class="pad">
            <header class="head">
                <div class="brand">
                    <img src="{{ asset('assets/img/logo.jpg') }}" alt="{{ $invoice['issuer'] }} logo">
                    <div>
                        <h2 class="issuer-name">{{ $invoice['issuer'] }}</h2>
                        <p class="issuer-sub">Commissary order</p>
                    </div>
                </div>
                <div class="doc-title">
                    <h1>PROFORMA INVOICE</h1>
                    <p class="doc-meta">Proforma <b>PF-{{ $order->order_number }}</b></p>
                    <p class="doc-meta">Order <b>{{ $order->order_number }}</b></p>
                    <p class="doc-meta">Issued <b>{{ $invoice['date'] }} (PHT)</b></p>
                </div>
            </header>

            <section class="parties">
                <div>
                    <p class="label">Seller</p>
                    <p class="big">{{ $invoice['issuer'] }}</p>
                    <p>Issuing commissary</p>
                </div>
                <div>
                    <p class="label">Bill to / Destination</p>
                    <p class="big">{{ $invoice['branch'] }}</p>
                    @if(!empty($destination['address']))<p>{{ $destination['address'] }}</p>@endif
                    @if(!empty($destination['contact']))<p>Contact <b>{{ $destination['contact'] }}</b></p>@endif
                    <p>Requested by <b>{{ $invoice['requested_by'] }}</b></p>
                </div>
            </section>

            <table>
                <thead>
                    <tr>
                        <th style="width:48px">No.</th>
                        <th>Description</th>
                        <th class="num" style="width:60px">Qty</th>
                        <th style="width:72px">Unit</th>
                        <th class="num" style="width:110px">Unit price</th>
                        <th class="num" style="width:120px">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice['lines'] as $line)
                        <tr>
                            <td class="center">{{ $loop->iteration }}</td>
                            <td class="prod">{{ $line['name'] }}</td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td>{{ $line['unit'] }}</td>
                            <td class="num">&#8369;{{ number_format((float) $line['unit_price'], 2) }}</td>
                            <td class="num">&#8369;{{ number_format((float) $line['subtotal'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="totals">
                <table>
                    <tr>
                        <td class="t-label">Subtotal</td>
                        <td class="t-val">&#8369;{{ number_format((float) $invoice['total'], 2) }}</td>
                    </tr>
                    <tr class="grand">
                        <td class="t-label">Total amount</td>
                        <td class="t-val">&#8369;{{ number_format((float) $invoice['total'], 2) }}</td>
                    </tr>
                </table>
            </div>

            @if($order->fulfilled_amount !== null && (float) $order->fulfilled_amount !== (float) $invoice['total'])
                <p class="adjust">Current fulfillment value: &#8369;{{ number_format((float) $order->fulfilled_amount, 2) }}. This proforma keeps the original issued amount; any payment adjustment is reviewed separately by the Commissary.</p>
            @endif

            <section class="lower">
                <div>
                    <h3>Payment instructions</h3>
                    @if($payment['qr_url'] || $payment['account_name'])
                        <div class="pay">
                            @if($payment['qr_url'])
                                <img src="{{ $payment['qr_url'] }}" alt="GCash receiving QR">
                            @endif
                            <div>
                                <p>Pay via GCash to the configured recipient:</p>
                                @if($payment['account_name'])<p>Name <b>{{ $payment['account_name'] }}</b></p>@endif
                                @if($payment['account_number'])<p>Number <b>{{ $payment['account_number'] }}</b></p>@endif
                                <p>Confirm the recipient in GCash before paying.</p>
                            </div>
                        </div>
                    @else
                        <p>No GCash receiving details are configured yet. Contact the Commissary for payment instructions.</p>
                    @endif
                    <div class="proof">
                        @if($order->payment_reference)
                            <p>Payment proof submitted — <b>for manual review</b>.</p>
                            <p>GCash reference <b>{{ $order->payment_reference }}</b></p>
                            <p>Submitted {{ $order->payment_submitted_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} (PHT)</p>
                        @else
                            <p>No proof recorded.</p>
                        @endif
                    </div>
                </div>

                <div>
                    <h3>Notes &amp; terms</h3>
                    <ul class="terms">
                        <li class="must">For quotation / payment reference only.</li>
                        <li class="must">Not an official receipt.</li>
                        <li>This proforma shows the original requested quantities and prices. It is not a payment receipt or confirmation that funds were received.</li>
                    </ul>
                    @if($order->remarks)
                        <p class="order-notes"><b>Order notes:</b> {{ $order->remarks }}</p>
                    @endif
                </div>
            </section>

            <footer>
                <div class="prepared">
                    <p>Prepared by <b>{{ $order->approvedByUser?->full_name ?? 'Authorized commissary staff' }}</b></p>
                    <p>Confirmed by <b>{{ $order->approvedByUser?->full_name ?? 'Authorized manager' }}</b>
                        · Confirmed on <b>{{ $order->approved_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} (PHT)</b></p>
                </div>
                <div style="text-align:right">
                    <p><b style="color:var(--maroon)">{{ $invoice['issuer'] }}</b></p>
                    <p>Preparation, dispatch, and branch receipt are tracked separately.</p>
                </div>
            </footer>
        </div>
    </main>
</body>
</html>
