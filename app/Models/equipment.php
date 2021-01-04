<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class equipment extends Model
{
    use HasFactory;
    protected $table = 'Equipment';
    protected $primaryKey = 'equipment_id';
    public $timestamps = false;

}
