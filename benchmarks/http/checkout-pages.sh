#!/bin/bash
# Antwortzeiten der Seiten, an denen die Erweiterung mitrechnet: je 12 warme Aufrufe, Median in
# Millisekunden. Läuft auf der DevBox gegen eine ddev-Instanz und legt dabei einen Messgast mit
# Anschrift in 96450 Coburg an (Mailadresse messung-…@example.test); dessen Konto nutzen auch die
# PHPBench-Messungen.
#
# Ausgabe je Seite eine Zeile: Seite;Median_ms;HTTP-Status
# Aufruf: checkout-pages.sh <instanz>
set -u
INSTANCE=${1:-live-clone}
RUNS=12
BASE=https://$INSTANCE.ddev.site
JAR=$(mktemp)
PRODUCT=0da2af7608904acabf787c434e7e8408
trap 'rm -f "$JAR"' EXIT

cd "/workspace/shopware/instances/$INSTANCE" || exit 1
SALUTATION=$(ddev mysql -N -e "SELECT LOWER(HEX(id)) FROM salutation ORDER BY salutation_key LIMIT 1" 2>/dev/null)
COUNTRY=$(ddev mysql -N -e "SELECT LOWER(HEX(id)) FROM country WHERE iso='DE' LIMIT 1" 2>/dev/null)

curl -skL -c "$JAR" -b "$JAR" "$BASE/" -o /dev/null
curl -skL -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/checkout/line-item/add" \
  --data-urlencode "lineItems[$PRODUCT][id]=$PRODUCT" --data-urlencode "lineItems[$PRODUCT][type]=product" \
  --data-urlencode "lineItems[$PRODUCT][referencedId]=$PRODUCT" --data-urlencode "lineItems[$PRODUCT][quantity]=1"
curl -sk -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/account/register" \
  --data-urlencode "guest=1" --data-urlencode "redirectTo=frontend.checkout.confirm.page" --data-urlencode "salutationId=$SALUTATION" \
  --data-urlencode "firstName=Probe" --data-urlencode "lastName=Messung" --data-urlencode "email=messung-$(date +%s%N)@example.test" \
  --data-urlencode "accountType=private" --data-urlencode "billingAddress[street]=Teststraße" --data-urlencode "billingAddress[streetNumber]=1" \
  --data-urlencode "billingAddress[zipcode]=96450" --data-urlencode "billingAddress[city]=Coburg" \
  --data-urlencode "billingAddress[countryId]=$COUNTRY" --data-urlencode "billingAddress[phoneNumber]=0201123456"

median_ms() { sort -n | awk '{v[NR]=$1} END {printf "%d", (v[int((NR+1)/2)] + v[int((NR+2)/2)]) / 2 * 1000}'; }

measure() {
  local page=$1; shift
  curl -sk -c "$JAR" -b "$JAR" -o /dev/null "$@"
  local samples
  samples=$(for _ in $(seq $RUNS); do curl -sk -c "$JAR" -b "$JAR" -o /dev/null -w "%{time_total} %{http_code}\n" "$@"; done)
  printf "%s;%s;%s\n" "$page" "$(echo "$samples" | awk '{print $1}' | median_ms)" "$(echo "$samples" | awk '{print $2}' | sort -u | paste -sd/)"
}

measure Warenkorb "$BASE/checkout/cart"
measure Warenkorb-Leiste "$BASE/checkout/offcanvas"
measure Bestätigung "$BASE/checkout/confirm"
measure Produktseite "$BASE/detail/$PRODUCT"
measure Speditionshinweis -H "X-Requested-With: XMLHttpRequest" "$BASE/rc-checkout/freight-hint/$PRODUCT"
