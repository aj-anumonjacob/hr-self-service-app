<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Employee extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'employee_number', 'full_name', 'preferred_name',
        'email', 'mobile_number', 'photo_url', 'company_id', 'department_id',
        'designation_id', 'reporting_manager_id', 'joining_date', 'status',
        'default_location_id', 'assigned_shift_id', 'weekly_off_rule_id',
        'device_registration_status',
        'activation_token', 'activation_token_expires_at', 'external_system',
        'external_id', 'external_reference', 'source_system', 'sync_status'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = Str::uuid()->toString();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    public function reportingManager()
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function subordinates()
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    public function defaultLocation()
    {
        return $this->belongsTo(WorkLocation::class, 'location_id');
    }

    public function location()
    {
        return $this->belongsTo(WorkLocation::class, 'location_id');
    }

    public function assignedShift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function shiftAllocations()
    {
        return $this->hasMany(EmployeeShiftAllocation::class, 'employee_id');
    }

    public function weeklyOffRule()
    {
        return $this->belongsTo(WeeklyOffRule::class, 'weekly_off_rule_id');
    }

    /**
     * Resolve the active shift for the employee for a given date.
     */
    public function getActiveShiftForDate(string $date = null): ?Shift
    {
        $targetDate = $date ? \Carbon\Carbon::parse($date)->format('Y-m-d') : date('Y-m-d');
        $dayName = strtolower(date('l', strtotime($targetDate)));

        // 1. Check for specific roster allocation
        $allocation = EmployeeShiftAllocation::with('shift')
            ->where('employee_id', $this->id)
            ->where('is_active', true)
            ->where('effective_from', '<=', $targetDate)
            ->where(function ($q) use ($targetDate) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $targetDate);
            })
            ->where(function ($q) use ($dayName) {
                $q->where('day_of_week', 'all')->orWhere('day_of_week', $dayName);
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if ($allocation && $allocation->shift) {
            return $allocation->shift;
        }

        // 2. Fallback to default employee shift
        if ($this->shift_id) {
            return Shift::find($this->shift_id);
        }

        // 3. Fallback to default company shift
        return Shift::where('company_id', $this->company_id ?? 1)->first();
    }



    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function devices()
    {
        return $this->hasMany(MobileDevice::class);
    }

    public function corrections()
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function assignments()
    {
        return $this->hasMany(EmployeeLocationAssignment::class);
    }
}
