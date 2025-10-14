<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    public function processPath(string $filename): array
    {
        $lines = static::extractLocalPdfLines($filename);
        if (!static::validateFormat($lines)) {
            throw new \Exception("TransalliancePdfAssistant: validateFormat returned false.");
        }
        $this->processLines($lines, basename($filename));
        return $this->getOutput();
    }

    public static function validateFormat(array $lines): bool
    {
        $full_text = implode("\n", $lines);
        return str_contains($full_text, 'TRANSALLIANCE TS LTD') && str_contains($full_text, 'CHARTERING CONFIRMATION');
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        $order_reference = $this->extractReference($text);
        $price = $this->extractPrice($text);

        $loading_index = array_find_key($lines, fn($l) => $l === 'Loading');
        $delivery_index = array_find_key($lines, fn($l) => $l === 'Delivery');
        $end_index = array_find_key($lines, fn($l) => Str::startsWith($l, 'CAUTION'));

        if ($loading_index === null || $delivery_index === null) {
            throw new \Exception('Could not find Loading or Delivery sections.');
        }

        $loading_block = array_slice($lines, $loading_index, $delivery_index - $loading_index);
        $delivery_block = array_slice($lines, $delivery_index, $end_index - $delivery_index);

        $loading_location = $this->parseLocationBlock($loading_block);
        $destination_location = $this->parseLocationBlock($delivery_block);

        $cargo = $this->extractCargo($text);

        $customer = [
            'side' => 'none',
            'details' => [
                'company' => 'TRANSALLIANCE TS LTD',
                'street_address' => 'SUITE 8/9 FARADAY COURT, CENTRUM ONE HUNDRED',
                'city' => 'BURTON UPON TRENT',
                'postal_code' => 'DE14 2WX',
                'country' => 'GB',
            ],
        ];

        $data = [
            'customer' => $customer,
            'order_reference' => $order_reference,
            'freight_price' => $price['amount'],
            'freight_currency' => $price['currency'],
            'loading_locations' => [$loading_location],
            'destination_locations' => [$destination_location],
            'cargos' => [$cargo],
            'attachment_filenames' => [mb_strtolower($attachment_filename ?? '')],
        ];

        $this->createOrder($data);
    }

    private function extractReference(string $text): ?string
    {
        if (preg_match('/REF\.:\s*(\S+)/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extractPrice(string $text): array
    {
        if (preg_match('/SHIPPING PRICE\s*([\d,]+\.\d{2})\s*([A-Z]{3})/s', $text, $matches)) {
            $price_string = str_replace(',', '.', str_replace('.', '', $matches[1]));
            return ['amount' => (float)$price_string, 'currency' => $matches[2]];
        }

        if (preg_match('/([\d,]+,\d{2})\s*EUR Ex-tax/', $text, $matches)) {
            $price_string = str_replace(',', '.', $matches[1]);
            return ['amount' => (float)$price_string, 'currency' => 'EUR'];
        }
        return ['amount' => null, 'currency' => null];
    }

    private function extractCargo(string $text): ?array
    {
        $cargo = [];
        if (preg_match('/Weight\s*:\s*([\d,.]+)/i', $text, $matches)) {
            $cargo['weight'] = (float)str_replace([',', '.'], ['', ''], $matches[1]);
        }
        if (preg_match('/M\. nature:\s*(.+)/i', $text, $matches)) {
            $cargo['title'] = trim($matches[1]);
        }

        if (empty($cargo)) return null;
        $cargo['package_count'] = 1;

        return $cargo;
    }

    private function parseLocationBlock(array $block): array
    {
        $text = implode("\n", $block);

        $date_str = $time_from = $time_to = null;
        if (preg_match('/ON:\s*(\d{2}\/\d{2}\/\d{2})/', $text, $date_match)) {
            $date_str = $date_match[1];
        }
        if (!$date_str && preg_match('/(\d{2}\/\d{2}\/\d{2})/', $text, $date_match)) {
            $date_str = $date_match[1];
        }

        if (preg_match('/(\d{1,2}h\d{2})\s*-\s*(\d{1,2}h\d{2})/', $text, $time_match)) {
            $time_from = str_replace('h', ':', $time_match[1]);
            $time_to = str_replace('h', ':', $time_match[2]);
        }

        $datetime_from = ($date_str && $time_from) ? Carbon::createFromFormat('d/m/y H:i', "$date_str $time_from")->toIso8601String() : Carbon::createFromFormat('d/m/y', "$date_str")->startOfDay()->toIso8601String();
        $datetime_to = ($date_str && $time_to) ? Carbon::createFromFormat('d/m/y H:i', "$date_str $time_to")->toIso8601String() : Carbon::createFromFormat('d/m/y', "$date_str")->endOfDay()->toIso8601String();

        $company = $street = $city = $postal_code = '';

        if (preg_match('/(ICONEX FRANCE|ICONEX|DP WORLD LONDON GATEWAY PORT|EP GROUP FRANCE)/', $text, $company_match)) {
            $company = $company_match[0];
        }
        if (preg_match('/(BAKEWELL RD|10 RTE DES INDUSTRIES|1 LONDON GATEWAY|CORRINGHAM|ZI DISTRIPORT|2 RUE DE TOKYO)/', $text, $street_match)) {
            $street = $street_match[0];
        }

        if (preg_match('/(GB-[A-Z\d]+\s\d[A-Z]{2}|-\d{5})\s+(.*)$/m', $text, $city_post_match)) {
            $raw_postal = $city_post_match[1];
            $postal_code = trim(str_replace(['GB-', '-'], '', $raw_postal));
            $city = trim($city_post_match[2]);
        }

        return [
            'company_address' => [
                'company' => $company,
                'street_address' => $street,
                'city' => $city,
                'postal_code' => $postal_code,
            ],
            'time' => [
                'datetime_from' => $datetime_from,
                'datetime_to' => $datetime_to,
            ]
        ];
    }
}
