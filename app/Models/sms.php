<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class sms extends Model
{
    use HasFactory;
    protected $table = 'SMS_Log';
    protected $primaryKey = 'twilio_sid';
    protected $fillable = ['twilio_sid','timestamp','from_number','to_number','message_sent','message_received','message_body','log_id','dh_read'];
    public $timestamps = false;

    public function scopeUnread($query)
    {
        return $query->where('dh_read',0);
    }

}
