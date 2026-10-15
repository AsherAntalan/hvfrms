<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Proforma Invoice — {{ $order->order_number }}</title>
    <style>
        :root{
            --maroon:#7A1717; --maroon-dark:#5C1010; --gold:#D4A94A; --gold-soft:#F3E7CC;
            --ink:#2B2520; --muted:#857B70; --line:#E7E0D4; --paper:#F4EFE7;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--paper);color:var(--ink);
            font:14px/1.55 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;
            font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased}
        ::selection{background:var(--gold-soft);color:var(--maroon-dark)}

        .toolbar{max-width:860px;margin:26px auto 0;display:flex;justify-content:space-between;align-items:center;gap:12px}
        .toolbar a{color:var(--maroon);font-weight:600;text-decoration:none;font-size:13px;display:inline-flex;align-items:center;gap:.4rem}
        .toolbar a:hover{text-decoration:underline}
        .toolbar button{background:var(--maroon);color:#fff;border:0;border-radius:10px;padding:10px 20px;
            font:inherit;font-weight:700;font-size:13px;letter-spacing:.03em;cursor:pointer;
            box-shadow:0 10px 22px -12px rgba(92,16,16,.55)}
        .toolbar button:hover{background:var(--maroon-dark)}

        .sheet{max-width:860px;margin:18px auto 40px;background:#fff;padding:0;
            border:1px solid var(--line);border-radius:16px;overflow:hidden;
            box-shadow:0 1px 2px rgba(60,8,8,.05),0 30px 60px -34px rgba(60,8,8,.35)}
        .band{height:6px;background:linear-gradient(90deg,var(--maroon),var(--maroon-dark) 60%,var(--gold))}
        .pad{padding:40px 44px}

        .head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px}
        .issuer-name{font-family:Georgia,"Times New Roman",serif;font-size:21px;font-weight:700;
            letter-spacing:-.01em;color:var(--maroon);margin:0}
        .issuer-sub{color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase;margin:.3rem 0 0}
        .doc-title{text-align:right}
        .doc-title h1{font-family:Georgia,"Times New Roman",serif;font-size:26px;font-weight:700;
            letter-spacing:.02em;color:var(--maroon);margin:0;line-height:1}
        .doc-ref{font-weight:700;color:var(--ink);margin:.55rem 0 0;font-size:14px}
        .doc-date{color:var(--muted);font-size:12.5px;margin:.15rem 0 0}

        .rule{height:2px;background:var(--gold);border-radius:2px;margin:22px 0 0;opacity:.85}

        .meta{display:flex;gap:24px;margin:26px 0 6px}
        .meta>div{flex:1}
        .label{color:var(--muted);font-size:10.5px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;margin:0 0 6px}
        .meta .big{font-size:16px;font-weight:700;color:var(--ink);margin:0}
        .meta p{margin:3px 0;font-size:13px}
        .meta .kv{color:var(--muted)} .meta .kv b{color:var(--ink);font-weight:600}

        table{width:100%;border-collapse:collapse;margin:26px 0 0}
        thead th{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
            color:var(--maroon);text-align:left;padding:10px 10px;background:var(--gold-soft);
            border-bottom:1px solid var(--line)}
        thead th:first-child{border-radius:8px 0 0 8px}
        thead th:last-child{border-radius:0 8px 8px 0}
        tbody td{padding:13px 10px;border-bottom:1px solid var(--line);vertical-align:top;font-size:13.5px}
        tbody tr:last-child td{border-bottom:0}
        .num{text-align:right;white-space:nowrap}
        .prod{font-weight:600;color:var(--ink)}
        tfoot td{padding:16px 10px 2px}
        .total-label{text-align:right;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
        .total-val{text-align:right;font-family:Georgia,serif;font-size:22px;font-weight:700;color:var(--maroon)}
        .total-row td{border-top:2px solid var(--gold)}

        .adjust{margin:14px 0 0;font-size:12.5px;color:var(--muted)}

        .note{margin:26px 0 0;border:1px solid var(--line);border-radius:12px;background:var(--paper);padding:16px 18px}
        .note-head{display:flex;align-items:center;gap:.5rem;font-weight:700;color:var(--maroon-dark);font-size:13.5px}
        .note-badge{font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
            border-radius:999px;padding:.12rem .5rem}
        .note-badge.has{background:#E6F0E7;color:#2E6B3A}
        .note-badge.none{background:var(--gold-soft);color:#8A6D00}
        .note p{margin:.5rem 0 0;font-size:12.5px;color:var(--muted);line-height:1.6}
        .note .ref{color:var(--ink)}

        .order-notes{margin:20px 0 0;font-size:13px}
        .order-notes b{color:var(--maroon-dark)}
        footer{margin:26px 0 0;padding-top:16px;border-top:1px solid var(--line);
            font-size:11.5px;color:var(--muted);line-height:1.6}

        @media(max-width:600px){
            .pad{padding:24px 20px}.head,.meta{flex-direction:column;gap:14px}
            .doc-title{text-align:left}.doc-title h1{font-size:22px}
            thead th,tbody td{padding:9px 6px}
        }
        @media print{
            @page{size:A4;margin:14mm}
            body{background:#fff}
            .toolbar{display:none}
            .sheet{margin:0;border:0;border-radius:0;max-width:none;box-shadow:none}
            .band{display:none}.pad{padding:0}
            thead{display:table-header-group}tr{break-inside:avoid}
            .note{background:none}
        }
    </style>
</head>
<body>
    <nav class="toolbar" aria-label="Invoice actions">
        <a href="{{ route('orders') }}">&larr; Back to orders</a>
        <button type="button" onclick="window.print()">Print / Save PDF</button>
    </nav>

    <main class="sheet">
        <div class="band"></div>
        <div class="pad">
            <header class="head">
                <div>
                    <h2 class="issuer-name">{{ $invoice['issuer'] }}</h2>
                    <p class="issuer-sub">Commissary order</p>
                </div>
                <div class="doc-title">
                    <h1>PROFORMA INVOICE</h1>
                    <p class="doc-ref">PF-{{ $order->order_number }}</p>
                    <p class="doc-date">{{ $invoice['date'] }} (PHT)</p>
                </div>
            </header>
            <div class="rule"></div>

            <section class="meta">
                <div>
                    <p class="label">Order for</p>
                    <p class="big">{{ $invoice['branch'] }}</p>
                    <p class="kv">Requested by <b>{{ $invoice['requested_by'] }}</b></p>
                </div>
                <div>
                    <p class="label">Details</p>
                    <p class="kv">Order <b>{{ $order->order_number }}</b></p>
                    <p class="kv">Status <b>{{ ucfirst($order->status) }}</b></p>
                    <p class="kv">Currency <b>PHP</b></p>
                    <p class="kv">Confirmed by <b>{{ $order->approvedByUser?->full_name ?? 'Authorized manager' }}</b></p>
                    <p class="kv">Confirmed on <b>{{ $order->approved_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} (PHT)</b></p>
                </div>
            </section>

            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Qty</th>
                        <th>Unit</th>
                        <th class="num">Unit price</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice['lines'] as $line)
                        <tr>
                            <td class="prod">{{ $line['name'] }}</td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td>{{ $line['unit'] }}</td>
                            <td class="num">{{ number_format((float)$line['unit_price'], 2) }}</td>
                            <td class="num">{{ number_format((float)$line['subtotal'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="4" class="total-label">Order total (PHP)</td>
                        <td class="total-val">&#8369;{{ number_format((float)$invoice['total'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>

            @if($order->fulfilled_amount !== null && (float)$order->fulfilled_amount !== (float)$invoice['total'])
                <p class="adjust">Current fulfillment value: PHP {{ number_format((float)$order->fulfilled_amount, 2) }}. The Commissary must review any payment adjustment separately.</p>
            @endif

            <section class="note">
                <div class="note-head">
                    <span>Payment proof</span>
                    @if($order->payment_reference)
                        <span class="note-badge has">Submitted — for manual review</span>
                    @else
                        <span class="note-badge none">No proof recorded</span>
                    @endif
                </div>
                @if($order->payment_reference)
                    <p class="ref">GCash reference: <strong>{{ $order->payment_reference }}</strong></p>
                    <p class="ref">Submitted: {{ $order->payment_submitted_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} (PHT)</p>
                @endif
                <p>This proforma shows the original requested quantities and prices. It is not a payment receipt or confirmation that funds were received.</p>
            </section>

            @if($order->remarks)
                <p class="order-notes"><b>Order notes:</b> {{ $order->remarks }}</p>
            @endif

            <footer>Preparation, dispatch, and branch receipt are tracked separately. Delivery status does not verify payment.</footer>
        </div>
    </main>
</body>
</html>
