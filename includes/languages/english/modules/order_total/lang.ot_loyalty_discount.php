<?php
// -----
// Part of the "Customer Loyalty Discount", a Zen Cart order-total module.
//
// Last updated: v3.0.0 (renamed from ot_loyalty_discount.php)
//
$define = [
    'MODULE_LOYALTY_DISCOUNT_TITLE' => 'Loyalty Discount',
    'MODULE_LOYALTY_DISCOUNT_DESCRIPTION' => 'Customer Loyalty Discount',

    // -----
    // %1$s is replaced by the discount percentage.
    // %2$s is *conditionally* replaced by MODULE_LOYALTY_DISCOUNT_SHIPPING_TEXT or MODULE_LOYALTY_DISCOUNT_SHIPPING_WITH_TAX_TEXT, based on configuration
    // %3$s is *conditionally* replaced by MODULE_LOYALTY_DISCOUNT_TAX_TEXT, based on configuration
    //
    'MODULE_LOYALTY_DISCOUNT_INFO' => 'Because of your previous purchases with us, this order qualifies for a discount of %1$s on its products%2$s%3$s.',
        'MODULE_LOYALTY_DISCOUNT_SHIPPING_TEXT' => ' and shipping-cost',
        'MODULE_LOYALTY_DISCOUNT_SHIPPING_WITH_TAX_TEXT' => ', shipping-cost',
        'MODULE_LOYALTY_DISCOUNT_TAX_TEXT' =< ' and associated taxes',
];
return $define;
