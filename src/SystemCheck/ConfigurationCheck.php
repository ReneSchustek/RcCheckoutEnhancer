<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\SystemCheck;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\SystemCheck\BaseCheck;
use Shopware\Core\Framework\SystemCheck\Check\Category;
use Shopware\Core\Framework\SystemCheck\Check\Result;
use Shopware\Core\Framework\SystemCheck\Check\Status;
use Shopware\Core\Framework\SystemCheck\Check\SystemCheckExecutionContext;

/**
 * Meldet im Shopware-Systemstatus, wenn eine Funktion der Erweiterung mangels Einstellung ruht.
 *
 * Drei Felder verweisen auf Datensätze des jeweiligen Shops und haben deshalb keinen Vorgabewert:
 * die Seite mit dem Kontaktformular, die Versandarten, die keine Lieferung sind, und die
 * versandkostenfreien Versandarten. Fehlt einer der ersten beiden, schweigt der Anfrageweg ganz
 * oder im Fall „nur Abholung"; das ist gewollt, blieb aber bisher unbemerkt. Fehlt der dritte,
 * erscheint der Versandkostenfrei-Hinweis überall statt nur dort, wo er gilt; das ist vertretbar,
 * nur ungenau, und bleibt deshalb ein Hinweis ohne Warnung.
 *
 * Sichtbar über `bin/console system:check`, die Schnittstelle `/api/_info/system-health-check`
 * und vor einem Rollout (Kontext `pre_rollout`). Geprüft wird je aktivem Storefront-Kanal, weil
 * jede Einstellung je Kanal überschreibbar ist.
 */
class ConfigurationCheck extends BaseCheck
{
    public const NAME = 'RcCheckoutEnhancerConfiguration';

    public function __construct(
        private readonly ConfigService $configService,
        private readonly Connection $connection,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function category(): Category
    {
        return Category::FEATURE;
    }

    public function run(): Result
    {
        $warnings = [];
        $notices = [];

        foreach ($this->storefrontSalesChannels() as $salesChannelId => $name) {
            if ($this->configService->isShippingEnquiryEnabled($salesChannelId)) {
                if ($this->configService->getShippingEnquiryCategoryId($salesChannelId) === null) {
                    $warnings[] = \sprintf('%s: Anfrageweg ruht, keine Seite mit Kontaktformular gewählt', $name);
                }

                if ($this->configService->getNonDeliveryMethodIds($salesChannelId) === []) {
                    $warnings[] = \sprintf('%s: keine Versandart als „keine Lieferung" eingetragen; Anfrageweg bei „nur Abholung", Abhol-Hinweis und Vorauswahl erkennen die Abholung nicht', $name);
                }
            }

            if ($this->configService->isFreeShippingIndicatorEnabled($salesChannelId)
                && $this->configService->getFreeShippingMethodIds($salesChannelId) === []) {
                $notices[] = \sprintf('%s: keine versandkostenfreien Versandarten gewählt; der Versandkostenfrei-Hinweis erscheint für jedes Lieferland', $name);
            }
        }

        $status = $warnings === [] ? Status::OK : Status::WARNING;
        $message = match (true) {
            $warnings !== [] => implode('; ', $warnings),
            $notices !== [] => 'Eingerichtet, mit Hinweisen: ' . implode('; ', $notices),
            default => 'Alle Verweise der Checkout-Erweiterung sind eingerichtet',
        };

        // Der Shop läuft in jedem Fall; eine ruhende Funktion ist kein Ausfall.
        return new Result($this->name(), $status, $message, true, ['warnings' => $warnings, 'notices' => $notices]);
    }

    protected function allowedSystemCheckExecutionContexts(): array
    {
        return SystemCheckExecutionContext::cases();
    }

    /**
     * @return array<string, string> Kennung => Name der aktiven Storefront-Kanäle
     */
    private function storefrontSalesChannels(): array
    {
        /** @var array<string, string> $channels */
        $channels = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(sc.id)), COALESCE(sct.name, LOWER(HEX(sc.id)))
             FROM sales_channel sc
             LEFT JOIN sales_channel_translation sct
                ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id
             WHERE sc.active = 1 AND sc.type_id = :storefront',
            ['storefront' => hex2bin(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)],
        );

        return $channels;
    }
}
