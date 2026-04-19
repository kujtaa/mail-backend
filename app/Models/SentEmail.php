<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SentEmail extends Model {
    public $timestamps = false;
    protected $fillable = ['company_id', 'batch_email_id', 'subject', 'body', 'sent_at', 'status'];
    protected $casts = ['sent_at' => 'datetime'];
    public function company() { return $this->belongsTo(Company::class); }
    public function batchEmail() { return $this->belongsTo(BatchEmail::class); }
}
