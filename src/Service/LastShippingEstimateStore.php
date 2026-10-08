<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Struct\LastShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bewahrt die zuletzt abgefragte Versandkosten-Auskunft in der Sitzung auf, damit die
 * Warenkorb-Seitenleiste sie zeigen kann, ohne sie erneut zu erfragen.
 *
 * In der Sitzung und nicht in einem eigenen Cookie: Shopware führt für den Warenkorb
 * ohnehin eine, damit entsteht kein zusätzlicher Einwilligungsfall nach § 25 TDDDG.
 *
 * Gespeichert wird nur die günstigste Lieferung. Die Leiste hat Platz für eine Zeile; die
 * vollständige Liste steht auf der Warenkorb-Seite, wo sie hingehört. Eine Abholung ist keine
 * Lieferung, mit 0,00 € gewänne sie sonst immer und die Leiste meldete „Versand … 0,00 €".
 *
 * Nicht `final`, weil die Tests der Leiste und des Rechner-Controllers ihn als Test-Double
 * ersetzen; eine Schnittstelle nur dafür wäre mehr Bauwerk als Nutzen.
 */
class LastShippingEstimateStore
{
    private const SESSION_KEY = 'rcCheckoutLastShippingEstimate';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param list<string> $excludedMethodIds Abholung und Platzhalter: kein Versand, auch wenn sie
     *                                        mit 0,00 € die günstigste Zeile wären
     */
    public function remember(ShippingEstimateResult $result, string $cartFingerprint, array $excludedMethodIds = []): void
    {
        $session = $this->requestStack->getSession();

        // Nur eine gelungene Auskunft ist eine Auskunft. „Kein Versand in dieses Land"
        // und „die Berechnung ist gescheitert" gehören nicht in die Leiste: Das eine
        // wäre eine Absage ohne Zusammenhang, das andere eine Entschuldigung an einer
        // Stelle, an der niemand danach gefragt hat.
        $deliveries = array_values(array_filter(
            $result->estimates,
            static fn (ShippingEstimate $estimate): bool => !\in_array($estimate->shippingMethodId, $excludedMethodIds, true),
        ));

        if (!$result->isSuccessful() || $deliveries === []) {
            $session->remove(self::SESSION_KEY);

            return;
        }

        $cheapest = $deliveries[0];
        foreach ($deliveries as $estimate) {
            if ($estimate->price < $cheapest->price) {
                $cheapest = $estimate;
            }
        }

        $session->set(self::SESSION_KEY, (new LastShippingEstimate(
            $result->countryIso,
            $result->zipCode,
            $cheapest->name,
            $cheapest->price,
            $cheapest->currencyIsoCode,
            $cartFingerprint,
        ))->toArray());
    }

    public function get(): ?LastShippingEstimate
    {
        $session = $this->requestStack->getSession();
        $data = $session->get(self::SESSION_KEY);

        if (!\is_array($data)) {
            return null;
        }

        return LastShippingEstimate::fromArray($data);
    }
}
