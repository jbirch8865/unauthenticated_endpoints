<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class shift extends Model
{
    use HasFactory;
    protected $table = 'Shift';
    protected $primaryKey = 'shift_id';
    public $timestamps = false;

    public function equipment_needs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany('App\Models\shift_has_equipment_need', 'shift_id', 'shift_id');
    }

    public function address(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne('App\Models\address', 'id', 'meet_address');
    }

}
