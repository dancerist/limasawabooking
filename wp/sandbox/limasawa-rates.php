<?php
/**
 * Limasawa Booking — flexible room/rate pricing fields.
 *
 * Adds three pricing SHAPES to the `accommodation` CPT, selected per-listing by
 * a `pricing_mode` field. Registered as a LOCAL ACF field group (code-managed,
 * git source of truth) so it merges with the DB-stored field group without
 * touching it. All 25 existing listings default to `flat` → unchanged.
 *
 *   flat     → existing accommodation_daily_rent / accommodation_monthly_rent
 *   rooms    → repeater `accommodation_room_types` ({room_name, room_capacity,
 *              room_rate, room_note}) + flat `additional_bed_rate`
 *   per_pax  → base_rate for `base_pax` guests, +`extra_rate` per extra guest,
 *              capped at `max_pax`
 *
 * Read side: limasawa-listings.php sb_rate_summary() returns a computed
 * `priceFrom` (cheapest nightly) so cards + price filtering keep working.
 * Write side: limasawa-submit.php maps the submit payload into these fields.
 */
if (!defined('ABSPATH')) exit;

add_action('acf/init', function () {
    if (!function_exists('acf_add_local_field_group')) return;

    acf_add_local_field_group([
        'key'      => 'group_limasawa_pricing',
        'title'    => 'Pricing (room types / per-pax)',
        'fields'   => [
            [
                'key'           => 'field_lima_pricing_mode',
                'label'         => 'Pricing mode',
                'name'          => 'pricing_mode',
                'type'          => 'select',
                'instructions'  => 'How this listing is priced. "Single rate" uses the Nightly/Monthly rate fields above.',
                'choices'       => [
                    'flat'    => 'Single rate (nightly / monthly)',
                    'rooms'   => 'Multiple room types',
                    'per_pax' => 'Per-pax tiered rate',
                ],
                'default_value' => 'flat',
                'allow_null'    => 0,
                'return_format' => 'value',
            ],

            // ── rooms ────────────────────────────────────────────────────
            [
                'key'               => 'field_lima_room_types',
                'label'             => 'Room types',
                'name'              => 'accommodation_room_types',
                'type'              => 'repeater',
                'instructions'      => 'One row per room type. The cheapest room becomes the card "from" price.',
                'button_label'      => 'Add room type',
                'layout'            => 'block',
                'conditional_logic' => [[['field' => 'field_lima_pricing_mode', 'operator' => '==', 'value' => 'rooms']]],
                'sub_fields'        => [
                    [
                        'key'   => 'field_lima_room_name',
                        'label' => 'Room name',
                        'name'  => 'room_name',
                        'type'  => 'text',
                    ],
                    [
                        'key'   => 'field_lima_room_capacity',
                        'label' => 'Good for (pax)',
                        'name'  => 'room_capacity',
                        'type'  => 'number',
                        'min'   => 0,
                    ],
                    [
                        'key'    => 'field_lima_room_rate',
                        'label'  => 'Rate per night (PHP)',
                        'name'   => 'room_rate',
                        'type'   => 'number',
                        'min'    => 0,
                        'prepend'=> '₱',
                    ],
                    [
                        'key'   => 'field_lima_room_note',
                        'label' => 'Short note',
                        'name'  => 'room_note',
                        'type'  => 'text',
                    ],
                ],
            ],
            [
                'key'               => 'field_lima_additional_bed_rate',
                'label'             => 'Additional bed rate (PHP)',
                'name'              => 'additional_bed_rate',
                'type'              => 'number',
                'instructions'      => 'Optional. Cost of an extra bed.',
                'min'               => 0,
                'prepend'           => '₱',
                'conditional_logic' => [[['field' => 'field_lima_pricing_mode', 'operator' => '==', 'value' => 'rooms']]],
            ],

            // ── per_pax ──────────────────────────────────────────────────
            [
                'key'               => 'field_lima_perpax_base_rate',
                'label'             => 'Base rate (PHP / night)',
                'name'              => 'per_pax_base_rate',
                'type'              => 'number',
                'instructions'      => 'Price covering up to the base pax below.',
                'min'               => 0,
                'prepend'           => '₱',
                'conditional_logic' => [[['field' => 'field_lima_pricing_mode', 'operator' => '==', 'value' => 'per_pax']]],
            ],
            [
                'key'               => 'field_lima_perpax_base_pax',
                'label'             => 'Base pax included',
                'name'              => 'per_pax_base_pax',
                'type'              => 'number',
                'instructions'      => 'Guests covered by the base rate (e.g. 4).',
                'min'               => 1,
                'conditional_logic' => [[['field' => 'field_lima_pricing_mode', 'operator' => '==', 'value' => 'per_pax']]],
            ],
            [
                'key'               => 'field_lima_perpax_extra_rate',
                'label'             => 'Extra per pax (PHP)',
                'name'              => 'per_pax_extra_rate',
                'type'              => 'number',
                'instructions'      => 'Added per guest beyond the base pax.',
                'min'               => 0,
                'prepend'           => '₱',
                'conditional_logic' => [[['field' => 'field_lima_pricing_mode', 'operator' => '==', 'value' => 'per_pax']]],
            ],
            [
                'key'               => 'field_lima_perpax_max_pax',
                'label'             => 'Maximum pax',
                'name'              => 'per_pax_max_pax',
                'type'              => 'number',
                'instructions'      => 'Upper guest limit (e.g. 11).',
                'min'               => 1,
                'conditional_logic' => [[['field' => 'field_lima_pricing_mode', 'operator' => '==', 'value' => 'per_pax']]],
            ],

            // ── extra charges (any mode) ─────────────────────────────────
            [
                'key'          => 'field_lima_pricing_extras',
                'label'        => 'Extra charges (add-ons)',
                'name'         => 'pricing_extras',
                'type'         => 'repeater',
                'instructions' => 'Optional add-on charges shown under the price. Free-text label, e.g. Adult ₱300, Young adult ₱200, Pet fee, Extra mattress. Works with any pricing mode.',
                'button_label' => 'Add charge',
                'layout'       => 'table',
                'sub_fields'   => [
                    [
                        'key'   => 'field_lima_extra_label',
                        'label' => 'Label',
                        'name'  => 'extra_label',
                        'type'  => 'text',
                    ],
                    [
                        'key'     => 'field_lima_extra_amount',
                        'label'   => 'Amount (PHP)',
                        'name'    => 'extra_amount',
                        'type'    => 'number',
                        'min'     => 0,
                        'prepend' => '₱',
                    ],
                    [
                        'key'   => 'field_lima_extra_note',
                        'label' => 'Note',
                        'name'  => 'extra_note',
                        'type'  => 'text',
                    ],
                ],
            ],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'accommodation']]],
        'menu_order'            => 5,
        'position'              => 'normal',
        'label_placement'       => 'top',
        'active'                => true,
        'description'           => 'Limasawa flexible pricing — registered in code (limasawa-rates.php).',
    ]);
});
