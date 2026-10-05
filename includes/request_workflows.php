<?php
require_once __DIR__ . '/workflows.php';

function create_change_request(array $actor, array $input): void
{
    global $conn;
    must($actor['role'] === 'parishioner', 'Parishioner access required.');
    $type = input_text($input, 'request_type');
    $reason = input_text($input, 'reason', 2000);
    must(in_array($type, ['refund', 'reschedule', 'cancel'], true) && strlen($reason) >= 5,
        'Choose a request type and explain your reason.');

    $previous = owned_application((int) ($input['application_id'] ?? 0), $actor);
    $service = sqlrow('SELECT * FROM services WHERE id=? FOR UPDATE', [$previous['service_id']]);
    $application = owned_application((int) $previous['id'], $actor, true);
    must(!sqlrow("SELECT id FROM application_requests WHERE application_id=? AND request_type=? AND status IN ('pending','approved')", [$application['id'], $type]),
        'An open request already exists.');
    must(!$application['checked_in_at'], 'Checked-in bookings cannot be changed.');

    $date = null;
    if ($type === 'cancel') {
        must(in_array($application['status'],['pending','approved'],true),'Only pending or approved applications can be cancelled.');
    } elseif ($type === 'refund') {
        must($application['payment_status'] === 'paid', 'Only a verified paid booking can request a refund.');
    } else {
        must(in_array($application['status'],['pending','approved'],true), 'Rejected bookings cannot be rescheduled.');
        $date = valid_schedule(input_text($input, 'proposed_schedule'));
        capacity($service, $date, $application['id']);
    }

    $conn->execute_query('INSERT INTO application_requests(application_id,requested_by,request_type,reason,proposed_schedule,original_schedule) VALUES(?,?,?,?,?,?)',
        [$application['id'], $actor['id'], $type, $reason, $date, $application['schedule']]);
    $id = $conn->insert_id;
    auditLog($actor['id'], 'request_' . $type, 'application_request', $id);

    $role = $type === 'refund' ? 'bookkeeper' : 'secretary';
    $recipients = $conn->execute_query("SELECT id FROM users WHERE parish_id=? AND role=? AND status='active'", [$application['parish_id'], $role]);
    foreach ($recipients as $recipient) {
        notify($recipient['id'], 'New ' . $type . ' request', 'Review request #' . $id . '.', 'application', 'requests.php?type='.$type);
        $recipientId=(int)$recipient['id'];
        $GLOBALS['after_commit'][]=fn()=>dispatch_to_user($recipientId,'New '.$type.' request','Review request #'.$id.'.',['sms','email'],'application');
    }
}

function review_change_request(array $actor, array $input): void
{
    global $conn;
    $id = (int) ($input['id'] ?? 0);
    $decision = input_text($input, 'decision');
    $note = input_text($input, 'review_note', 2000);
    $previous = sqlrow('SELECT * FROM application_requests WHERE id=?', [$id]);
    must($previous !== null, 'Request not found.');
    $requiredRole = $previous['request_type'] === 'refund' ? 'bookkeeper' : 'secretary';
    must($actor['role'] === $requiredRole, 'This request belongs to another staff role.');
    must(in_array($decision, ['approve', 'reject', 'complete'], true), 'Invalid decision.');

    $previousApplication = owned_application((int) $previous['application_id'], $actor);
    $service = sqlrow('SELECT * FROM services WHERE id=? FOR UPDATE', [$previousApplication['service_id']]);
    $application = owned_application((int) $previous['application_id'], $actor, true);
    $request = sqlrow('SELECT * FROM application_requests WHERE id=? FOR UPDATE', [$id]);

    if ($decision === 'complete') {
        must($request['request_type'] === 'refund' && $request['status'] === 'approved' && strlen($note) >= 5,
            'An approved refund and transfer/cash-return reference are required.');
        $payment = sqlrow("SELECT * FROM payments WHERE application_id=? AND status='completed' FOR UPDATE", [$application['id']]);
        must($payment !== null, 'No completed payment is available.');
        $conn->execute_query("UPDATE payments SET status='refunded',refund_reason=?,processed_by=?,refunded_at=NOW(),refund_request_id=? WHERE id=?", [$note, $actor['id'], $request['id'], $payment['id']]);
        $conn->execute_query("UPDATE applications SET payment_status='refunded' WHERE id=?", [$application['id']]);
        $state = 'completed';
    } else {
        must($request['status'] === 'pending', 'Request already reviewed.');
        $state = $decision === 'approve' ? 'approved' : 'rejected';
        if ($decision === 'reject') { must(strlen($note) >= 5, 'Explain the rejection.'); }
        if ($decision === 'approve' && $request['request_type'] === 'cancel') {
            must(!$application['checked_in_at'] && in_array($application['status'],['pending','approved'],true), 'This booking cannot be cancelled.');
            $conn->execute_query("UPDATE applications SET status='cancelled' WHERE id=?",[$application['id']]);
            $conn->execute_query("UPDATE sacramental_records SET status='archived' WHERE application_id=?",[$application['id']]);
            $conn->execute_query("UPDATE application_requests SET status='rejected',review_note='Application cancellation approved',reviewed_at=NOW(),reviewed_by=? WHERE application_id=? AND request_type='reschedule' AND status IN ('pending','approved')",[$actor['id'],$application['id']]);
            $state='completed';
        }
        if ($decision === 'approve' && $request['request_type'] === 'refund') {
            must($application['payment_status'] === 'paid'
                && sqlrow("SELECT id FROM payments WHERE application_id=? AND status='completed'", [$application['id']]) !== null,
                'Only a verified paid booking can request a refund.');
        }
        if ($decision === 'approve' && $request['request_type'] === 'reschedule') {
            must(!$application['checked_in_at'] && in_array($application['status'],['pending','approved'],true), 'This booking cannot be rescheduled.');
            $date = valid_schedule($request['proposed_schedule']);
            capacity($service, $date, $application['id']);
            $conn->execute_query('UPDATE applications SET schedule=? WHERE id=?', [$date, $application['id']]);
            // Keep linked records synchronized without overwriting the Secretary's work.
            $conn->execute_query('UPDATE sacramental_records SET date_of_sacrament=DATE(?) WHERE application_id=?', [$date, $application['id']]);
            $state = 'completed';
        }
    }

    $conn->execute_query("UPDATE application_requests SET status=?,reviewed_by=?,review_note=?,reviewed_at=NOW(),completed_at=IF(?='completed',NOW(),NULL) WHERE id=?", [$state, $actor['id'], $note, $state, $id]);
    auditLog($actor['id'], 'request_' . $decision, 'application_request', $id, $note);
    $message='Application #'.$application['id'].' | Service: '.$service['name'].' | '.ucfirst($request['request_type']).' request #'.$id.' | Status: '.$state.' | Schedule: '.display_datetime($application['schedule']);
    if($request['proposed_schedule'])$message.=' | Requested schedule: '.display_datetime($request['proposed_schedule']);
    if($note!=='')$message.=' | Review note: '.$note;
    notify($application['user_id'], 'Request Updated', $message, 'application', 'requests.php?type='.$request['request_type']);
    $GLOBALS['after_commit'][]=fn()=>dispatch_to_user($application['user_id'],'Request Updated',$message,['sms','email'],'application');
}
