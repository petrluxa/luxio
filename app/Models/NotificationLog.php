<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationLog extends Model
{
    public $timestamps = false;

    protected $table = 'notification_log';

    protected $fillable = ['notifiable_type', 'notifiable_id', 'type', 'channel', 'booking_id', 'sent_at', 'error'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
