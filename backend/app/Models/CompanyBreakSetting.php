<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyBreakSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'initial_overdue_enabled',
        'initial_delay_minutes',
        'repeat_interval_minutes',
        'employee_repeat_enabled',
        'manager_escalation_delay_minutes',
        'admin_escalation_delay_minutes',
        'repeat_manager_escalation',
        'max_repeat_notifications',
        'push_enabled',
        'in_app_enabled',
        // Also editable from the HR portal's Break Policies tab (writes directly
        // to this table via raw SQL).
        'max_breaks_per_day',
        'max_break_duration_minutes',
        'overdue_alert_threshold_minutes',
        'is_paid_default',
    ];

    protected $casts = [
        'initial_overdue_enabled' => 'boolean',
        'employee_repeat_enabled' => 'boolean',
        'repeat_manager_escalation' => 'boolean',
        'push_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
        'initial_delay_minutes' => 'integer',
        'repeat_interval_minutes' => 'integer',
        'manager_escalation_delay_minutes' => 'integer',
        'admin_escalation_delay_minutes' => 'integer',
        'max_repeat_notifications' => 'integer',
        'max_breaks_per_day' => 'integer',
        'max_break_duration_minutes' => 'integer',
        'overdue_alert_threshold_minutes' => 'integer',
        'is_paid_default' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
