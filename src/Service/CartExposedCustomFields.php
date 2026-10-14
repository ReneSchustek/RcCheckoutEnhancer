<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Die Zusatzfelder, die im Warenkorb sichtbar sein dürfen (Haken „Im Warenkorb verfügbar").
 *
 * Shopware legt die Zusatzfelder eines Produkts vollständig in die Position und entfernt die nicht
 * freigegebenen erst beim Speichern des Warenkorbs. Wird er in derselben Anfrage neu berechnet,
 * stehen sie noch darin. Die Anfrage an den Vertrieb übernimmt deshalb nur die freigegebenen; sonst
 * landeten interne Felder mit technischem Schlüssel im vorbelegten Kontaktformular, das der Kunde
 * sieht.
 */
class CartExposedCustomFields implements ResetInterface
{
    /**
     * @var list<string>|null
     */
    private ?array $names = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Zwischen zwei Anfragen geleert (Tag `kernel.reset`), damit ein neu freigegebenes Feld auch in
     * einem langlebigen Prozess ankommt.
     */
    public function reset(): void
    {
        $this->names = null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        if ($this->names === null) {
            /** @var list<string> $names */
            $names = $this->connection->fetchFirstColumn('SELECT `name` FROM `custom_field` WHERE `allow_cart_expose` = 1');
            $this->names = $names;
        }

        return $this->names;
    }
}
