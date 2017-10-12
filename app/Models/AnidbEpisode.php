<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnidbEpisode extends Model
{
    /**
     * @var bool
     */
    protected $dateFormat = false;

    /**
     * @var string
     */
    protected $primaryKey = 'anidbid';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $guarded = [];

    public function title()
    {
        return $this->belongsTo('App\Models\AnidbTitle', 'anidbid');
    }

    public function info()
    {
        return $this->hasMany('App\Modles\AnidbInfo', 'anidbid');
    }
}
