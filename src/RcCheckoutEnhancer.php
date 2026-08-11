<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RcCheckoutEnhancer extends Plugin
{
    /**
     * Zieht die eigene Paket-Konfiguration in den Container.
     *
     * `Bundle::build()` lädt sie nicht: `buildDefaultConfig()` ist zwar vorhanden,
     * wird aber nur von den Core-Bundles selbst aufgerufen (Framework, Storefront,
     * Administration, Profiling). Ein Plugin, das eine Datei unter
     * `Resources/config/packages/` ablegt, bekommt sie ohne diesen Aufruf nie zu
     * sehen — sie liegt still da und wirkt nicht. Hier hängt daran die
     * Rate-Limiter-Staffel des Versandkostenrechners; ohne sie bricht der Endpunkt
     * mit „Rate limiter factory not found" ab.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->buildDefaultConfig($container);
    }
}
