<?php
// -----
// Part of the "Customer Loyalty Discount", a Zen Cart order-total module.
//
define('MODULE_LOYALTY_DISCOUNT_TITLE', 'Loyalty Discount');
define('MODULE_LOYALTY_DISCOUNT_DESCRIPTION', 'Customer Loyalty Discount');

// -----
// %1$s is replaced by the discount percentage.
// %2$s is *conditionally* replaced by MODULE_LOYALTY_DISCOUNT_SHIPPING_TEXT or MODULE_LOYALTY_DISCOUNT_SHIPPING_WITH_TAX_TEXT, based on configuration
// %3$s is *conditionally* replaced by MODULE_LOYALTY_DISCOUNT_TAX_TEXT, based on configuration
//
define('MODULE_LOYALTY_DISCOUNT_INFO', 'Because of your previous purchases with us, this order qualifies for a discount of %1$s on its products%2$s%3$s.');
    define('MODULE_LOYALTY_DISCOUNT_SHIPPING_TEXT', ' and shipping-cost');
    define('MODULE_LOYALTY_DISCOUNT_SHIPPING_WITH_TAX_TEXT', ', shipping-cost');
    define('MODULE_LOYALTY_DISCOUNT_TAX_TEXT', ' and associated taxes');
