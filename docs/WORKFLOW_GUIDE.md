# Guide to the revised workflows

This guide describes the revised local application. Use the existing accounts and parish assignments. For a demonstration, use the isolated test environment described in [Local setup](../LOCAL_SETUP.md), rather than adding sample transactions to actual parish records.

## Parishioner

### Apply for a service and submit payment information

1. Sign in and open **Apply for Service**.
2. Select the parish, then an active service belonging to that parish.
3. Choose a future schedule. A full day or already-booked service time cannot be submitted successfully.
4. Complete the dynamic fields and upload required PDF/JPG/PNG documents, each up to 5 MB.
5. Review and submit. Successful submission saves a pending application and displays its QR code.
6. Select cash or GCash. For GCash, provide the transfer reference. Submission records payment information pending bookkeeper verification.
7. Open the dashboard or **My Payments** to see stored payment status. Application approval is shown separately. A receipt becomes available after payment verification and issuance.

If submission fails, use the displayed validation message to correct the field, file, or schedule. A browser selection alone does not reserve a slot; the server checks availability when saving.

### Respond to an additional-document request

1. Open **Requested Documents** after receiving a staff request.
2. Read the booking number and requested information.
3. Select the requested PDF/JPG/PNG and submit it. The current flow accepts one document per request.
4. The request shows its submission time and a download link. Staff can access it from the application for review.

### Request a refund or a new schedule

1. Open **Refund / Reschedule**, select the booking, and choose the request type.
2. Enter a reason. For rescheduling, provide the proposed future schedule.
3. Submit and monitor the request status.

A request does not immediately change the booking. Rescheduling changes the schedule only after authorized approval and another capacity check. Refund requests require verified payment; approval does not mean that money has already been returned. Checked-in bookings cannot submit these change requests.

### Ask a question

Open **Help & Messages**, choose the relevant parish, and enter the question. The assistant uses only that parish's active, stored FAQ answers when it finds a sufficiently strong match. If no reliable answer is found, it alerts the secretary. While staff handles the conversation, automated answers remain paused.

### Enable two-factor login

Open **Login Security**, enter the current password, and enable the option. The next password login requires an email code before the dashboard becomes available. Codes expire after ten minutes and cannot be reused. Local development records the code in the protected email simulation log; live mailbox delivery must be configured and tested separately.

## Parish secretary

### Review applications

Open **Applications** and inspect the form details, schedule, and authorized document downloads. Approve, reject with a reason, or assign a valid schedule using the available application actions. The server checks parish ownership and current booking state. Request additional documents when needed; the parishioner submits them through Requested Documents.

### Maintain parish information

Use the mass-schedule page to maintain schedules and the calendar page to manage parish events. Saved information feeds the parishioner views. Service definitions continue to control fees, required form fields/documents, active status, and daily capacity.

### Review rescheduling

Open **Refund / Reschedule** to review reschedule requests for the assigned parish. Approve only after reviewing the requested date, or reject with a reason. Approval saves the new schedule after the server rechecks capacity. Refund processing belongs to the bookkeeper.

### Verify attendance and certificates

Use the check-in page to scan or paste the booking QR URL. The backend requires a valid token, the assigned parish, an approved application, and the scheduled event day. A successful check-in records attendance once; repeating it is rejected. Camera scanning depends on browser support; paste/USB input is available.

For certificates, create or review the sacramental record, confirm the person, date, minister/priest and other content, then generate using the existing template. Issuance preserves an existing certificate number. The printed QR opens the public authenticity check. Confirm parish-specific content before printing or saving as PDF.

### Handle FAQ inquiries

Open **Messages**, respond in the parishioner's existing conversation, and resolve the help thread when finished. Resolving the thread allows FAQ automation to answer subsequent matched questions. Do not treat an automated answer as a newly created parish policy; it reflects stored FAQ content.

## Parish bookkeeper

### Verify payment and issue a receipt

1. Open payment management for the assigned parish.
2. Confirm the actual cash received or verify the submitted GCash transfer through the parish's payment process.
3. Confirm the pending payment. The server checks the fee and state, records the responsible user, and updates the application's payment status.
4. Generate the receipt for the completed payment. The official receipt number is saved with the transaction.
5. Print or save the receipt using the existing output. Repeat confirmation or duplicate receipt issuance is blocked.

### Complete an approved refund

Review the refund request and approve or reject it with the appropriate explanation. After the parish actually returns the money, use the completion action and record the cash-return or transfer reference. Only this final recording updates payment/application state to refunded. The application does not itself transfer refund money.

Use the financial pages to view the parish's payment records, totals, and exports. Access to operational application review, certificates, and member editing belongs to the secretary.

## Administrator

Use the existing administration pages to manage parish and staff configuration, inspect real consolidated reports, and publish announcements to the selected audience. A staff account needs an active assigned parish for staff access. In-app staff-only announcements are not visible to parishioners.

Report data comes from database records. Choose the report type, dates, and relevant parish filter before exporting. Review the meaning of the selected report: a pending payment record is not collected revenue, and a zero-revenue parish remains a valid comparison row.

The administrator's notification inbox actions apply to that administrator's notifications; marking the inbox read does not mark parishioners' messages read.

## Suggested demonstration sequence

| Step | Demonstrate | Evidence to inspect |
|---|---|---|
| 1 | Parishioner submits a valid booking. | Pending application, stored fields/documents, QR confirmation. |
| 2 | Attempt a conflicting schedule or invalid upload. | Validation response and absence of an extra successful booking. |
| 3 | Secretary requests an additional document; applicant submits it. | Persisted request, submission timestamp, authorized staff download. |
| 4 | Secretary approves; bookkeeper verifies payment and issues receipt. | Separate application/payment states and persisted receipt number. |
| 5 | Applicant requests a new schedule or refund. | Request remains separate from completion; authorized decision changes the relevant records. |
| 6 | Ask a matching FAQ, then an unmatched question. | Stored FAQ answer, secretary handoff, human reply in the same conversation. |
| 7 | Enable and complete two-factor login. | Dashboard blocked before the code; successful login after valid verification. |

Event-day check-in needs a test booking scheduled for the demonstration day. Do not change real booking dates to force a demonstration. See the [revision report](../REVISION_REPORT.md) for verified test coverage and limitations.
