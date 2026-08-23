<?php

namespace App\Services\Import;

trait RepairsMetaEncoding
{
    private function repairMetaEncoding(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->repairMetaEncoding($item), $value);
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        $repaired = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);

        return $repaired !== false && mb_check_encoding($repaired, 'UTF-8') ? $repaired : $value;
    }
}
