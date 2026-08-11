<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Framework\Struct\Struct;

class FreeShippingStatus extends Struct
{
    public function __construct(
        public readonly float $threshold,
        public readonly float $remaining,
        public readonly bool $achieved,
        public readonly string $currencyIsoCode,
    ) {
    }

    public function getApiAlias(): string
    {
        return 'rc_free_shipping_status';
    }
}
