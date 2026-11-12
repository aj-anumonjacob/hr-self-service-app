<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePolicySetting extends Model
{
    protected $fillable = [
        'company_id',
        'checkin_reminder_upcoming_minutes',
        'checkin_reminder_due_enabled',
        'checkin_reminder_late_escalation_minutes',
        'checkout_reminder_upcoming_minutes',
        'checkout_reminder_due_enabled',
        'checkout_reminder_overdue_minutes',
        'auto_checkout_enabled',
        'auto_checkout_cutoff_time',
        'max_late_delay_minutes',
        'claim_description_max_chars',
        'late_reason_options',
        'claim_reason_options',
        'half_day_threshold_percent',
        'half_day_min_minutes'
    ];

    protected $casts = [
        'checkin_reminder_due_enabled' => 'boolean',
        'checkout_reminder_due_enabled' => 'boolean',
        'auto_checkout_enabled' => 'boolean',
        'late_reason_options' => 'array',
        'claim_reason_options' => 'array',
        'half_day_threshold_percent' => 'float'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get or create default policy settings for a company.
     */
    public static function forCompany(int $companyId): self
    {
        return self::firstOrCreate(
            ['company_id' => $companyId],
            [
                'checkin_reminder_upcoming_minutes' => -5,
                'checkin_reminder_due_enabled' => true,
                'checkin_reminder_late_escalation_minutes' => 10,
                'checkout_reminder_upcoming_minutes' => -5,
                'checkout_reminder_due_enabled' => true,
                'checkout_reminder_overdue_minutes' => 10,
                'auto_checkout_enabled' => true,
                'auto_checkout_cutoff_time' => '23:59:00',
                'max_late_delay_minutes' => 240,
                'claim_description_max_chars' => 500,
                'late_reason_options' => [
                    ['code' => 'medical_appointment', 'label' => 'Medical Appointment'],
                    ['code' => 'family_emergency', 'label' => 'Family Emergency'],
                    ['code' => 'transportation_delay', 'label' => 'Transportation Delay'],
                    ['code' => 'unexpected_traffic_disruption', 'label' => 'Unexpected Traffic Disruption'],
                    ['code' => 'personal_emergency', 'label' => 'Personal Emergency'],
                    ['code' => 'government_official_appointment', 'label' => 'Government or Official Appointment'],
                    ['code' => 'childcare_issue', 'label' => 'Childcare Issue'],
                    ['code' => 'weather_disruption', 'label' => 'Weather Disruption'],
                    ['code' => 'vehicle_breakdown', 'label' => 'Vehicle Breakdown'],
                    ['code' => 'other', 'label' => 'Other']
                ],
                'claim_reason_options' => [
                    ['code' => 'forgot_check_in', 'label' => 'Forgot to check in'],
                    ['code' => 'forgot_check_out', 'label' => 'Forgot to check out'],
                    ['code' => 'wrong_attendance_action', 'label' => 'Accidentally selected wrong attendance action'],
                    ['code' => 'app_issue', 'label' => 'Mobile application issue'],
                    ['code' => 'device_battery_failure', 'label' => 'Device battery failure'],
                    ['code' => 'network_connectivity_issue', 'label' => 'Network connectivity issue'],
                    ['code' => 'location_verification_issue', 'label' => 'Location verification issue'],
                    ['code' => 'emergency_situation', 'label' => 'Emergency situation'],
                    ['code' => 'manager_instructed_schedule_change', 'label' => 'Manager instructed schedule change'],
                    ['code' => 'auto_checkout_time_incorrect', 'label' => 'Automatic checkout time is incorrect'],
                    ['code' => 'other', 'label' => 'Other']
                ],
                'half_day_threshold_percent' => 50.0,
                'half_day_min_minutes' => 240,
            ]
        );
    }
}
