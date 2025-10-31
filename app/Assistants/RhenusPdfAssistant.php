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

        /*
        // --- START DEBUG: Print lines with numbers ---
        echo "\n--- DEBUG: Lines passed to validateFormat() ---\n";
        foreach ($lines as $index => $line) {
            echo "[" . $index . "] " . $line . "\n";
        }
        echo "--- END DEBUG ---\n\n";
        // --- END DEBUG ---
        */

        $customer = $this->extractCustomer($lines);

        $order_reference = $this->extractReference($lines) ?? "Not Mentioned";

        $price =  $this->extractFreightPrice($lines);

        $transport_numbers = $this->extractTransportNumbers($lines);

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


        $cargos = $this->extractCargos($lines) ?? [
            [
                'weight' => 1.00,
                'title' => "test string",
                'package_count' => 1,
            ]
        ];

        $data = [
            'customer' => $customer,
            'order_reference' => $order_reference,
            'freight_price' => $price['amount'],
            'freight_currency' => $price['currency'],
            'transport_numbers' => $transport_numbers,
            'loading_locations' => $loading_locations,
            'destination_locations' => $destination_locations,
            'cargos' => $cargos,
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

    private function extractTransportNumbers(array $lines): ?string
    {
        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains(strtolower($lines[$i]), 'transport no')) {
                $nextIdx = $i + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }

                if ($nextIdx < count($lines)) {
                    $candidateLine = trim($lines[$nextIdx]);

                    if (!str_contains($candidateLine, ':')) {
                        return $candidateLine;
                    }
                }
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
        // Line 1/2: Street address
        // Line 2/3: Postal code + City
        // Line 3/4: Country

        // Extract company name (line 0)
        if (isset($lines[$startIdx])) {
            $address['company'] = trim($lines[$startIdx]);
        }

        // Extract postal code + city (line 2)
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

        // Check if line 3 is a valid country
        $line3Country = null;
        if (isset($lines[$startIdx + 3])) {
            $line3Name = trim($lines[$startIdx + 3]);
            $line3TitleCase = ucwords(strtolower($line3Name));
            $line3Iso = GeonamesCountry::getIso($line3TitleCase);
            if ($line3Iso) {
                $line3Country = $line3Iso;
            }
        }

        // If line 3 is a valid country, use standard 4-line format
        if ($line3Country) {
            // Line 1 is street address
            if (isset($lines[$startIdx + 1])) {
                $address['street_address'] = trim($lines[$startIdx + 1]);
            }
            $address['country'] = $line3Country;
        } else {
            // Line 3 is not a valid country, check line 4
            $line4Country = null;
            if (isset($lines[$startIdx + 4])) {
                $line4Name = trim($lines[$startIdx + 4]);
                $line4TitleCase = ucwords(strtolower($line4Name));
                $line4Iso = GeonamesCountry::getIso($line4TitleCase);
                if ($line4Iso) {
                    $line4Country = $line4Iso;
                }
            }

            // If line 4 is a valid country, use 5-line format
            if ($line4Country) {
                // Lines 1 and 3 are street addresses
                $streetLines = [];
                if (isset($lines[$startIdx + 1])) {
                    $streetLines[] = trim($lines[$startIdx + 1]);
                }
                if (isset($lines[$startIdx + 3])) {
                    $streetLines[] = trim($lines[$startIdx + 3]);
                }
                if (!empty($streetLines)) {
                    $address['street_address'] = implode(', ', $streetLines);
                }
                $address['country'] = $line4Country;
            } else {
                // Neither line 3 nor line 4 is valid country, use edge case
                if (isset($lines[$startIdx + 1])) {
                    $address['street_address'] = trim($lines[$startIdx + 1]);
                }
                $address['country'] = "--";
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
                    $shipmentTimes = $this->getShipmentTimes($lines, $i);

                    $loadingLocation = [
                        'company_address' => $companyAddress,
                    ];

                    if ($shipmentTimes && ($shipmentTimes['pickup_datetime_from'] || $shipmentTimes['pickup_datetime_to'])) {
                        $loadingLocation['time'] = [
                            'datetime_from' => $shipmentTimes['pickup_datetime_from'],
                            'datetime_to' => $shipmentTimes['pickup_datetime_to'],
                        ];
                    }

                    $loadingLocations[] = $loadingLocation;
                }
            }
        }

        return !empty($loadingLocations) ? $loadingLocations : null;
    }

    private function extractDestinationLocation(array $lines): ?array
    {
        $destinationLocations = [];

        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains(strtolower($lines[$i]), 'sender ref.')) {

                $nextIdx = $i + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }

                if ($nextIdx + 3 < count($lines)) {
                    $companyAddress = $this->extractCompanyAddress($lines, $nextIdx);
                    $shipmentTimes = $this->getShipmentTimes($lines, $i);

                    $destinationLocation = [
                        'company_address' => $companyAddress,
                    ];

                    if ($shipmentTimes && ($shipmentTimes['delivery_datetime_from'] || $shipmentTimes['delivery_datetime_to'])) {
                        $destinationLocation['time'] = [
                            'datetime_from' => $shipmentTimes['delivery_datetime_from'],
                            'datetime_to' => $shipmentTimes['delivery_datetime_to'],
                        ];
                    }

                    $destinationLocations[] = $destinationLocation;
                }
            }
        }

        return !empty($destinationLocations) ? $destinationLocations : null;
    }

    private function getShipmentTimes(array $lines, int $startSearchIdx): ?array
    {
        $pickupDateStr = null;
        $pickupTimeStr = null;
        $deliveryDateStr = null;
        $deliveryTimeStr = null;

        $pickupIdx = null;
        for ($i = $startSearchIdx; $i < count($lines) && $i < $startSearchIdx + 50; $i++) {
            if (str_contains(strtolower($lines[$i]), 'pickup date')) {
                $pickupIdx = $i;
                break;
            }
        }

        if ($pickupIdx === null) return null;

        $requestedIdx = null;
        for ($i = $pickupIdx + 1; $i < count($lines) && $i < $pickupIdx + 10; $i++) {
            if (str_contains(strtolower($lines[$i]), 'requested')) {
                $requestedIdx = $i;
                break;
            }
        }

        if ($requestedIdx === null) return null;

        $nextIdx = $requestedIdx + 1;
        while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
            $nextIdx++;
        }

        if ($nextIdx >= count($lines)) return null;

        $pickupDateStr = trim($lines[$nextIdx]);
        $nextIdx++;
        while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
            $nextIdx++;
        }

        if ($nextIdx < count($lines)) {
            $nextLine = trim($lines[$nextIdx]);

            if (str_contains($nextLine, ':') || (str_contains($nextLine, '-') && !str_contains($nextLine, 'Sep') && !str_contains($nextLine, 'Oct'))) {
                $pickupTimeStr = $nextLine;
                $nextIdx++;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $deliveryDateStr = trim($lines[$nextIdx]);
                }
            } else {
                $deliveryDateStr = $nextLine;
            }
        }

        if (!$deliveryDateStr) {
            $latestIdx = null;
            for ($i = $pickupIdx + 1; $i < count($lines) && $i < $pickupIdx + 30; $i++) {
                if (str_contains(strtolower($lines[$i]), 'latest')) {
                    $latestIdx = $i;
                    break;
                }
            }

            if ($latestIdx !== null) {
                $nextIdx = $latestIdx + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $deliveryDateStr = trim($lines[$nextIdx]);
                    $nextIdx++;
                    while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                        $nextIdx++;
                    }
                    if ($nextIdx < count($lines)) {
                        $nextLine = trim($lines[$nextIdx]);
                        if (str_contains($nextLine, ':') || (str_contains($nextLine, '-') && !str_contains($nextLine, 'Sep') && !str_contains($nextLine, 'Oct'))) {
                            $deliveryTimeStr = $nextLine;
                        }
                    }
                }
            }
        }

        $pickupDateTime = $this->parseDateTime($pickupDateStr, $pickupTimeStr);
        $deliveryDateTime = $this->parseDateTime($deliveryDateStr, $deliveryTimeStr);

        return [
            'pickup_datetime_from' => $pickupDateTime,
            'pickup_datetime_to' => $pickupDateTime,
            'delivery_datetime_from' => $deliveryDateTime,
            'delivery_datetime_to' => $deliveryDateTime,
        ];
    }

    private function parseDateTime(?string $dateStr, ?string $timeStr): ?string
    {
        if (!$dateStr) return null;

        try {
            $date = Carbon::createFromFormat('d-M-Y', $dateStr);

            if ($timeStr) {
                if (str_contains($timeStr, '-')) {
                    $timeParts = explode('-', $timeStr);
                    $timeStr = trim($timeParts[0]);
                }
                $timeOnly = Carbon::createFromFormat('H:i', trim($timeStr));
                $date->setTime($timeOnly->hour, $timeOnly->minute);
            } else {
                $date->setTime(0, 0, 0);
            }

            return $date->toIso8601String();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractCargos(array $lines): ?array
    {
        $cargos = [];
        $shipmentIndices = [];

        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains(strtolower($lines[$i]), 'shipment no:')) {
                $nextIdx = $i + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $shipmentNumber = trim($lines[$nextIdx]);
                    $shipmentIndices[] = [
                        'label_idx' => $i,
                        'number' => $shipmentNumber,
                    ];
                }
            }
        }

        if (empty($shipmentIndices)) return null;

        foreach ($shipmentIndices as $shipmentInfo) {
            $startIdx = $shipmentInfo['label_idx'];
            $cargo = [
                'number' => $shipmentInfo['number'],
            ];

            $quantityLabelIdx = $this->findLabelIndex($lines, 'total quantity:', $startIdx + 1);
            if ($quantityLabelIdx === null) {
                continue;
            }

            $descLabelIdx = $this->findLabelIndex($lines, 'goods description', $quantityLabelIdx);
            $weightLabelIdx = $descLabelIdx ? $this->findLabelIndex($lines, 'total gross weight', $descLabelIdx) : null;
            $volumeLabelIdx = $weightLabelIdx ? $this->findLabelIndex($lines, 'total volume', $weightLabelIdx) : null;
            $ldmLabelIdx = $volumeLabelIdx ? $this->findLabelIndex($lines, 'total ldm', $volumeLabelIdx) : null;

            $valuesStartIdx = $ldmLabelIdx ? $ldmLabelIdx + 1 : $quantityLabelIdx + 1;
            while ($valuesStartIdx < count($lines) && trim($lines[$valuesStartIdx]) === '') {
                $valuesStartIdx++;
            }

            $values = [];
            $valueIdx = $valuesStartIdx;
            $valueCount = 0;

            while ($valueIdx < count($lines) && $valueCount < 6) {
                $currentLine = trim($lines[$valueIdx]);
                if ($currentLine !== '') {
                    $values[$valueCount] = $currentLine;
                    $valueCount++;
                }
                $valueIdx++;
            }

            if (isset($values[0]) && is_numeric($values[0])) {
                $cargo['package_count'] = (int)$values[0];
            }

            if (isset($values[1])) {
                $cargo['title'] = $values[1];
            }

            if (isset($values[2])) {
                $weight = (float)uncomma($values[2]);
                if ($weight > 0) {
                    $cargo['weight'] = $weight;
                }
            }

            if (isset($values[3])) {
                $volume = (float)uncomma($values[3]);
                if ($volume > 0) {
                    $cargo['volume'] = $volume;
                }
            }

            if (isset($values[4])) {
                $ldm = (float)uncomma($values[4]);
                if ($ldm > 0) {
                    $cargo['ldm'] = $ldm;
                }
            }

            if (isset($values[5])) {
                $pkgTypeStr = strtolower($values[5]);
                if ($pkgTypeStr === 'piece') {
                    $cargo['package_type'] = 'EPAL';
                } elseif ($pkgTypeStr === 'units') {
                    $cargo['package_type'] = 'other';
                }
            }

            $lengthIdx = $this->findLabelIndex($lines, 'length [cm]', $startIdx);
            if ($lengthIdx !== null) {
                $nextIdx = $lengthIdx + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $lengthStr = trim($lines[$nextIdx]);
                    if (is_numeric($lengthStr)) {
                        $cargo['pkg_length'] = (float)$lengthStr / 100;
                    }
                }
            }

            $widthIdx = $this->findLabelIndex($lines, 'width [cm]', $startIdx);
            if ($widthIdx !== null) {
                $nextIdx = $widthIdx + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $widthStr = trim($lines[$nextIdx]);
                    if (is_numeric($widthStr)) {
                        $cargo['pkg_width'] = (float)$widthStr / 100;
                    }
                }
            }

            $heightIdx = $this->findLabelIndex($lines, 'height [cm]', $startIdx);
            if ($heightIdx !== null) {
                $nextIdx = $heightIdx + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                if ($nextIdx < count($lines)) {
                    $heightStr = trim($lines[$nextIdx]);
                    if (is_numeric($heightStr)) {
                        $cargo['pkg_height'] = (float)$heightStr / 100;
                    }
                }
            }

            $cargo = array_filter($cargo, function ($val) {
                return $val !== null;
            });

            if (!empty($cargo)) {
                $cargos[] = $cargo;
            }
        }

        return !empty($cargos) ? $cargos : null;
    }

    private function findLabelIndex(array $lines, string $label, int $startIdx, int $maxRange = 150): ?int
    {
        $endIdx = min($startIdx + $maxRange, count($lines));
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $lineContent = strtolower(trim($lines[$i]));
            $labelLower = strtolower($label);

            if ($labelLower === 'total quantity:') {
                if (str_starts_with($lineContent, 'total quantity')) {
                    return $i;
                }
            } elseif ($labelLower === 'goods description') {
                if ($lineContent === 'goods description') {
                    return $i;
                }
            } elseif ($labelLower === 'total gross weight') {
                if (str_contains($lineContent, 'total gross weight')) {
                    return $i;
                }
            } elseif ($labelLower === 'total volume') {
                if (str_contains($lineContent, 'total volume')) {
                    return $i;
                }
            } elseif ($labelLower === 'total ldm') {
                if (str_contains($lineContent, 'total ldm') || str_contains($lineContent, 'total ldm')) {
                    return $i;
                }
            } elseif ($labelLower === 'piece') {
                if (trim($lineContent) === 'piece') {
                    return $i;
                }
            } elseif ($labelLower === 'units') {
                if (trim($lineContent) === 'units') {
                    return $i;
                }
            } elseif ($labelLower === 'length [cm]') {
                if (str_contains($lineContent, 'length') && str_contains($lineContent, 'cm')) {
                    return $i;
                }
            } elseif ($labelLower === 'width [cm]') {
                if (str_contains($lineContent, 'width') && str_contains($lineContent, 'cm')) {
                    return $i;
                }
            } elseif ($labelLower === 'height [cm]') {
                if (str_contains($lineContent, 'height') && str_contains($lineContent, 'cm')) {
                    return $i;
                }
            } else {
                // Generic fallback
                if (str_contains($lineContent, $labelLower)) {
                    return $i;
                }
            }
        }
        return null;
    }
}
