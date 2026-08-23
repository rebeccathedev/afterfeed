<?php

namespace App\Models;

use App\Models\Concerns\ScopedThrough;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Post extends Model
{
    use HasFactory, ScopedThrough;

    protected static function userOwnerRelation(): string
    {
        return 'socialAccount';
    }

    protected $fillable = ['social_account_id', 'external_id', 'type', 'body', 'url', 'posted_at', 'deleted_at', 'reply_to_external_id', 'metadata'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'deleted_at' => 'datetime', 'metadata' => 'array'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function archives(): BelongsToMany
    {
        return $this->belongsToMany(Archive::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(PostCollection::class, 'collection_post')->withTimestamps();
    }

    public function annotation(): HasOne
    {
        return $this->hasOne(PostAnnotation::class);
    }

    public function boostedPostByUrl(): HasOne
    {
        return $this->hasOne(self::class, 'url', 'url')->where('type', '!=', 'boost');
    }

    public function resolvedBoostedPost(): ?self
    {
        if ($this->type !== 'boost' || ! $this->url) {
            return null;
        }
        if ($this->relationLoaded('boostedPostByUrl') && $this->boostedPostByUrl) {
            return $this->boostedPostByUrl;
        }

        $externalId = basename(parse_url($this->url, PHP_URL_PATH) ?: '');
        $match = self::query()
            ->with(['socialAccount', 'attachments'])
            ->where('type', '!=', 'boost')
            ->whereHas('socialAccount', fn ($account) => $account->where('platform', 'mastodon'))
            ->where(fn ($query) => $query->where('url', $this->url)->when($externalId !== '', fn ($query) => $query->orWhere('external_id', $externalId)))
            ->first();
        $this->setRelation('boostedPostByUrl', $match);

        return $match;
    }

    public function originalUrl(): ?string
    {
        return $this->socialAccount?->platform === 'facebook' || $this->type === 'boost' ? null : $this->url;
    }

    public function sharedUrl(): ?string
    {
        $url = match ($this->socialAccount?->platform) {
            'facebook' => $this->url ?: data_get($this->metadata, 'external_url'),
            'reddit' => data_get($this->metadata, 'external_url'),
            'mastodon' => $this->type === 'boost' ? $this->url : null,
            default => null,
        };

        if (! is_string($url) || $url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = preg_match('#^[\w.-]+\.[a-z]{2,}(?:/|$)#i', $url) ? 'https://'.$url : null;
        }

        return $url && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    public function sharedLinkHost(): ?string
    {
        $host = parse_url($this->sharedUrl() ?? '', PHP_URL_HOST);

        return $host ? preg_replace('/^www\./i', '', $host) : null;
    }

    public function sharedLinkTitle(): ?string
    {
        if ($this->type === 'boost') {
            return $this->boostedAccount() ?: $this->sharedLinkHost();
        }

        return data_get($this->metadata, 'external_name') ?: $this->sharedLinkHost();
    }

    public function sharedLinkLabel(): string
    {
        return $this->type === 'boost' ? 'Boosted post' : 'Shared link';
    }

    public function sharedLinkAction(): string
    {
        return $this->type === 'boost' ? 'View post' : 'Open';
    }

    public function boostedAccount(): ?string
    {
        if ($this->type !== 'boost' || ! $this->url) {
            return null;
        }
        $host = parse_url($this->url, PHP_URL_HOST);
        $path = parse_url($this->url, PHP_URL_PATH) ?: '';
        if (! $host || ! preg_match('#/(?:users|@|p)/([^/]+)(?:/statuses)?/#', $path.'/', $match)) {
            return null;
        }

        return '@'.$match[1].'@'.preg_replace('/^www\./i', '', $host);
    }

    public function displayBody(): string
    {
        if ($this->body) {
            return $this->body;
        }
        if ($this->type === 'boost') {
            return $this->boostedAccount() ? 'Boosted '.$this->boostedAccount() : 'Boosted a remote post';
        }

        return '';
    }

    public function mapPoint(): ?array
    {
        $latitude = data_get($this->metadata, 'location.latitude') ?? data_get($this->metadata, 'place.coordinate.latitude');
        $longitude = data_get($this->metadata, 'location.longitude') ?? data_get($this->metadata, 'place.coordinate.longitude');
        $coordinates = data_get($this->metadata, 'coordinates.coordinates');
        if ((! is_numeric($latitude) || ! is_numeric($longitude)) && is_array($coordinates) && count($coordinates) >= 2) {
            [$longitude, $latitude] = $coordinates;
        }
        $geo = data_get($this->metadata, 'geo.coordinates');
        if ((! is_numeric($latitude) || ! is_numeric($longitude)) && is_array($geo) && count($geo) >= 2) {
            [$latitude, $longitude] = $geo;
        }
        $bounds = data_get($this->metadata, 'place.bounding_box.coordinates.0');
        if ((! is_numeric($latitude) || ! is_numeric($longitude)) && is_array($bounds) && $bounds !== []) {
            $latitude = collect($bounds)->avg(fn ($point) => $point[1] ?? null);
            $longitude = collect($bounds)->avg(fn ($point) => $point[0] ?? null);
        }
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'place' => data_get($this->metadata, 'location.name') ?: data_get($this->metadata, 'place.full_name') ?: 'Archived location',
        ];
    }

    public function contextLabels(): array
    {
        $labels = [];
        $platform = $this->socialAccount?->platform;

        if ($platform === 'facebook' && ($title = data_get($this->metadata, 'title')) && $title !== $this->body) {
            $name = preg_quote((string) ($this->socialAccount?->display_name ?: ''), '/');
            $labels[] = ucfirst(trim(preg_replace('/^'.$name.'\s+/i', '', $title)));
        }
        if ($platform === 'reddit' && ($community = data_get($this->metadata, 'subreddit'))) {
            $labels[] = 'r/'.ltrim($community, 'r/');
        }
        if ($platform === 'instagram' && $this->type === 'comment' && ($owner = data_get($this->metadata, 'media_owner'))) {
            $labels[] = 'On '.$owner."'s post";
        }
        if ($platform === 'instagram' && ($source = data_get($this->metadata, 'cross_post_source'))) {
            $labels[] = 'Cross-posted from '.$source;
        }

        $place = data_get($this->metadata, 'location.name') ?: data_get($this->metadata, 'place.full_name');
        if ($place) {
            $labels[] = '⌖ '.$place;
        }

        $tags = collect(data_get($this->metadata, 'tags', []))->map(function ($tag) {
            $name = is_array($tag) ? ($tag['name'] ?? null) : $tag;

            return $name ? '#'.ltrim($name, '#') : null;
        })->filter()->take(3)->all();

        return array_values(array_unique([...$labels, ...$tags]));
    }
}
