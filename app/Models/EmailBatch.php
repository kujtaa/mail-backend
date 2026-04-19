<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EmailBatch extends Model {
    public $timestamps = false;
    protected $fillable = ['company_id', 'category_id', 'city_id', 'label', 'batch_size', 'price_paid', 'purchased_at'];
    protected $casts = ['purchased_at' => 'datetime', 'price_paid' => 'float'];
    public function company() { return $this->belongsTo(Company::class); }
    public function category() { return $this->belongsTo(Category::class); }
    public function city() { return $this->belongsTo(City::class); }
    public function batchEmails() { return $this->hasMany(BatchEmail::class, 'batch_id'); }
}
