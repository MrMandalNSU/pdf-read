<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

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

        $price = [
            'amount' => 100,
            'currency' => "USD",
        ];

        $dateFrom = Carbon::now()->startOfDay()->toIso8601String();
        $dateTo = Carbon::now()->startOfDay()->toIso8601String();

        $loading_location = [
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
        ];

        $destination_location = [
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
            'loading_locations' => [$loading_location],
            'destination_locations' => [$destination_location],
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
}
