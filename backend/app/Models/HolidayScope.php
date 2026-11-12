<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayScope extends Model
{
    protected $table = 'holiday_scopes';
    protected $fillable = ['holiday_id', 'scope_type', 'scope_id'];

    public function holiday()
    {
        return $this->belongsTo(Holiday::class);
    }
}
