<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Stand des Warenkorbs gegenüber der Versandkostenfrei-Schwelle, beide Beträge schon in der
 * Währung des Besuchers. Hängt als Erweiterung an der Seite und wird von der Vorlage gelesen.
 */
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
