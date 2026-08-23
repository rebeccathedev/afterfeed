<?php

namespace Tests\Unit;

use App\Services\Import\RepairsMetaEncoding;
use PHPUnit\Framework\TestCase;

class RepairsMetaEncodingTest extends TestCase
{
    use RepairsMetaEncoding;

    public function test_it_repairs_facebook_mojibake_recursively_without_damaging_valid_text(): void
    {
        $value = [
            'post' => 'Itâs funny ð',
            'nested' => ['name' => 'Beyoncé'],
        ];

        $this->assertSame([
            'post' => 'It’s funny 😂',
            'nested' => ['name' => 'Beyoncé'],
        ], $this->repairMetaEncoding($value));
    }
}
