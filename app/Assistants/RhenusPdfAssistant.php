<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;
use App\GeonamesCountry;

class RhenusPdfAssistant extends PdfClient
{
    public function processPath(string $filename): array
    {
        $lines = static::extractLocalPdfLines($filename);
        if (!static::validateFormat($lines)) {
            throw new \Exception("RhenusPdfAssistant: validateFormat returned false.");
        }
        $this->processLines($lines, basename($filename));
        return $this->getOutput();
    }

    public static function validateFormat(array $lines): bool
    {
        $full_text = implode("\n", $lines);
        return str_contains($full_text, 'Rhenus Logistics Ltd') && str_contains($full_text, 'Transport instruction');
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        // --- START DEBUG: Print lines with numbers ---
        echo "\n--- DEBUG: Lines passed to validateFormat() ---\n";
        foreach ($lines as $index => $line) {
            echo "[" . $index . "] " . $line . "\n";
        }
        echo "--- END DEBUG ---\n\n";
        // --- END DEBUG ---

        $customer = $this->extractCustomer($lines);

        $order_reference = $this->extractReference($lines) ?? "Not Mentioned";

        $price =  $this->extractFreightPrice($lines);

        $dateFrom = Carbon::now()->startOfDay()->toIso8601String();
        $dateTo = Carbon::now()->startOfDay()->toIso8601String();

        $loading_locations = $this->extractLoadingLocation($lines) ?? [
            [
                'company_address' => [
                    'company' => "Test String",
                    'street_address' => "Test String",
                    'city' => "Test String",
                    'postal_code' => "Test String",
                ],
                'time' => [
                    'datetime_from' => $dateFrom,
                    'datetime_to' => $dateTo,
                ]
            ]
        ];

        $destination_locations = $this->extractDestinationLocation($lines) ?? [
            [
                'company_address' => [
                    'company' => "Test String",
                    'street_address' => "Test String",
                    'city' => "Test String",
                    'postal_code' => "Test String",
                ],
                'time' => [
                    'datetime_from' => $dateFrom,
                    'datetime_to' => $dateTo,
                ]
            ]
        ];


        $cargo = [
            'weight' => 1.00,
            'title' => "test string",
            'package_count' => 1,
        ];

        $data = [
            'customer' => $customer,
            'order_reference' => $order_reference,
            'freight_price' => $price['amount'],
            'freight_currency' => $price['currency'],
            'loading_locations' => $loading_locations,
            'destination_locations' => $destination_locations,
            'cargos' => [$cargo],
            'attachment_filenames' => [mb_strtolower($attachment_filename ?? '')],
        ];

        $this->createOrder($data);
    }

    private function extractCustomer(array $lines): ?array
    {
        $customer = [
            'side' => 'none',
            'details' => [
                'company' => null,
                'street_address' => null,
                'city' => null,
                'postal_code' => null,
                'phone' => null,
                'fax' => null,
                'email' => null,
            ],
        ];

        // Customer section Before Carrier
        $endIdx = count($lines);
        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains(strtolower($lines[$i]), 'carrier:')) {
                $endIdx = $i;
                break;
            }
        }

        // Extract company name
        if (isset($lines[2]) && $endIdx > 2) {
            $customer['details']['company'] = trim($lines[2]);
        }

        // Extract street, Combining two street lines
        $streetParts = [];
        if (isset($lines[3]) && $endIdx > 3) {
            $streetLine1 = trim($lines[3]);
            if (!empty($streetLine1)) {
                $streetParts[] = $streetLine1;
            }
        }
        if (isset($lines[9]) && $endIdx > 9) {
            $streetLine2 = trim($lines[9]);
            if (!empty($streetLine2)) {
                $streetParts[] = $streetLine2;
            }
        }
        if (!empty($streetParts)) {
            $customer['details']['street_address'] = implode(', ', $streetParts);
        }

        // Extract city
        if (isset($lines[15]) && $endIdx > 15) {
            $city = trim($lines[15]);
            if (!empty($city)) {
                $customer['details']['city'] = $city;
            }
        }

        // Extract phone
        for ($i = 0; $i < $endIdx; $i++) {
            if (str_contains(strtolower($lines[$i]), 'phone:') && !str_contains(strtolower($lines[$i]), 'phone::')) {
                $nextIdx = $i + 1;
                while ($nextIdx < $endIdx && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < $endIdx) {
                    $value = trim($lines[$nextIdx]);
                    if (!empty($value) && !str_contains(strtolower($value), ':')) {
                        $customer['details']['phone'] = $value;
                    }
                }
                break;
            }
        }

        // Extract fax
        for ($i = 0; $i < $endIdx; $i++) {
            if (str_contains(strtolower($lines[$i]), 'fax:')) {
                $nextIdx = $i + 1;
                while ($nextIdx < $endIdx && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < $endIdx) {
                    $value = trim($lines[$nextIdx]);
                    if (!empty($value) && !str_contains(strtolower($value), ':')) {
                        $customer['details']['fax'] = $value;
                    }
                }
                break;
            }
        }

        // Extract email
        for ($i = 0; $i < $endIdx; $i++) {
            if (str_contains(strtolower($lines[$i]), 'email:')) {
                $nextIdx = $i + 1;
                while ($nextIdx < $endIdx && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < $endIdx) {
                    $value = trim($lines[$nextIdx]);
                    if (!empty($value) && str_contains(strtolower($value), '@')) {
                        $customer['details']['email'] = $value;
                    }
                }
                break;
            }
        }

        $customer['details'] = array_filter($customer['details'], function ($val) {
            return $val !== null;
        });

        return $customer;
    }

    private function extractReference(array $lines): ?string
    {
        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains(strtolower($lines[$i]), 'principal ref.')) {
                $nextIdx = $i + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $value = trim($lines[$nextIdx]);
                    if (!empty($value) && !str_contains(strtolower($value), 'request')) {
                        return $value;
                    }
                }
                break;
            }
        }

        return null;
    }

    private function extractFreightPrice(array $lines): ?array
    {
        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains(strtolower($lines[$i]), 'freight cost')) {
                $nextIdx = $i + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $value = trim($lines[$nextIdx]);
                    if (!empty($value)) {
                        $parts = explode(' ', $value);
                        if (count($parts) >= 2) {
                            $amount = uncomma($parts[0]);
                            $currency = $parts[count($parts) - 1];
                            return [
                                'amount' => $amount,
                                'currency' => $currency,
                            ];
                        }
                    }
                }
                break;
            }
        }

        return null;
    }

    private function extractCompanyAddress(array $lines, int $startIdx): ?array
    {
        $address = [
            'company' => null,
            'street_address' => null,
            'postal_code' => null,
            'city' => null,
            'country' => null,
        ];

        // Extract 4/5 lines starting from startIdx
        // Line 0: Company name
        // Line 1: Street address
        // Line 2/3: Postal code + City
        // Line 3/4: Country

        if (isset($lines[$startIdx])) {
            $address['company'] = trim($lines[$startIdx]);
        }

        if (isset($lines[$startIdx + 1])) {
            $address['street_address'] = trim($lines[$startIdx + 1]);
        }

        if (isset($lines[$startIdx + 2])) {
            $postalCity = trim($lines[$startIdx + 2]);
            $parts = explode(' ', $postalCity, 2);
            if (count($parts) == 2) {
                $address['postal_code'] = $parts[0];
                $address['city'] = $parts[1];
            } else {
                $address['city'] = $postalCity;
            }
        }

        if (isset($lines[$startIdx + 3])) {

            $countryName = trim($lines[$startIdx + 3]);
            $countryNameTitleCase = ucwords(strtolower($countryName));
            $iso = GeonamesCountry::getIso($countryNameTitleCase);

            if ($iso) {
                $address['country'] = $iso;
            } else {
                $address['country'] = "--"; //edge case
            }
        }


        return array_filter($address, function ($val) {
            return $val !== null;
        });
    }

    private function extractLoadingLocation(array $lines): ?array
    {
        $loadingLocations = [];

        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains(strtolower($lines[$i]), 'unload place')) {

                $nextIdx = $i + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }

                if ($nextIdx + 3 < count($lines)) {
                    $companyAddress = $this->extractCompanyAddress($lines, $nextIdx);

                    $loadingLocation = [
                        'company_address' => $companyAddress,
                    ];
                    $loadingLocations[] = $loadingLocation;
                }
            }
        }

        return !empty($loadingLocations) ? $loadingLocations : null;
    }
}
