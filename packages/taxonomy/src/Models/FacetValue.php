<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value in a facet vocabulary — `1080p` in `resolution`.
 *
 * @property int $id
 * @property int $facet_id
 * @property string $value
 * @property string $label
 * @property int $position
 */
class FacetValue extends Model
{
    protected $table = 'taxonomy_facet_values';

    protected $fillable = ['facet_id', 'value', 'label', 'position'];

    protected function casts(): array
    {
        return [
            'facet_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function facet(): BelongsTo
    {
        return $this->belongsTo(Facet::class, 'facet_id');
    }
}
