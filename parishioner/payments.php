<?php

require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflows.php';

function escape_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$notice = '';
$notice_type = 'info'; // info | success | error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $result = record_payment($user, $_POST);

        $conn->commit();
        $transactionStarted = false;
        $notice = $result['message'];
        $notice_type = 'success';
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        $notice = $exception instanceof DomainException
            ? $exception->getMessage()
            : 'Unable to submit payment.';
        $notice_type = 'error';
    }
}

$unpaidApplications = $conn->execute_query(
    "SELECT a.*, s.name
     FROM applications a
     JOIN services s ON s.id = a.service_id
     WHERE a.user_id = ?
       AND a.status <> 'rejected'
       AND a.fee_snapshot > 0
       AND NOT EXISTS (
           SELECT 1
           FROM payments p
           WHERE p.application_id = a.id
             AND p.status IN ('pending', 'completed')
       )",
    [$user['id']]
)->fetch_all(MYSQLI_ASSOC);

$paymentHistory = $conn->execute_query(
    "SELECT p.*, r.id AS receipt_id, s.name
     FROM payments p
     JOIN applications a ON a.id = p.application_id
     JOIN services s ON s.id = a.service_id
     LEFT JOIN receipts r ON r.payment_id = p.id
     WHERE a.user_id = ?
     ORDER BY p.id DESC",
    [$user['id']]
)->fetch_all(MYSQLI_ASSOC);

$page_id = 'payments';
$page_title = 'My Payments';

require __DIR__ . '/includes/layout.php';
?>
<style>
/* ── Parish concept theme, scoped to this page ────────────────── */
.pay-wrap { --gold: #C9A84C; --gold-lt: #E8C97A; --navy: #1B2A4A; --navy-deep: #0D1828;
  --cream: #FAF7F2; --ink: #1A1510; --ink-70: rgba(26,21,16,.7); --ink-45: rgba(26,21,16,.45);
  --ink-10: rgba(26,21,16,.08); --white: #FFFFFF; --green: #2E8B57; --green-bg: #E7F5EC;
  --wine: #6B2737; --wine-bg: #FBEAEA; --amber: #9A6B12; --amber-bg: #FBF2DE;
  font-family: "DM Sans", sans-serif; color: var(--ink); }
.pay-card { background: var(--white); border: 1px solid var(--ink-10); border-radius: 16px;
  padding: 28px 26px; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(27,42,74,.05); }
.pay-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.pay-card-head .bar { width: 4px; height: 22px; background: linear-gradient(180deg, var(--gold), var(--gold-lt)); border-radius: 3px; }
.pay-card h2 { font-family: "Cormorant Garamond", Georgia, serif; font-size: 1.5rem; color: var(--navy); font-weight: 600; }
.pay-card > p.hint { color: var(--ink-70); font-size: .85rem; margin: 6px 0 20px; line-height: 1.55; }

.pay-notice { border-radius: 10px; padding: 12px 16px; font-size: .85rem; margin-bottom: 18px; }
.pay-notice.success { background: var(--green-bg); color: var(--green); }
.pay-notice.error   { background: var(--wine-bg); color: var(--wine); }
.pay-notice.info    { background: var(--amber-bg); color: var(--amber); }

.pay-field { margin-bottom: 18px; }
.pay-field label { display: block; font-size: .78rem; font-weight: 500; color: var(--ink-70); margin-bottom: 7px; letter-spacing: .2px; }
.pay-field select, .pay-field input[type="text"] {
  width: 100%; padding: 12px 14px; border: 1px solid var(--ink-10); border-radius: 10px;
  font-size: .9rem; font-family: inherit; color: var(--ink); background: var(--cream);
  transition: border-color .2s ease;
}
.pay-field select:focus, .pay-field input[type="text"]:focus { outline: none; border-color: var(--gold); background: var(--white); }

.pay-amount-display { display: flex; align-items: baseline; gap: 8px; background: var(--navy);
  color: var(--white); border-radius: 12px; padding: 16px 18px; margin-bottom: 20px; }
.pay-amount-display .lbl { font-size: .75rem; opacity: .75; text-transform: uppercase; letter-spacing: .5px; }
.pay-amount-display .val { font-family: "Cormorant Garamond", Georgia, serif; font-size: 1.7rem; font-weight: 600; color: var(--gold-lt); }

.pay-method-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 4px; }
@media (max-width: 520px) { .pay-method-row { grid-template-columns: 1fr; } }

.pay-btn { display: inline-flex; align-items: center; gap: 8px; padding: 13px 26px; border: none;
  border-radius: 10px; background: var(--navy); color: var(--white); font-weight: 500; font-size: .88rem;
  cursor: pointer; transition: background .2s ease; }
.pay-btn:hover { background: var(--navy-deep); }

.pay-empty { text-align: center; padding: 34px 10px; color: var(--ink-45); font-size: .88rem; }

.pay-table-wrap { overflow-x: auto; }
.pay-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
.pay-table th { text-align: left; padding: 10px 14px; color: var(--ink-70); font-weight: 500;
  font-size: .74rem; text-transform: uppercase; letter-spacing: .4px; border-bottom: 2px solid var(--ink-10); }
.pay-table td { padding: 14px; border-bottom: 1px solid var(--ink-10); vertical-align: middle; }
.pay-table tr:last-child td { border-bottom: none; }
.pay-table tr:hover td { background: var(--cream); }

.pay-pill { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: .74rem; font-weight: 600; }
.pay-pill.completed { background: var(--green-bg); color: var(--green); }
.pay-pill.pending   { background: var(--amber-bg); color: var(--amber); }
.pay-pill.rejected, .pay-pill.failed { background: var(--wine-bg); color: var(--wine); }

.pay-receipt-link { display: inline-flex; align-items: center; gap: 5px; color: var(--navy);
  font-weight: 500; font-size: .82rem; text-decoration: none; border-bottom: 1px solid var(--gold); }
.pay-receipt-link:hover { color: var(--navy-deep); }
.pay-muted { color: var(--ink-45); }
</style>

<div class="pay-wrap">

  <div class="pay-card">
    <div class="pay-card-head"><div class="bar"></div><h2>Submit Payment Details</h2></div>
    <p class="hint">GCash details are verified by parish staff. For cash payments, please settle at the parish office. Submitting a reference number does not automatically confirm payment.</p>

    <?php if ($notice !== ''): ?>
      <div class="pay-notice <?= escape_html($notice_type) ?>"><?= escape_html($notice) ?></div>
    <?php endif; ?>

    <?php if ($unpaidApplications): ?>
      <form method="post">
        <div class="pay-field">
          <label for="paymentApp">Application</label>
          <select name="application_id" id="paymentApp" required>
            <?php foreach ($unpaidApplications as $application): ?>
              <option
                value="<?= (int) $application['id'] ?>"
                data-fee="<?= escape_html($application['fee_snapshot']) ?>"
              >
                #<?= (int) $application['id'] ?> — <?= escape_html($application['name']) ?>
                (₱<?= number_format((float) $application['fee_snapshot'], 2) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <input type="hidden" name="amount" id="paymentAmount" value="<?= escape_html($unpaidApplications[0]['fee_snapshot']) ?>">

        <div class="pay-amount-display">
          <span class="lbl">Amount Due</span>
          <span class="val">₱<span id="paymentAmountDisplay"><?= number_format((float) $unpaidApplications[0]['fee_snapshot'], 2) ?></span></span>
        </div>

        <div class="pay-method-row">
          <div class="pay-field">
            <label for="paymentMethod">Payment Method</label>
            <select name="payment_method" id="paymentMethod" required>
              <option value="cash">Parish Office (Cash)</option>
              <option value="gcash">GCash</option>
            </select>
          </div>
          <div class="pay-field">
            <label for="referenceNumber">GCash Reference (required for GCash)</label>
            <input type="text" name="reference_number" id="referenceNumber" maxlength="100" placeholder="e.g. 0912345678901">
          </div>
        </div>

        <button type="submit" class="pay-btn">Submit for Verification</button>
      </form>

      <script>
        const paymentApplication = document.getElementById('paymentApp');
        const paymentAmount = document.getElementById('paymentAmount');
        const paymentAmountDisplay = document.getElementById('paymentAmountDisplay');

        function updatePaymentAmount() {
          const selectedOption = paymentApplication.options[paymentApplication.selectedIndex];
          paymentAmount.value = selectedOption.dataset.fee;
          paymentAmountDisplay.textContent = Number(selectedOption.dataset.fee).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        paymentApplication.addEventListener('change', updatePaymentAmount);
      </script>
    <?php else: ?>
      <div class="pay-empty">No unpaid applications need payment right now.</div>
    <?php endif; ?>
  </div>

  <div class="pay-card">
    <div class="pay-card-head"><div class="bar"></div><h2>Payment History</h2></div>

    <?php if ($paymentHistory): ?>
      <div class="pay-table-wrap">
        <table class="pay-table">
          <thead>
            <tr>
              <th>Application</th>
              <th>Service</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Status</th>
              <th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($paymentHistory as $payment): ?>
              <tr>
                <td>#<?= (int) $payment['application_id'] ?></td>
                <td><?= escape_html($payment['name']) ?></td>
                <td>₱<?= number_format((float) $payment['amount'], 2) ?></td>
                <td><?= escape_html(ucfirst($payment['payment_method'])) ?></td>
                <td><span class="pay-pill <?= escape_html($payment['status']) ?>"><?= escape_html(ucfirst($payment['status'])) ?></span></td>
                <td>
                  <?php if (!empty($payment['receipt_id'])): ?>
                    <a class="pay-receipt-link" href="../public/receipt.php?id=<?= (int) $payment['receipt_id'] ?>" target="_blank" rel="noopener noreferrer">Print Receipt</a>
                  <?php else: ?>
                    <span class="pay-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="pay-empty">No payment history yet.</div>
    <?php endif; ?>
  </div>

</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>