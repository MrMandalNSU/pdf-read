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
        echo "I am here in Rhenus Parser";

        //Temporary Data blocks to verify the JSON format

        $customer = [
            'side' => 'none',
            'details' => [
                'company' => 'Rhenus Logistics Ltd',
            ],
        ];

        $order_reference = "1234";

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
}
