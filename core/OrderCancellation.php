<?php
declare(strict_types=1);

require_once __DIR__ . '/Language.php';

/**
 * Standard Order Cancellation Definitions & Utilities
 * Central source of truth for POS, Back-office orders, and Financial Reports.
 */
class OrderCancellation {
    public const REASONS = [
        'customer_changed_mind' => [
            'code' => 'customer_changed_mind',
            'en'   => 'Customer changed mind',
            'km'   => 'អតិថិជនប្តូរចិត្ត',
        ],
        'wrong_service_product' => [
            'code' => 'wrong_service_product',
            'en'   => 'Wrong service / product selected',
            'km'   => 'ជ្រើសរើសសេវាកម្ម ឬទំនិញខុស',
        ],
        'wrong_vehicle_plate' => [
            'code' => 'wrong_vehicle_plate',
            'en'   => 'Wrong vehicle / plate number',
            'km'   => 'បញ្ចូលស្លាកលេខ ឬរថយន្តខុស',
        ],
        'left_queue_wait_time' => [
            'code' => 'left_queue_wait_time',
            'en'   => 'Left queue / Wait time too long',
            'km'   => 'រង់ចាំយូរពេក / ចាកចេញពីជួរ',
        ],
        'duplicate_order' => [
            'code' => 'duplicate_order',
            'en'   => 'Duplicate order created',
            'km'   => 'បានបង្កើតការកុម្ម៉ង់ស្ទួន',
        ],
        'vehicle_condition_issue' => [
            'code' => 'vehicle_condition_issue',
            'en'   => 'Vehicle condition issue / Cannot service',
            'km'   => 'រថយន្តមានបញ្ហា / មិនអាចផ្តល់សេវាបាន',
        ],
        'payment_failed' => [
            'code' => 'payment_failed',
            'en'   => 'Payment failed / Card or app declined',
            'km'   => 'ការទូទាត់បរាជ័យ / ធនាគារឬកាតមិនដំណើរការ',
        ],
        'other' => [
            'code' => 'other',
            'en'   => 'Other (typed note required)',
            'km'   => 'ផ្សេងៗ (តម្រូវឱ្យវាយបញ្ចូលមូលហេតុ)',
        ],
    ];

    /**
     * Get list of standard cancellation reasons localized to current or specified language.
     */
    public static function getReasons(?string $lang = null): array {
        $lang = $lang ?: Language::current();
        $list = [];
        foreach (self::REASONS as $code => $r) {
            $list[$code] = [
                'code'  => $code,
                'label' => ($lang === 'km') ? $r['km'] : $r['en'],
                'en'    => $r['en'],
                'km'    => $r['km'],
                'is_other' => ($code === 'other')
            ];
        }
        return $list;
    }

    /**
     * Get label for a given reason code.
     */
    public static function getReasonLabel(?string $code, ?string $lang = null): string {
        $lang = $lang ?: Language::current();
        if ($code && isset(self::REASONS[$code])) {
            return ($lang === 'km') ? self::REASONS[$code]['km'] : self::REASONS[$code]['en'];
        }
        return ($lang === 'km') ? 'ផ្សេងៗ (ចាស់)' : 'Other (old)';
    }

    /**
     * Format cancellation reason for saving into orders notes / cancel_reason.
     */
    public static function formatReason(string $code, string $note = '', ?string $lang = null): string {
        $lang = $lang ?: Language::current();
        $code = trim($code);
        $note = trim($note);

        if ($code === 'other') {
            $otherLabel = ($lang === 'km') ? 'ផ្សេងៗ' : 'Other';
            return $note !== '' ? "{$otherLabel}: {$note}" : $otherLabel;
        }

        if (isset(self::REASONS[$code])) {
            $label = ($lang === 'km') ? self::REASONS[$code]['km'] : self::REASONS[$code]['en'];
            return $note !== '' ? "{$label} ({$note})" : $label;
        }

        // Legacy or raw free-text
        return $note !== '' ? $note : (($lang === 'km') ? 'ផ្សេងៗ (ចាស់)' : 'Other (old)');
    }

    /**
     * Categorize a cancelled order for report grouping.
     * Legacy records without a valid cancel_reason_code are grouped under 'other_old'.
     */
    public static function categorizeForReport(?string $code, ?string $rawReason = null, ?string $lang = null): array {
        $lang = $lang ?: Language::current();
        $code = trim((string)$code);

        if ($code !== '' && isset(self::REASONS[$code])) {
            if ($code === 'other') {
                return [
                    'group_key'   => 'other',
                    'group_label' => ($lang === 'km') ? 'ផ្សេងៗ' : 'Other',
                    'detail'      => $rawReason ?: ''
                ];
            }
            return [
                'group_key'   => $code,
                'group_label' => ($lang === 'km') ? self::REASONS[$code]['km'] : self::REASONS[$code]['en'],
                'detail'      => $rawReason ?: ''
            ];
        }

        // Legacy / free-text orders
        return [
            'group_key'   => 'other_old',
            'group_label' => ($lang === 'km') ? 'ផ្សេងៗ (ចាស់)' : 'Other (old)',
            'detail'      => $rawReason ?: ''
        ];
    }
}
