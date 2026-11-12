<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceClaimAction extends Model
{
    protected $fillable = [
        'attendance_claim_id',
        'previous_status',
        'new_status',
        'actor_id',
        'actor_role',
        'comment'
    ];

    public function attendanceClaim()
    {
        return $this->belongsTo(AttendanceClaim::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
