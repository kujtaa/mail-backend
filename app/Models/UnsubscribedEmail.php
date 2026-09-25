<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Global suppression list. Once an address is here it is permanent:
 * the model refuses deletes and never overwrites an existing row.
 */
class UnsubscribedEmail extends Model
{
    public const SOURCE_LINK = 'link';
    public const SOURCE_MANUAL = 'manual';

    public $timestamps = false;

    protected $fillable = ['email', 'business_id', 'unsubscribed_at', 'token', 'source', 'added_by', 'note'];

    protected $casts = ['unsubscribed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (UnsubscribedEmail $row) {
            $row->email = self::normalize($row->email);
        });

        // The suppression list is append-only. Removing an address would let it
        // be emailed again, which is exactly what unsubscribing must prevent.
        static::deleting(fn () => false);
    }

    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function contains(string $email): bool
    {
        return static::where('email', self::normalize($email))->exists();
    }

    /**
     * Exclude rows whose $column matches a suppressed address, case-insensitively.
     * Usable on any query builder (Business, joined tables, etc.).
     */
    public static function excludeFrom(Builder|\Illuminate\Database\Query\Builder $query, string $column): void
    {
        $query->whereNotExists(function ($sub) use ($column) {
            $sub->selectRaw('1')
                ->from('unsubscribed_emails')
                ->whereRaw("unsubscribed_emails.email = LOWER({$column})");
        });
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(Company::class, 'added_by');
    }
}
