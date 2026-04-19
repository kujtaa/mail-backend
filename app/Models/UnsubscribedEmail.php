<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class UnsubscribedEmail extends Model {
    public $timestamps = false;
    protected $fillable = ['email', 'business_id', 'unsubscribed_at', 'token'];
    protected $casts = ['unsubscribed_at' => 'datetime'];
}
