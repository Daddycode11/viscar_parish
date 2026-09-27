<section class="card"><div class="card-body"><h2><?= h(t('Help and FAQ')) ?></h2>
<?php foreach([
    'How do I apply?' => 'Choose a parish, service and available schedule. Complete the required fields and upload the requested documents.',
    'How are payments verified?' => 'Submit your payment details. The Bookkeeper verifies payment before it is marked paid; submitting a reference alone does not confirm payment.',
    'How do refunds work?' => 'Request a refund for a verified paid booking. Only the Bookkeeper reviews it. Approval is separate from recording the actual returned payment.',
    'How do I reschedule?' => 'Submit a future schedule and reason. Only the Secretary can approve the change, subject to availability.',
    'Where are my documents and QR code?' => 'Open Application details to view your documents and print the verification QR. The QR shows only safe verification information.',
    'How do I choose notifications?' => 'Select your parishes and delivery channels in Settings. Clear the selections to unsubscribe from parish announcements. System-wide notices remain available.',
] as $question=>$answer): ?><details><summary><?= h(t($question)) ?></summary><p><?= h(t($answer)) ?></p></details><?php endforeach; ?>
<p><a href="help.php"><?= h(t('Help & Messages')) ?></a></p></div></section>
