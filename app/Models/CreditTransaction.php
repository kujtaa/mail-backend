<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model {
    public $timestamps = false;
    protected $fillable = ['company_id', 'amount', 'type', 'description', 'created_at'];
    protected $casts = ['created_at' => 'datetime', 'amount' => 'float'];
    public function company() { return $this->belongsTo(Company::class); }
}
