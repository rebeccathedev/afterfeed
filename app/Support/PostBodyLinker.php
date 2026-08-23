<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class PostBodyLinker
{
    public static function format(?string $body): HtmlString
    {
        $body ??= '';
        preg_match_all('~https?://[^\s<>]+|(?<![\p{L}\p{N}_])#[\p{L}\p{N}_]+~iu', $body, $matches, PREG_OFFSET_CAPTURE);
        $html = '';
        $offset = 0;

        foreach ($matches[0] as [$match, $position]) {
            $html .= e(substr($body, $offset, $position - $offset));
            if (str_starts_with(strtolower($match), 'http')) {
                $url = rtrim($match, '.,!?;:');
                $trailing = substr($match, strlen($url));
                if (str_ends_with($url, ')') && substr_count($url, ')') > substr_count($url, '(')) {
                    $url = substr($url, 0, -1);
                    $trailing = ')'.$trailing;
                }
                $html .= '<a class="post-body-link" href="'.e($url).'" target="_blank" rel="noreferrer">'.e($url).'</a>'.e($trailing);
            } else {
                $html .= '<a class="post-hashtag" href="'.e(route('archives.index', ['q' => $match]).'#timeline').'">'.e($match).'</a>';
            }
            $offset = $position + strlen($match);
        }
        $html .= e(substr($body, $offset));

        return new HtmlString($html);
    }
}
