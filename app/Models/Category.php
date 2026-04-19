<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Category extends Model {
    use HasFactory;
    public $timestamps = false;
    protected $fillable = ['name'];
    public function businesses() { return $this->hasMany(Business::class); }
    public function emailBatches() { return $this->hasMany(EmailBatch::class); }
}
