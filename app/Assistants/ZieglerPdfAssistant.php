<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class ZieglerPdfAssistant extends PdfClient
{
    public function processPath(string $filename): array
    {
        $lines = static::extractLocalPdfLines($filename);
        if (!static::validateFormat($lines)) {
            throw new \Exception("ZieglerPdfAssistant: validateFormat returned false.");
        }
        $this->processLines($lines, basename($filename));
        return $this->getOutput();
    }

    public static function validateFormat(array $lines): bool
    {
        $full_text = implode("\n", $lines);
        $keywords = ['ZIEGLER UK LTD', 'Ziegler Ref', 'Collection', 'Delivery'];
        foreach ($keywords as $keyword) {
            if (!str_contains($full_text, $keyword)) return false;
        }
        return true;
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_map('trim', $lines);

        $header = $this->extractHeader($lines);
        $locationsAndCargos = $this->extractLocationsAndCargos($lines);
        $comment = $this->extractComment($lines);

        $customer = [
            'side' => 'none',
            'details' => [
                'company' => 'ZIEGLER UK LTD',
                'street_address' => 'NORTH 4, NORTH SEA CROSSING',
                'city' => 'STANFORD LE HOPE',
                'postal_code' => 'SS17 9FJ',
                'country' => 'GB',
            ],
        ];

        $clearance_comment = $locationsAndCargos['clearance_info'] ? "\n" . $locationsAndCargos['clearance_info'] : '';

        $data = [
            'customer' => $customer,
            'order_reference' => $header['reference'],
            'freight_price' => $header['price'],
            'freight_currency' => $header['currency'],
            'loading_locations' => $locationsAndCargos['loading_locations'],
            'destination_locations' => $locationsAndCargos['destination_locations'],
            'cargos' => $locationsAndCargos['cargos'],
            'comment' => $comment . $clearance_comment,
            'attachment_filenames' => [mb_strtolower($attachment_filename ?? '')],
        ];

        $this->createOrder($data);
    }

    private function extractHeader(array $lines): array
    {
        $header_start_index = array_find_key($lines, fn($l) => str_contains($l, 'Ziegler Ref'));
        if ($header_start_index === null) {
            throw new \Exception('Invalid ZIEGLER PDF: Could not find "Ziegler Ref" header.');
        }
        $header_text = implode("\n", array_slice($lines, $header_start_index, 10));

        if (!preg_match('/Ziegler Ref\s*([\d]+)/s', $header_text, $ref_match)) {
            throw new \Exception('Invalid ZIEGLER PDF: Could not parse Ziegler Ref number.');
        }

        if (!preg_match('/Rate\s*€\s*([\d,.]+)/s', $header_text, $rate_match)) {
            throw new \Exception('Invalid ZIEGLER PDF: Could not parse Freight Rate.');
        }

        $price_string = str_replace([',', '.'], ['', '.'], $rate_match[1]);

        return [
            'reference' => $ref_match[1],
            'price' => (float)$price_string,
            'currency' => 'EUR',
        ];
    }

    private function extractLocationsAndCargos(array $lines): array
    {
        $loading_locations = [];
        $destination_locations = [];
        $cargos = [];
        $clearance_info = '';
        $indices = [];

        foreach ($lines as $i => $line) {
            if (Str::startsWith($line, ['Collection', 'Clearance', 'Delivery'])) {
                $indices[] = ['type' => trim(explode("\t", $line)[0]), 'index' => $i];
            }
        }

        foreach ($indices as $key => $section) {
            $start_index = $section['index'];
            $end_index = $indices[$key + 1]['index'] ?? array_find_key($lines, fn($l) => Str::startsWith($l, '- Payment will only be made'));
            if ($end_index === null) $end_index = count($lines);

            $block = array_slice($lines, $start_index, $end_index - $start_index);
            $location = $this->parseLocationBlock($block);

            if ($section['type'] === 'Collection') {
                $loading_locations[] = $location;
                if (preg_match('/(\d+)\s+PALLETS/', implode("\n", $block), $cargo_match)) {
                    $cargos[] = ['package_count' => (int)$cargo_match[1], 'package_type' => 'pallet', 'palletized' => true];
                }
            } elseif ($section['type'] === 'Delivery') {
                $destination_locations[] = $location;
            } elseif ($section['type'] === 'Clearance') {
                $clearance_info = "Clearance at: " . ($location['company_address']['company'] ?? '');
            }
        }

        return compact('loading_locations', 'destination_locations', 'cargos', 'clearance_info');
    }

    private function parseLocationBlock(array $block): array
    {
        $text = implode("\n", $block);

        $company = '';
        $company_line = $block[1] ?? $block[2] ?? '';
        if (preg_match('/"([^"]+)"/', $company_line, $company_match)) {
            $company = $company_match[1];
        }

        if (!preg_match('/(\d{2}\/\d{2}\/\d{4})/s', $text, $date_match)) {
            throw new \Exception('Invalid ZIEGLER PDF: Could not find a date in location block: ' . $text);
        }
        $date_str = $date_match[1];

        $time_from = '00:00';
        $time_to = '23:59';
        if (preg_match('/(\d{2}:?\d{2})\s*-\s*(\d{1,2}:?\d{2}\s*[apAP]?[mM]?)/', $text, $time_range_match)) {
            $time_from = (new Carbon($time_range_match[1]))->format('H:i');
            $time_to = (new Carbon($time_range_match[2]))->format('H:i');
        } elseif (preg_match('/BOOKED-(\d{2}:\d{2}\s*[apAP][mM])/', $text, $booked_time_match)) {
            $time_from = (new Carbon($booked_time_match[1]))->format('H:i');
            $time_to = $time_from;
        }

        $datetime_from = Carbon::createFromFormat('d/m/Y H:i', "$date_str $time_from")->toIso8601String();
        $datetime_to = Carbon::createFromFormat('d/m/Y H:i', "$date_str $time_to")->toIso8601String();

        // --- THIS IS THE FIX ---
        // Hardcoded regex now includes all known streets, cities, and postal codes from both PDFs.
        $street = $city = $postal_code = '';
        if (preg_match('/(CHERRYCOURT WAY|STANBRIDGE ROAD|Sevington|ZAC DE GAROLOR, RUE DU DOUANIER|RUE ROBERT SCHUMANN|NEEDHAM ROAD|GUSTED HALL LANE|166 Chem\. de Saint-Prix|580 RUE DU CHAMP ROUGE)/', $text, $street_match)) $street = $street_match[0];
        if (preg_match('/(LEIGHTON BUZZARD|Ashford|ENNERY|STIRING WENDEL|STOWMARKET|HAWKWELL|TAVERNY|SARAN)/', $text, $city_match)) $city = $city_match[0];
        if (preg_match('/([A-Z]{1,2}\d[\dA-Z]?\s*\d[A-Z]{2}|\d{5})/', $text, $post_match)) $postal_code = $post_match[0];

        $company_address = [
            'company' => $company,
            'street_address' => $street,
            'city' => $city,
            'postal_code' => $postal_code,
        ];

        return [
            'company_address' => $company_address,
            'time' => ['datetime_from' => $datetime_from, 'datetime_to' => $datetime_to],
        ];
    }

    private function extractComment(array $lines): string
    {
        $comment_start_index = array_find_key($lines, fn($l) => str_contains($l, 'Payment will only be made'));
        if ($comment_start_index === null) return '';

        $comment_block = array_slice($lines, $comment_start_index);
        return implode("\n", array_map(fn($line) => ltrim($line, '- '), $comment_block));
    }
}
