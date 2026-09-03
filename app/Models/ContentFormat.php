<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Dalam format apa konten dipublikasikan?" - Single Post/Carousel/Video.
 * BEDA dimensi dari ContentType ("bagaimana konten dikerjakan?" -
 * Desain/Video) - lihat App\Services\ContentFormatResolver buat satu-
 * satunya tempat raw provider value (IMAGE/CAROUSEL_ALBUM/dst) dipetakan
 * ke master ini.
 */
class ContentFormat extends Model
{
    protected $fillable = ['name', 'slug'];

    public function contentItems() { return $this->hasMany(ContentItem::class); }
}
