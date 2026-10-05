<?php

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/service_types.php';
require_once __DIR__.'/attachments.php';
require_once __DIR__.'/service_days.php';

function sqlrow(string $sql, array $args = []): ?array
{
    global $conn;

    return $conn->execute_query($sql, $args)->fetch_assoc() ?: null;
}

function must(bool $valid, string $message): void
{
    if (!$valid) {
        throw new DomainException($message);
    }
}

function valid_schedule(string $value): string
{
    $value = str_replace('T', ' ', trim($value));

    if (strlen($value) === 16) {
        $value .= ':00';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

    must(
        $date !== false
            && $date->format('Y-m-d H:i:s') === $value
            && $date->getTimestamp() > time(),
        'Choose a valid future schedule.'
    );

    return $value;
}

function owned_application(int $id, array $actor, bool $lock = false): array
{
    $sql = 'SELECT * FROM applications WHERE id=?';

    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $application = sqlrow($sql, [$id]);

    must($application !== null, 'Application not found.');

    $isAdmin = $actor['role'] === 'admin';
    $isParishioner = $actor['role'] === 'parishioner';
    $hasAccess = $isAdmin
        || ($isParishioner
            ? (int) $application['user_id'] === (int) $actor['id']
            : (int) $application['parish_id'] === (int) $actor['parish_id']);

    must($hasAccess, 'Application not available.');

    return $application;
}

function capacity(array $service, string $schedule, int $exclude = 0): void
{
    must(service_day_allowed($service,$schedule),'This service is unavailable on the selected weekday.');
    if(($service['schedule_mode']??'user_defined')==='fixed')must(in_array(substr($schedule,11,5),service_time_slots($service),true)&&substr($schedule,17,2)==='00','Choose one of the available service times.');
    $sameSchedule = sqlrow(
        "SELECT COUNT(*) total
         FROM applications
         WHERE service_id=?
           AND schedule=?
           AND status IN ('pending', 'approved')
           AND id<>?",
        [$service['id'], $schedule, $exclude]
    );

    must(
        (int)($service['slot_capacity']??1)===0 || (int)$sameSchedule['total']<(int)($service['slot_capacity']??1),
        'This service time is already booked. Choose another time.'
    );

    $dailyBookings = sqlrow(
        "SELECT COUNT(*) AS total
         FROM applications
         WHERE service_id=?
           AND DATE(schedule)=DATE(?)
           AND status IN ('pending', 'approved')
           AND id<>?",
        [$service['id'], $schedule, $exclude]
    );

    $limit = (int) $service['max_daily_limit'];
    $bookingCount = (int) ($dailyBookings['total'] ?? 0);

    must(
        $limit === 0 || $bookingCount < $limit,
        'This service has reached its daily booking limit.'
    );
}

function submit_booking(array $actor, array $input, array $files): array
{
    global $conn;

    $parishId = (int) ($input['parish_id'] ?? 0);
    $serviceId = (int) ($input['service_id'] ?? 0);
    $schedule = valid_schedule($input['schedule'] ?? '');

    $service = sqlrow(
        "SELECT s.*
         FROM services s
         JOIN parishes p ON p.id=s.parish_id
         WHERE s.id=?
           AND s.parish_id=?
           AND s.status='active'
           AND p.status='active'
         FOR UPDATE",
        [$serviceId, $parishId]
    );

    must($service !== null, 'Select an active service from the selected parish.');
    capacity($service, $schedule);

    $existingBooking = sqlrow(
        "SELECT id
         FROM applications
         WHERE user_id=?
           AND service_id=?
           AND schedule=?
           AND status IN ('pending', 'approved')",
        [$actor['id'], $serviceId, $schedule]
    );

    must($existingBooking === null, 'This booking already exists.');

    $formData = json_decode($input['form_data'] ?? '{}', true);
    must(is_array($formData), 'Invalid application form.');

    $cleanFormData = [];
    $expectedFiles = [];

    foreach ($conn->execute_query(
        'SELECT * FROM service_fields WHERE service_id=?',
        [$serviceId]
    ) as $field) {
        $key = $field['field_name'] ?: 'field_' . $field['id'];

        if ($field['field_type'] === 'file') {
            $expectedFiles['field_' . $field['id']] = [
                'required' => (bool) $field['is_required'],
                'label' => $field['field_label'],
            ];
            continue;
        }

        $value = $formData[$key] ?? '';
        must(is_scalar($value), 'Invalid field value.');

        $value = trim((string) $value);

        must(
            !$field['is_required'] || $value !== '',
            'Required: ' . $field['field_label']
        );
        must(strlen($value) <= 10000, 'Field is too long.');

        if ($value !== '') {
            validate_field_value($field, $value);
        }

        $cleanFormData[$key] = $value;
    }

    foreach ($conn->execute_query(
        'SELECT * FROM service_requirements WHERE service_id=?',
        [$serviceId]
    ) as $requirement) {
        $expectedFiles['req_' . $requirement['id']] = [
            'required' => (bool) $requirement['is_required'],
            'label' => $requirement['document_name'],
        ];
    }

    unset($files['payment_proof']); // Payment proof is validated separately and is not a service requirement.
    $savedAttachments = save_attachment_groups(validate_attachment_groups($expectedFiles, $files));
    $uploadedFiles = $savedAttachments['primary'];

    $qrToken = generateQR('');
    $serviceFee = ($service['amount_mode']??'fixed')==='user_defined'
        ? service_money(input_text($input,'amount')) : $service['fee'];

    must((float)$serviceFee<=0||in_array($input['payment_method']??'', ['cash','gcash'],true),'Choose a payment method before submitting.');
    $conn->execute_query(
        "INSERT INTO applications
            (user_id, parish_id, service_id, schedule, status, uploaded_files,
             payment_status, qr_code, form_data, fee_snapshot)
         VALUES (?, ?, ?, ?, 'pending', ?, 'pending', ?, ?, ?)",
        [
            $actor['id'],
            $parishId,
            $serviceId,
            $schedule,
            json_encode($uploadedFiles),
            $qrToken,
            json_encode($cleanFormData),
            $serviceFee,
        ]
    );

    $applicationId = $conn->insert_id;
    insert_attachment_rows((int)$applicationId,$savedAttachments['rows']);
    $schema=$conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$serviceId])->fetch_all(MYSQLI_ASSOC);
    $conn->execute_query('UPDATE applications SET form_schema=? WHERE id=?',[json_encode($schema),$applicationId]);
    $requirements=$conn->execute_query('SELECT * FROM service_requirements WHERE service_id=?',[$serviceId])->fetch_all(MYSQLI_ASSOC);
    $conn->execute_query('UPDATE applications SET requirement_schema=? WHERE id=?',[json_encode($requirements),$applicationId]);

    notify(
        $actor['id'],
        'Application Submitted',
        'Application #'.$applicationId.' | Service: '.$service['name'].' | Status: Pending review | Schedule: '.$schedule,
        'application',
        'dashboard.php'
    );

    notifyParishStaff(
        $parishId,
        'New Application',
        'Booking #' . $applicationId . ' requires review.',
        'application',
        'applications.php'
    );

    if((float)$serviceFee>0)record_payment($actor,['application_id'=>$applicationId,'amount'=>$serviceFee,'payment_method'=>$input['payment_method'],'reference_number'=>$input['reference_number']??'','manual_method_id'=>$input['manual_method_id']??0]);
    auditLog($actor['id'], 'submit', 'application', $applicationId);
    $GLOBALS['after_commit'][]=fn()=>dispatch_to_user($actor['id'],'Application Submitted','Application #'.$applicationId.' | Service: '.$service['name'].' | Status: Pending review | Schedule: '.$schedule,['sms','email'],'application');

    return [
        'app_id' => $applicationId,
        'qr_code' => $qrToken,
        'qr_url' => getQRImageURL(
            getVerificationURL($applicationId, $parishId, $qrToken)
        ),
        'service_name' => $service['name'],
        'service_fee' => (float) $serviceFee,
    ];
}

function validate_field_value(array $field, string $value): void
{
    switch ($field['field_type']) {
        case 'email':
            must((bool) filter_var($value, FILTER_VALIDATE_EMAIL), 'Invalid email.');
            break;

        case 'number':
            must(
                is_numeric($value) && is_finite((float) $value),
                'Invalid number.'
            );
            break;

        case 'phone':
            must(
                (bool) preg_match('/^[+0-9 ()-]{7,25}$/', $value),
                'Invalid phone number.'
            );
            break;

        case 'date':
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            must(
                $date !== false && $date->format('Y-m-d') === $value,
                'Invalid date.'
            );
            break;

        case 'select':
            $options = array_map(
                'trim',
                explode(',', $field['field_options'] ?? '')
            );
            must(in_array($value, $options, true), 'Invalid selection.');
            break;
    }
}

function validate_uploaded_files(array $expectedFiles, array $files): array
{
    $validatedFiles = [];
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $allowedMimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    foreach ($expectedFiles as $key => $requirement) {
        $file = $files[$key] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            must(!$requirement['required'], 'Upload required: ' . $requirement['label']);
            continue;
        }

        must(
            $file['error'] === UPLOAD_ERR_OK
                && $file['size'] > 0
                && $file['size'] <= 5 * 1024 * 1024,
            'Upload failed or exceeds 5 MB.'
        );

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = $fileInfo->file($file['tmp_name']);

        must(
            isset($allowedMimeTypes[$extension])
                && $mimeType === $allowedMimeTypes[$extension],
            'Upload a valid PDF, JPG or PNG.'
        );

        $validatedFiles[$key] = $file;
    }

    foreach ($files as $key => $file) {
        must(isset($expectedFiles[$key]), 'Unexpected uploaded document.');
    }

    return $validatedFiles;
}

function save_uploaded_files(array $files): array
{
    $uploadedFiles = [];

    foreach ($files as $key => $file) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        do { $filename = bin2hex(random_bytes(20)) . '.' . $extension; $target = private_path($filename); } while(file_exists($target));

        must(move_uploaded_file($file['tmp_name'], $target), 'Unable to save document.');

        $GLOBALS['new_uploads'][] = $target;
        $uploadedFiles[$key] = $filename;
    }

    return $uploadedFiles;
}

function record_payment(array $actor, array $input): array
{
    global $conn;

    $application = owned_application(
        (int) ($input['application_id'] ?? 0),
        $actor,
        true
    );

    must(
        in_array($application['status'],['pending','approved'],true),
        'Only pending or approved applications can be paid.'
    );

    $paymentMethod = $input['payment_method'] ?? $input['method'] ?? '';
    $serviceFee = (float) $application['fee_snapshot'];

    must(
        in_array($paymentMethod, ['gcash', 'cash'], true),
        'Choose GCash or parish-office cash.'
    );
    must(
        $serviceFee > 0
            && abs((float) ($input['amount'] ?? 0) - $serviceFee) < 0.005,
        'Amount must match the service fee.'
    );

    $referenceNumber = trim($input['reference_number'] ?? '');
    $manualId=(int)($input['manual_method_id']??0);$manual=null;$proof=null;
    must($paymentMethod==='cash'||$manualId>0,'Select a digital payment method configured by the parish.');
    if($manualId){
        $manual=sqlrow('SELECT * FROM parish_payment_methods WHERE id=? AND parish_id=? AND active=1',[$manualId,$application['parish_id']]);
        must($manual!==null&&$paymentMethod==='gcash','Choose an available parish payment method.');
    }
    if($manualId||isset($_FILES['payment_proof'])){
        $validated=validate_uploaded_files(['payment_proof'=>['required'=>$manualId>0,'label'=>'Payment receipt screenshot']],isset($_FILES['payment_proof'])?['payment_proof'=>$_FILES['payment_proof']]:[]);
        $proof=save_uploaded_files($validated)['payment_proof']??null;
    }

    must(
        $paymentMethod !== 'gcash'
            || (bool) preg_match('/^[A-Za-z0-9-]{6,100}$/', $referenceNumber),
        'Enter a valid digital payment reference.'
    );

    must(
        sqlrow(
            "SELECT id
             FROM payments
             WHERE application_id=?
               AND status IN ('pending', 'completed')",
            [$application['id']]
        ) === null,
        'Payment already submitted.'
    );

    if ($referenceNumber !== '') {
        must(
            sqlrow(
                'SELECT id FROM payments WHERE reference_number=? FOR UPDATE',
                [$referenceNumber]
            ) === null,
            'This reference has already been used.'
        );
    }

    $conn->execute_query(
        "INSERT INTO payments
            (application_id, amount, payment_method, reference_number, status)
         VALUES (?, ?, ?, ?, 'pending')",
        [
            $application['id'],
            $serviceFee,
            $paymentMethod,
            $referenceNumber !== '' ? $referenceNumber : null,
        ]
    );

    $paymentId = $conn->insert_id;
    $conn->execute_query('UPDATE payments SET manual_method_id=?,manual_method_name=?,proof_file=? WHERE id=?',[$manualId?:null,$manual['name']??null,$proof,$paymentId]);

    auditLog($actor['id'], 'submit_payment', 'payment', $paymentId);

    return [
        'id' => $paymentId,
        'message' => 'Payment submitted for staff verification.',
    ];
}

function confirm_payment(array $actor, int $id): array
{
    global $conn;

    must(
        $actor['role']==='bookkeeper',
        'Bookkeeper access required.'
    );

    $payment = sqlrow('SELECT * FROM payments WHERE id=? FOR UPDATE', [$id]);
    must($payment !== null, 'Payment not found.');

    $application = owned_application(
        (int) $payment['application_id'],
        $actor,
        true
    );

    must($payment['status'] === 'pending', 'Payment already processed.');
    must(
        $application['status'] !== 'rejected'
            && abs(
                (float) $payment['amount']
                - (float) $application['fee_snapshot']
            ) < 0.005,
        'Payment does not match the application fee.'
    );

    $conn->execute_query(
        "UPDATE payments
         SET status='completed',
             paid_at=NOW(),
             processed_by=?,
             verified_by=?,
             verified_at=NOW()
         WHERE id=?",
        [$actor['id'], $actor['id'], $id]
    );

    $conn->execute_query(
        "UPDATE applications SET payment_status='paid' WHERE id=?",
        [$application['id']]
    );

    auditLog($actor['id'], 'verify_payment', 'payment', $id);
    $paymentService=sqlrow('SELECT name FROM services WHERE id=?',[$application['service_id']]);
    $paymentMessage='Application #'.$application['id'].' | Service: '.($paymentService['name']??'Parish service').' | Payment #'.$id.' | Status: Verified | Amount: PHP '.number_format((float)$payment['amount'],2).' | Schedule: '.display_datetime($application['schedule']);

    notify(
        $application['user_id'],
        'Payment Confirmed',
        $paymentMessage,
        'payment',
        'dashboard.php'
    );

    $GLOBALS['after_commit'][] = fn () => dispatch_to_user(
        $application['user_id'],
        'Payment Confirmed',
        $paymentMessage,
        ['sms', 'email'],
        'payment'
    );

    return ['message' => 'Payment verified.'];
}

function decide_application(
    array $actor,
    int $id,
    string $action,
    array $input
): array {
    global $conn;

    must(
        $actor['role'] === 'secretary',
        'Secretary access required.'
    );

    $previousApplication = owned_application($id, $actor);
    $service = sqlrow(
        'SELECT * FROM services WHERE id=? FOR UPDATE',
        [$previousApplication['service_id']]
    );
    $application = owned_application($id, $actor, true);

    if ($action === 'assign_schedule') {
        must(in_array($application['status'],['pending','approved'],true) && !$application['checked_in_at'],'This booking cannot be rescheduled.');
        must($actor['role'] === 'secretary', 'Secretary access required.');
        $schedule = valid_schedule($input['schedule'] ?? '');
        capacity($service, $schedule, $id);

        $conn->execute_query(
            'UPDATE applications SET schedule=? WHERE id=?',
            [$schedule, $id]
        );
        $conn->execute_query('UPDATE sacramental_records SET date_of_sacrament=DATE(?) WHERE application_id=?',[$schedule,$id]);
    } else {
        must(
            $application['status'] === 'pending',
            'Application has already been reviewed.'
        );

        if ($action === 'approve') {
            valid_schedule($application['schedule']);
            capacity($service, $application['schedule'], $id);
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';
        $reason = trim($input['reason'] ?? '');

        must(
            $status !== 'rejected' || $reason !== '',
            'Explain the rejection.'
        );

        $conn->execute_query(
            'UPDATE applications SET status=?, rejection_reason=? WHERE id=?',
            [$status, $reason !== '' ? $reason : null, $id]
        );
    }

    if ($action === 'approve') {
        require_once __DIR__ . '/application_revisions.php';
        create_approved_sacramental_record($actor, $application, $service);
    }

    $updateMessage='Application #'.$id.' | Service: '.$service['name'].' | Status: '.($action==='assign_schedule'?'Rescheduled':($action==='approve'?'Approved':'Rejected')).' | Schedule: '.display_datetime($schedule??$application['schedule']);
    if($action==='assign_schedule')$updateMessage.=' | Previous schedule: '.display_datetime($application['schedule']);
    if(!empty($reason))$updateMessage.=' | Reason: '.$reason;
    auditLog($actor['id'], $action, 'application', $id, $updateMessage);

    notify(
        $application['user_id'],
        'Application Updated',
        $updateMessage,
        'application',
        'dashboard.php'
    );

    $GLOBALS['after_commit'][] = fn () => dispatch_to_user(
        $application['user_id'],
        'Application Updated',
        $updateMessage,
        ['sms', 'email'],
        'application'
    );

    return ['message' => 'Application updated.'];
}

function create_receipt(array $actor, int $paymentId): array
{
    global $conn;
    must($actor['role'] === 'bookkeeper', 'Bookkeeper access required.');

    $payment = sqlrow(
        'SELECT * FROM payments WHERE id=? FOR UPDATE',
        [$paymentId]
    );

    must(
        $payment !== null && $payment['status'] === 'completed',
        'A completed payment is required.'
    );

    $application = owned_application(
        (int) $payment['application_id'],
        $actor,
        true
    );

    must(!$payment['receipt_number'], 'Receipt already issued.');

    if (!$application['qr_code']) {
        $conn->execute_query(
            'UPDATE applications SET qr_code=? WHERE id=?',
            [generateQR(), $application['id']]
        );
    }

    $user = sqlrow(
        'SELECT name FROM users WHERE id=?',
        [$application['user_id']]
    );
    $service = sqlrow(
        'SELECT name FROM services WHERE id=?',
        [$application['service_id']]
    );
    $parish = sqlrow(
        'SELECT name FROM parishes WHERE id=?',
        [$application['parish_id']]
    );

    $receiptNumber = 'OR-' . date('Y') . '-' . str_pad(
        (string) $paymentId,
        8,
        '0',
        STR_PAD_LEFT
    );

    $conn->execute_query(
        'INSERT INTO receipts
            (payment_id, receipt_number, amount, parishioner_name,
             service_name, parish_name, issued_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $paymentId,
            $receiptNumber,
            $payment['amount'],
            $user['name'],
            $service['name'],
            $parish['name'],
            $actor['id'],
        ]
    );

    $receiptId = $conn->insert_id;

    $conn->execute_query(
        'UPDATE payments SET receipt_number=? WHERE id=?',
        [$receiptNumber, $paymentId]
    );

    auditLog($actor['id'], 'generate_receipt', 'receipt', $receiptId);

    return [
        'id' => $receiptId,
        'receipt_number' => $receiptNumber,
        'message' => 'Receipt issued.',
    ];
}

function issue_certificate(array $actor, int $id): array
{
    global $conn;

    require_once __DIR__ . '/pdf.php';

    must(
        in_array($actor['role'], ['secretary', 'admin'], true),
        'Secretary access required.'
    );

    $record = sqlrow(
        "SELECT sr.*,
                p.name AS parish_name,
                p.address AS parish_address,
                p.priest_name
         FROM sacramental_records sr
         JOIN parishes p ON p.id=sr.parish_id
         WHERE sr.id=?
         FOR UPDATE",
        [$id]
    );

    $hasAccess = $record !== null
        && ($actor['role'] === 'admin'
            || (int) $record['parish_id'] === (int) $actor['parish_id']);

    must($hasAccess, 'Record not available.');
    must($record['status'] === 'active', 'Archived records cannot be issued.');

    if (!$record['certificate_number']) {
        $type = strtoupper(substr(
            preg_replace('/[^a-z]/i', '', $record['record_type']),
            0,
            3
        ));

        $record['certificate_number'] = 'AVSJ-'
            . date('Y')
            . '-' . $type
            . '-' . str_pad((string) $record['id'], 5, '0', STR_PAD_LEFT);

        must(
            sqlrow(
                'SELECT id
                 FROM sacramental_records
                 WHERE certificate_number=? AND id<>?',
                [$record['certificate_number'], $id]
            ) === null,
            'Certificate identifier conflict; contact the administrator.'
        );

        $conn->execute_query(
            'UPDATE sacramental_records
             SET certificate_number=?, certificate_generated_at=NOW()
             WHERE id=?',
            [$record['certificate_number'], $record['id']]
        );

        auditLog($actor['id'], 'generate_certificate', 'sacramental_record', $id);
    }

    $record['minister_name'] = $record['minister_name'] ?: $record['priest_name'];

    $html = generateCertificateHTML(
        $record,
        [
            'name' => $record['parish_name'],
            'address' => $record['parish_address'],
        ],
        getQRImageURL(
            app_url('public/verify.php')
                . '?cert=' . urlencode($record['certificate_number']),
            140
        )
    );

    return [
        'certificate_number' => $record['certificate_number'],
        'html' => $html,
    ];
}


function fail_payment(array $actor,int $id,string $reason): array {
    global $conn;
    must($actor['role']==='bookkeeper','Bookkeeper access required.');
    must(trim($reason)!==''&&mb_strlen($reason)<=1000,'Enter a failure reason (up to 1000 characters).');
    $payment=sqlrow('SELECT p.* FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.id=? AND a.parish_id=? FOR UPDATE',[$id,$actor['parish_id']]);
    must($payment!==null&&$payment['status']==='pending','Only a pending payment in your parish can be marked failed.');
    $application=owned_application((int)$payment['application_id'],$actor,true);
    must(in_array($application['status'],['pending','approved'],true),'This application is no longer active.');
    $conn->execute_query("UPDATE payments SET status='failed',failure_reason=?,failed_by=?,failed_at=NOW() WHERE id=?",[trim($reason),$actor['id'],$id]);
    // Do not cancel the booking or alter verified totals. A new payment may be submitted.
    auditLog($actor['id'],'fail_payment','payment',$id,trim($reason));
    notify($application['user_id'],'Payment failed',trim($reason),'payment','payments.php');
    return ['message'=>'Payment marked failed. No receipt issued; the application may be paid again.'];
}
