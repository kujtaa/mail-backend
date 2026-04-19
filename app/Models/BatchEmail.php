<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class BatchEmail extends Model {
    public $timestamps = false;
    protected $fillable = ['batch_id', 'business_id'];
    public function batch() { return $this->belongsTo(EmailBatch::class, 'batch_id'); }
    public function business() { return $this->belongsTo(Business::class); }
    public function sentEmails() { return $this->hasMany(SentEmail::class); }
}
