<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SecretMask;
use PHPUnit\Framework\TestCase;

final class SecretMaskTest extends TestCase
{
    public function test_it_masks_all_but_the_last_four_characters(): void
    {
        $this->assertSame('••••3456', SecretMask::tail4('sk-0123456'));
        $this->assertSame('••••cret', SecretMask::tail4('smtp-secret'));
    }

    public function test_short_or_empty_secrets_are_fully_masked_or_null(): void
    {
        $this->assertSame('••••', SecretMask::tail4('abc'));
        $this->assertSame('••••', SecretMask::tail4('1234'));
        $this->assertNull(SecretMask::tail4(null));
        $this->assertNull(SecretMask::tail4(''));
    }
}
