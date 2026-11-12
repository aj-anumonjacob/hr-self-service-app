<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRequestAction extends Model
{
    protected $fillable = [
        'attendance_request_id',
        'previous_status',
        'new_status',
        'actor_id',
        'actor_role',
        'comment'
    ];

    public function attendanceRequest()
    {
        return $this->belongsTo(AttendanceRequest::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
