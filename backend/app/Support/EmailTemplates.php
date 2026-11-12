<?php

namespace App\Support;

/**
 * Shared modern HTML email templates, styled consistently with the
 * password reset OTP email (dark navy header, gold brand mark, teal accents).
 */
class EmailTemplates
{
    private static function shell(string $eyebrow, string $bodyHtml, string $fromAddress): string
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset='utf-8'>
          <title>" . htmlspecialchars($eyebrow) . "</title>
        </head>
        <body style='font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px;'>
          <div style='max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08);'>
            <div style='background-color: #0F172A; padding: 24px; text-align: center;'>
              <h2 style='color: #D4AF37; margin: 0; font-size: 20px; letter-spacing: 1.5px; text-transform: uppercase;'>Your Company PROPERTIES LLC</h2>
              <p style='color: #94A3B8; margin: 6px 0 0 0; font-size: 13px;'>" . htmlspecialchars($eyebrow) . "</p>
            </div>
            {$bodyHtml}
            <div style='background-color: #F1F5F9; padding: 16px 24px; text-align: center; border-top: 1px solid #E2E8F0;'>
              <p style='color: #94A3B8; font-size: 12px; margin: 0;'>Sent securely from <strong>" . htmlspecialchars($fromAddress) . "</strong></p>
            </div>
          </div>
        </body>
        </html>
        ";
    }

    /**
     * Activation code email sent to an employee's own inbox.
     */
    public static function activationCode(string $employeeName, string $employeeCode, string $code, int $expiryMinutes, string $fromAddress): array
    {
        $subject = "Your Company LLC - Device Activation Code [{$code}]";

        $body = "
            <div style='padding: 30px 24px; text-align: center;'>
              <p style='color: #334155; font-size: 16px; margin-top: 0;'>Dear <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
              <p style='color: #64748B; font-size: 14px; line-height: 1.5;'>You requested to activate the Attendance mobile app for Employee Code <strong>" . htmlspecialchars($employeeCode) . "</strong>.</p>

              <div style='margin: 28px 0; background-color: #F8FAFC; border: 2px dashed #0F5A6E; border-radius: 12px; padding: 18px;'>
                <span style='display: block; font-size: 12px; text-transform: uppercase; color: #64748B; font-weight: bold; letter-spacing: 1px; margin-bottom: 8px;'>Your Device Activation Code</span>
                <span style='font-size: 36px; font-weight: bold; color: #0F5A6E; letter-spacing: 10px;'>" . htmlspecialchars($code) . "</span>
              </div>

              <p style='color: #EF4444; font-size: 13px; font-weight: bold;'>&#9888; This code is valid for {$expiryMinutes} minutes only.</p>
              <p style='color: #64748B; font-size: 13px; line-height: 1.5;'>If you did not request this activation code, please ignore this email or contact the IT Department immediately.</p>
            </div>
        ";

        return ['subject' => $subject, 'html' => self::shell('Device Activation', $body, $fromAddress)];
    }

    /**
     * Internal notification to Support/IT when an employee sets/updates their own
     * email address during the self-service activation flow.
     */
    public static function emailUpdateNotification(array $data, string $fromAddress): array
    {
        $employeeName = htmlspecialchars($data['employee_name'] ?? 'Unknown Employee');
        $employeeCode = htmlspecialchars($data['employee_code'] ?? '—');
        $previousEmail = htmlspecialchars($data['previous_email'] ?? 'None on file');
        $newEmail = htmlspecialchars($data['new_email'] ?? '—');
        $ip = htmlspecialchars($data['ip'] ?? '—');
        $timestamp = htmlspecialchars($data['timestamp'] ?? now()->toDayDateTimeString());

        $device = $data['device'] ?? [];
        $deviceRows = '';
        $deviceFields = [
            'Device Name' => $device['device_name'] ?? null,
            'Device Model' => $device['device_model'] ?? null,
            'Platform' => $device['platform'] ?? null,
            'OS Version' => $device['os_version'] ?? null,
            'App Version' => $device['app_version'] ?? null,
            'Device ID' => $device['device_id'] ?? null,
        ];
        foreach ($deviceFields as $label => $value) {
            if (empty($value)) continue;
            $deviceRows .= "<tr>
                <td style='padding:6px 10px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>" . htmlspecialchars($label) . "</td>
                <td style='padding:6px 10px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>" . htmlspecialchars((string) $value) . "</td>
            </tr>";
        }

        $locationHtml = '<p style="color:#94A3B8;font-size:13px;">Location unavailable (permission denied or GPS off).</p>';
        if (!empty($data['latitude']) && !empty($data['longitude'])) {
            $lat = htmlspecialchars((string) $data['latitude']);
            $lng = htmlspecialchars((string) $data['longitude']);
            $accuracy = isset($data['accuracy']) ? htmlspecialchars((string) $data['accuracy']) . ' m' : 'n/a';
            $mapsUrl = "https://maps.google.com/?q={$lat},{$lng}";
            $locationHtml = "
                <table style='width:100%; border-collapse:collapse; margin-top:6px;'>
                    <tr>
                        <td style='padding:6px 10px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Coordinates</td>
                        <td style='padding:6px 10px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$lat}, {$lng}</td>
                    </tr>
                    <tr>
                        <td style='padding:6px 10px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Accuracy</td>
                        <td style='padding:6px 10px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$accuracy}</td>
                    </tr>
                </table>
                <a href='{$mapsUrl}' style='display:inline-block;margin-top:10px;padding:8px 16px;background-color:#0F5A6E;color:#ffffff;font-size:12px;font-weight:bold;text-decoration:none;border-radius:8px;'>View Exact Location on Map</a>
            ";
        }

        $subject = "Employee Self-Service Email Update - {$employeeName} ({$employeeCode})";

        $body = "
            <div style='padding: 28px 24px;'>
              <p style='color: #334155; font-size: 15px; margin-top: 0;'>An employee updated their own email address via the Attendance mobile app during the device activation flow.</p>

              <table style='width:100%; border-collapse:collapse; margin: 16px 0; background-color:#F8FAFC; border-radius:10px; overflow:hidden;'>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Employee</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$employeeName} ({$employeeCode})</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Previous Email</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$previousEmail}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>New Email</td>
                  <td style='padding:8px 12px;color:#059669;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$newEmail}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;'>Request Time</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;'>{$timestamp} &middot; IP {$ip}</td>
                </tr>
              </table>

              <h3 style='color:#0F172A; font-size:14px; margin: 20px 0 8px;'>Device Details</h3>
              <table style='width:100%; border-collapse:collapse;'>{$deviceRows}</table>

              <h3 style='color:#0F172A; font-size:14px; margin: 20px 0 8px;'>Device Exact Location</h3>
              {$locationHtml}

              <p style='color:#EF4444; font-size:13px; font-weight:bold; margin-top:24px;'>&#9888; If this update was not expected, please verify with the employee before approving further activation requests.</p>
            </div>
        ";

        return ['subject' => $subject, 'html' => self::shell('Security Notification', $body, $fromAddress)];
    }

    /**
     * Notification sent when an employee submits an attendance claim
     * (e.g. a missed check-in/check-out quick-claim from the mobile dashboard).
     */
    public static function attendanceClaimSubmitted(array $data, string $fromAddress): array
    {
        $employeeName = htmlspecialchars($data['employee_name'] ?? 'Employee');
        $employeeCode = htmlspecialchars($data['employee_code'] ?? '—');
        $claimTypeLabel = htmlspecialchars($data['claim_type_label'] ?? 'Attendance Claim');
        $date = htmlspecialchars($data['date'] ?? '—');
        $recordedIn = htmlspecialchars($data['recorded_check_in'] ?? 'Not recorded');
        $recordedOut = htmlspecialchars($data['recorded_check_out'] ?? 'Not recorded');
        $requestedIn = htmlspecialchars($data['requested_check_in'] ?? '—');
        $requestedOut = htmlspecialchars($data['requested_check_out'] ?? '—');
        $reason = htmlspecialchars($data['reason_label'] ?? '—');
        $description = nl2br(htmlspecialchars($data['description'] ?? 'N/A'));
        $submittedAt = htmlspecialchars($data['submitted_at'] ?? now()->toDayDateTimeString());

        $subject = "Attendance Claim Submitted — {$employeeName} ({$employeeCode}) — {$date}";

        $body = "
            <div style='padding: 28px 24px;'>
              <p style='color: #334155; font-size: 15px; margin-top: 0;'>An employee submitted an attendance correction claim from the mobile app.</p>

              <table style='width:100%; border-collapse:collapse; margin: 16px 0; background-color:#F8FAFC; border-radius:10px; overflow:hidden;'>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Employee</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$employeeName} ({$employeeCode})</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Claim Type</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$claimTypeLabel}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Attendance Date</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$date}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;'>Submitted</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;'>{$submittedAt}</td>
                </tr>
              </table>

              <h3 style='color:#0F172A; font-size:14px; margin: 20px 0 8px;'>Recorded vs. Requested</h3>
              <table style='width:100%; border-collapse:collapse;'>
                <tr>
                  <td style='padding:6px 10px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Recorded Check-In</td>
                  <td style='padding:6px 10px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$recordedIn}</td>
                  <td style='padding:6px 10px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Requested Check-In</td>
                  <td style='padding:6px 10px;color:#059669;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$requestedIn}</td>
                </tr>
                <tr>
                  <td style='padding:6px 10px;color:#64748B;font-size:13px;'>Recorded Check-Out</td>
                  <td style='padding:6px 10px;color:#0F172A;font-size:13px;font-weight:600;'>{$recordedOut}</td>
                  <td style='padding:6px 10px;color:#64748B;font-size:13px;'>Requested Check-Out</td>
                  <td style='padding:6px 10px;color:#059669;font-size:13px;font-weight:700;'>{$requestedOut}</td>
                </tr>
              </table>

              <h3 style='color:#0F172A; font-size:14px; margin: 20px 0 8px;'>Reason</h3>
              <p style='color:#334155; font-size:13px;'>{$reason}</p>

              <h3 style='color:#0F172A; font-size:14px; margin: 20px 0 8px;'>Employee Description</h3>
              <p style='color:#334155; font-size:13px; background-color:#F8FAFC; padding:12px; border-radius:8px;'>{$description}</p>

              <p style='color:#64748B; font-size:12px; margin-top:24px;'>Review and action this claim from the Attendance App Control Center or the Manager mobile app.</p>
            </div>
        ";

        return ['subject' => $subject, 'html' => self::shell('Attendance Claim', $body, $fromAddress)];
    }

    /**
     * Notification sent when an employee submits a leave request from the mobile app.
     */
    public static function leaveRequestSubmitted(array $data, string $fromAddress): array
    {
        $employeeName = htmlspecialchars($data['employee_name'] ?? 'Employee');
        $employeeCode = htmlspecialchars($data['employee_code'] ?? '—');
        $leaveType = htmlspecialchars($data['leave_type_name'] ?? 'Leave');
        $dateFrom = htmlspecialchars($data['date_from'] ?? '—');
        $dateTo = htmlspecialchars($data['date_to'] ?? '—');
        $days = htmlspecialchars((string) ($data['days'] ?? '—'));
        $reason = nl2br(htmlspecialchars($data['reason'] ?? 'N/A'));
        $hasAttachment = !empty($data['has_attachment']);
        $submittedAt = htmlspecialchars($data['submitted_at'] ?? now()->toDayDateTimeString());

        $subject = "Leave Request Submitted — {$employeeName} ({$employeeCode}) — {$leaveType}";

        $body = "
            <div style='padding: 28px 24px;'>
              <p style='color: #334155; font-size: 15px; margin-top: 0;'>An employee submitted a leave request from the mobile app.</p>

              <table style='width:100%; border-collapse:collapse; margin: 16px 0; background-color:#F8FAFC; border-radius:10px; overflow:hidden;'>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Employee</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$employeeName} ({$employeeCode})</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Leave Type</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$leaveType}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Dates</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$dateFrom} &rarr; {$dateTo} ({$days} day(s))</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;'>Submitted</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;'>{$submittedAt}</td>
                </tr>
              </table>

              <h3 style='color:#0F172A; font-size:14px; margin: 20px 0 8px;'>Reason</h3>
              <p style='color:#334155; font-size:13px; background-color:#F8FAFC; padding:12px; border-radius:8px;'>{$reason}</p>

              " . ($hasAttachment ? "<p style='color:#059669; font-size:13px; font-weight:600;'>&#128206; A supporting document was attached to this request.</p>" : "") . "

              <p style='color:#64748B; font-size:12px; margin-top:24px;'>Review and action this leave request from the Attendance App Control Center or the Manager mobile app.</p>
            </div>
        ";

        return ['subject' => $subject, 'html' => self::shell('Leave Request', $body, $fromAddress)];
    }

    /**
     * Sent directly to an employee's own inbox when they miss their assigned
     * shift's check-in or check-out time (mirrors the in-app reminder sent
     * via ShiftReminderService, for employees who have an email on file).
     */
    public static function missedShiftAlert(array $data, string $fromAddress): array
    {
        $employeeName = htmlspecialchars($data['employee_name'] ?? 'Employee');
        $employeeCode = htmlspecialchars($data['employee_code'] ?? '—');
        $shiftLabel = htmlspecialchars($data['shift_label'] ?? '—');
        $dateStr = htmlspecialchars($data['date'] ?? '—');
        $eventLabel = htmlspecialchars($data['event_label'] ?? 'Check-in');
        $expectedTime = htmlspecialchars($data['expected_time'] ?? '—');
        $message = htmlspecialchars($data['message'] ?? '');

        $subject = "Missed {$eventLabel} Alert — {$dateStr}";

        $body = "
            <div style='padding: 28px 24px;'>
              <p style='color: #334155; font-size: 15px; margin-top: 0;'>Dear {$employeeName},</p>
              <p style='color: #334155; font-size: 14px;'>{$message}</p>

              <table style='width:100%; border-collapse:collapse; margin: 16px 0; background-color:#F8FAFC; border-radius:10px; overflow:hidden;'>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Employee</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$employeeName} ({$employeeCode})</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Shift</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$shiftLabel}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Date</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$dateStr}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;'>Expected {$eventLabel} Time</td>
                  <td style='padding:8px 12px;color:#B00020;font-size:13px;font-weight:700;'>{$expectedTime}</td>
                </tr>
              </table>

              <p style='color:#64748B; font-size:12px; margin-top:24px;'>If this was in error, please submit a claim or request from the mobile app so it can be reviewed by your manager.</p>
            </div>
        ";

        return ['subject' => $subject, 'html' => self::shell('Shift Reminder', $body, $fromAddress)];
    }

    /**
     * Overdue break alert — shared by the HR/recipients copy and the
     * employee's own copy; `audience` ('hr' or 'employee') only changes the
     * salutation and framing, the detail table is identical.
     */
    public static function overdueBreakAlert(array $data, string $fromAddress): array
    {
        $employeeName = htmlspecialchars($data['employee_name'] ?? 'Employee');
        $employeeCode = htmlspecialchars($data['employee_code'] ?? '—');
        $policyName = htmlspecialchars($data['policy_name'] ?? 'Standard Break');
        $allowedMinutes = htmlspecialchars((string) ($data['allowed_minutes'] ?? '—'));
        $overdueMinutes = htmlspecialchars((string) ($data['overdue_minutes'] ?? '—'));
        $startedAt = htmlspecialchars($data['started_at'] ?? '—');
        $audience = ($data['audience'] ?? 'hr') === 'employee' ? 'employee' : 'hr';

        $subject = $audience === 'employee'
            ? "Your break is overdue — {$overdueMinutes} minutes"
            : "Break Overdue Alert — {$employeeName} ({$employeeCode})";

        $intro = $audience === 'employee'
            ? "<p style='color: #334155; font-size: 14px;'>You have not ended your break and it is now <strong style='color:#B00020;'>{$overdueMinutes} minutes</strong> past the allowed time. Please end your break and resume work as soon as possible.</p>"
            : "<p style='color: #334155; font-size: 14px;'><strong>{$employeeName}</strong> has not ended their break and is now <strong style='color:#B00020;'>{$overdueMinutes} minutes</strong> overdue.</p>";

        $body = "
            <div style='padding: 28px 24px;'>
              <p style='color: #334155; font-size: 15px; margin-top: 0;'>Dear " . ($audience === 'employee' ? $employeeName : 'Team') . ",</p>
              {$intro}
              <table style='width:100%; border-collapse:collapse; margin: 16px 0; background-color:#F8FAFC; border-radius:10px; overflow:hidden;'>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Employee</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:700;border-bottom:1px solid #E2E8F0;'>{$employeeName} ({$employeeCode})</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Break Policy</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$policyName}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Allowed Duration</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$allowedMinutes} minutes</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;border-bottom:1px solid #E2E8F0;'>Break Started At</td>
                  <td style='padding:8px 12px;color:#0F172A;font-size:13px;font-weight:600;border-bottom:1px solid #E2E8F0;'>{$startedAt}</td>
                </tr>
                <tr>
                  <td style='padding:8px 12px;color:#64748B;font-size:13px;'>Overdue By</td>
                  <td style='padding:8px 12px;color:#B00020;font-size:13px;font-weight:700;'>{$overdueMinutes} minutes</td>
                </tr>
              </table>
            </div>
        ";

        return ['subject' => $subject, 'html' => self::shell('Break Overdue Alert', $body, $fromAddress)];
    }
}
