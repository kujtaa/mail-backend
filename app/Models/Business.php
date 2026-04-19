<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Business extends Model {
    use HasFactory;
    public $timestamps = false;
    protected $fillable = ['name', 'phone', 'email', 'address', 'website', 'city_id', 'category_id', 'source'];
    public function city() { return $this->belongsTo(City::class); }
    public function category() { return $this->belongsTo(Category::class); }
    public function batchEmails() { return $this->hasMany(BatchEmail::class); }
}
