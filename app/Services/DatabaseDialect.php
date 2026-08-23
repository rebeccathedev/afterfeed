<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DatabaseDialect
{
    public function __construct(private readonly ?string $driverOverride = null) {}

    public function driver(): string
    {
        return $this->driverOverride ?: DB::connection()->getDriverName();
    }

    public function year(string $column): string
    {
        return match ($this->driver()) {
            'mysql', 'mariadb' => "year({$column})",
            'pgsql' => "cast(extract(year from {$column}) as integer)",
            default => "strftime('%Y', {$column})",
        };
    }

    public function hour(string $column): string
    {
        return match ($this->driver()) {
            'mysql', 'mariadb' => "hour({$column})",
            'pgsql' => "cast(extract(hour from {$column}) as integer)",
            default => "cast(strftime('%H', {$column}) as integer)",
        };
    }

    public function weekday(string $column): string
    {
        return match ($this->driver()) {
            'mysql', 'mariadb' => "dayofweek({$column}) - 1",
            'pgsql' => "cast(extract(dow from {$column}) as integer)",
            default => "cast(strftime('%w', {$column}) as integer)",
        };
    }

    public function monthDay(string $column): string
    {
        return match ($this->driver()) {
            'mysql', 'mariadb' => "date_format({$column}, '%m-%d')",
            'pgsql' => "to_char({$column}, 'MM-DD')",
            default => "strftime('%m-%d', {$column})",
        };
    }

    public function jsonText(string $column, string $path): string
    {
        $segments = implode(',', explode('.', $path));
        $jsonPath = collect(explode('.', $path))->reduce(fn ($path, $segment) => $path.(ctype_digit($segment) ? "[{$segment}]" : ".{$segment}"), '$');

        return match ($this->driver()) {
            'mysql', 'mariadb' => "json_unquote(json_extract({$column}, '{$jsonPath}'))",
            'pgsql' => "{$column} #>> '{{$segments}}'",
            default => "json_extract({$column}, '{$jsonPath}')",
        };
    }

    public function validJson(string $column): string
    {
        return match ($this->driver()) {
            'sqlite' => "json_valid({$column})",
            default => "{$column} is not null",
        };
    }

    public function searchPosts(Builder $query, string $search): Builder
    {
        $query = match ($this->driver()) {
            'mysql', 'mariadb' => $query->whereRaw('match(posts.body) against (? in natural language mode)', [$search]),
            'pgsql' => $query->whereRaw("to_tsvector('simple', coalesce(posts.body, '')) @@ plainto_tsquery('simple', ?)", [$search]),
            'sqlite' => $query->whereIn('posts.id', fn ($fts) => $fts->select('rowid')->from('posts_fts')->whereRaw('posts_fts MATCH ?', ['"'.str_replace('"', '""', $search).'"'])),
            default => $query->where('posts.body', 'like', '%'.$search.'%'),
        };

        if (str_starts_with($search, '#')) {
            $tag = '%'.substr($search, 1).'%';
            $this->driver() === 'pgsql'
                ? $query->orWhereRaw('cast(posts.metadata as text) ilike ?', [$tag])
                : $query->orWhere('posts.metadata', 'like', $tag);
        }

        return $query;
    }
}
