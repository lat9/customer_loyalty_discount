<?php
/**
 * Order Total Module
 *
 * @package - Loyalty Disccount
 * @copyright Copyright 2007-2008 Numinix Technology http://www.numinix.com
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: ot_loyalty_discount.php 2019-11-24 15:15:00 webchills
 */
class ot_loyalty_discount
{
    public
        $code,
        $title,
        $description,
        $enabled,
        $sort_order,
        $output;

    protected
        $_check,
        $currency_decimal_places,
        $discount_table,
        $od_pc,
        $period_string,
        $cum_order_total;

    public function __construct()
    {
        $this->code = 'ot_loyalty_discount';
        $this->title = MODULE_LOYALTY_DISCOUNT_TITLE;
        $this->description = MODULE_LOYALTY_DISCOUNT_DESCRIPTION;

        $this->sort_order = defined('MODULE_LOYALTY_DISCOUNT_SORT_ORDER') ? MODULE_LOYALTY_DISCOUNT_SORT_ORDER : null;
        if (null === $this->sort_order) {
            return false;
        }

        $this->enabled = (MODULE_LOYALTY_DISCOUNT_STATUS === 'true');
        if ($this->enabled === false) {
            return false;
        }

        $this->output = [];
        $this->od_pc = 0;

        // -----
        // No loyalty-discount for guest-checkout purchases!
        //
        if (IS_ADMIN_FLAG === false && zen_in_guest_checkout() === true) {
            $this->enabled = false;
        } else {
            $this->validateConfiguration();
        }
    }

    // -----
    // Check the order-total's configuration to see if the discount-table specification
    // is 'processable'.
    //
    // The table's format is in the form 'vvv:ppp[,vvv:ppp]' where
    //
    // 1. Each 'vvv' represents the minimum value of the customer's previous order's amount
    //    for which the 'ppp' percentage discount is to apply.  Each 'vvv' and 'ppp' value
    //    is a numeric value, e.g. '5' or '5.00'.
    // 2. Each 'vvv' value specified must be greater than the previous 'vvv' value.
    //
    protected function validateConfiguration()
    {
        $this->discount_table = [];

        $discount_table = explode(',', str_replace(' ', '', MODULE_LOYALTY_DISCOUNT_TABLE));
        $configuration_message = '';
        if (empty($discount_table)) {
            $configuration_message = 'No discounts configured.';
        } else {
            $last_discount_amount = 0;
            foreach ($discount_table as $next_discount) {
                if (strpos($next_discount, ':') === false) {
                    $configuration_message = "Invalid discount-table format [$next_discount].";
                    break;
                }
                list($discount_amount, $discount_percentage) = explode(':', $next_discount);
                if (!is_numeric($discount_amount) || !is_numeric($discount_percentage)) {
                    $configuration_message = "Invalid discount amount [$next_discount].";
                    break;
                }
                if ($discount_amount <= $last_discount_amount) {
                    $configuration_message = "Discount amounts must be in ascending order [$next_discount].";
                    break;
                }
                $this->discount_table[$discount_amount] = $discount_percentage;
                $last_discount_amount = $discount_amount;
            }
        }
        if ($configuration_message !== '') {
            $this->enabled = false;
            if (IS_ADMIN_FLAG === true) {
                $this->title .= '<small><span class="alert">(' . $configuration_message . ')</span></small>';
            }
        }
    }

    protected function currencyValue($currency_value)
    {
        return number_format((float)$currency_value, $this->currency_decimal_places, '.', '');
    }

    public function process()
    {
        global $order, $currencies;

        // -----
        // If the constructor has determined that the "Loyalty Discount" should be disabled or if
        // there's currently a coupon applied to the order, nothing further to do.
        //
        if ($this->enabled === false || !empty($_SESSION['cc_id'])) {
            return;
        }

        $this->currency_decimal_places = $currencies->get_decimal_places($order->info['currency']);

        $discount_amount = $this->calculateCredit();
        if ($discount_amount > 0) {
            $tmp = sprintf(MODULE_LOYALTY_DISCOUNT_INFO, $this->period_string, $currencies->format($this->cum_order_total), $this->od_pc . '%');
            $this->output[] = [
                'title' => '<div class="ot_loyalty_title">' . $this->title . ':</div>' . $tmp,
                'text' => '-' . $currencies->format($discount_amount),
                'value' => $discount_amount,
            ];
        }
    }

    protected function calculateCredit()
    {
        global $order;

        // -----
        // If the customer doesn't qualify for a discount, quick return.  Note that
        // the discount value returned is saved in the class variable od_pc.
        //
        $discount = $this->getOrderDiscount();
        if ($discount === false) {
            return 0;
        }

        // -----
        // Determine the 'tax groups' associated with the products and (possibly)
        // shipping, initializing each element to 0; the following section will
        // then add any non-gift-certificate taxes to each group.
        //
        $tax_groups = $order->info['tax_groups'];
        foreach ($tax_groups as $key => $value) {
            $tax_groups[$key] = 0;
        }

        // -----
        // Gather up the product-related taxes into their associated tax-groups and
        // as a total
        // Note: Gift certificates/vouchers aren't included in any discount, so their
        // cost will not 'count' towards the customer's discount-basis.
        //
        $gv_amount = 0;
        $non_gv_tax = 0;
        foreach ($order->products as $next_product) {
            $current_amount = $this->currencyValue($next_product['final_price'] * $next_product['qty']);
            if (strpos($next_product['model'], 'GIFT') !== 0) {
                foreach ($next_product['tax_groups'] as $key => $tax_rate) {
                    $product_tax = $this->currencyValue(zen_calculate_tax($current_amount, $tax_rate));
                    $non_gv_tax += $product_tax;
                    $tax_groups[$key] += $product_tax;
                }
            } else {
                $gv_amount += $current_amount;
            }
        }

        // -----
        // Determine the pricing basis for the loyalty discount, starting with the
        // order's current subtotal, less any gift-certificate value.
        //
        // Note that the order, at this point, contains the products' final pricing
        // and their taxes and the shipping cost ... as well as any additional order-totals
        // (e.g. ot_cod_fee).  If the sort-order of the shipping order-total is less than that
        // configured for the loyalty-discount, its tax is also present.
        //
        // Note: If the store sets this order-total's sort-order to be less than the shipping
        // order-total's and indicates that the discount should include shipping unwanted
        // results will occur!
        //
        $discount_basis = $this->currencyValue($order->info['subtotal']) - $gv_amount;

        // -----
        // If the loyalty-discount is also to apply to the order's tax, the customer's
        // discount basis also includes the tax associated with any non-gift-certificate
        // products.  Further, if the order's discount is also to apply to the shipping
        // cost, the shipping-tax is also part of the discount-basis.
        //
        $shipping_tax = 0;
        if (MODULE_LOYALTY_DISCOUNT_INC_TAX === 'true') {
            $discount_basis += $non_gv_tax;
            if (MODULE_LOYALTY_DISCOUNT_INC_SHIPPING === 'true') {
                $shipping_tax = $this->currencyValue($order->info['shipping_tax']);
                $discount_basis += $shipping_tax;
                if (isset($_SESSION['shipping_tax_description'])) {
                    $tax_groups[$_SESSION['shipping_tax_description']] += $shipping_tax;
                }
            }
        }

        // -----
        // If the loyalty-discount is also to apply to the order's shipping-cost, add
        // that value to the customer's discount basis.
        //
        if (MODULE_LOYALTY_DISCOUNT_INC_SHIPPING === 'true') {
            $discount_basis += $this->currencyValue($order->info['shipping_cost']);
        }

        // -----
        // Determine the currency value of the to-be-applied discount.
        //
        $discount = $this->currencyValue($discount_basis * $this->od_pc / 100);

        // -----
        // Apply the discount to the order's total value.  The 'process' method
        // of this order-total will provide the additional order-total information
        // to apply to the order's display to the customer.
        //
        $order->info['total'] = $this->currencyValue($order->info['total']) - $discount;

        // -----
        // If the order's tax is to be recalculated, it only *really* needs to
        // be recalculated if this order-total is discounting the tax!
        //
        if (MODULE_LOYALTY_DISCOUNT_CALC_TAX === 'true' && MODULE_LOYALTY_DISCOUNT_INC_TAX === 'true') {
            // -----
            // Determine the subtraction to be made to the order's overall tax.  This is the
            // non-gift-certificate products' tax plus (if shipping is to be included in the
            // discount, the shipping tax.
            //
            $discounted_taxes = $non_gv_tax;
            if (MODULE_LOYALTY_DISCOUNT_INC_SHIPPING === 'true') {
                $discounted_taxes += $shipping_tax;
            }
            $discounted_taxes_value = $this->currencyValue($discounted_taxes * $this->od_pc / 100);

            $discount -= $discounted_taxes_value;
            $order->info['tax'] = $this->currencyValue($order->info['tax']) - $discounted_taxes_value;

            // Calculate tax group deductions
            foreach ($order->info['tax_groups'] as $key => $value) {
                $order->info['tax_groups'][$key] = $this->currencyValue($value) - $this->currencyValue($tax_groups[$key] * $this->od_pc / 100);
            }
        }

        return $discount;
    }

    protected function getOrderDiscount()
    {
        $order_discount = false;
        $cumulative_order_total = $this->getCumulativeOrderTotal();
        foreach ($this->discount_table as $amount => $discount) {
            if ($cumulative_order_total >= $amount) {
                $order_discount = $discount;
                $this->od_pc = $order_discount;
            }
        }
        return $order_discount;
    }

    protected function getCumulativeOrderTotal()
    {
        global $db;

        if (MODULE_LOYALTY_DISCOUNT_ORDER_STATUS === '') {
            $orders_status_clause = '';
        } elseif (strpos(MODULE_LOYALTY_DISCOUNT_ORDER_STATUS, ',') === false) {
            $orders_status_clause = ' AND orders_status >= ' . MODULE_LOYALTY_DISCOUNT_ORDER_STATUS;
        } else {
            $orders_status_clause = ' AND orders_status IN (' . MODULE_LOYALTY_DISCOUNT_ORDER_STATUS . ')';
        }

        $history_query_raw =
            "SELECT date_purchased, order_total
               FROM " . TABLE_ORDERS . "
              WHERE customers_id = " . $_SESSION['customer_id'] .
              $orders_status_clause;
        $history_query = $db->Execute($history_query_raw);
        $this->cum_order_total = 0;
        if (!$history_query->EOF) {
            $cutoff_timestamp = $this->get_cutoff_timestamp();
            foreach ($history_query as $next_order) {
                if ($this->get_date_in_period($cutoff_timestamp, $next_order['date_purchased']) === true) {
                    $this->cum_order_total += $this->currencyValue($next_order['order_total']);
                }
            }
        }

        return $this->cum_order_total;
    }

    protected function get_cutoff_timestamp()
    {
        switch (MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD) {
            case 'year':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_YEAR;
                $cutoff_timestamp = strtotime('-1 year');
                break;

            case 'quarter':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_QUARTER;
                $cutoff_timestamp = strtotime('-3 month');
                break;

            case 'month':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_MONTH;
                $cutoff_timestamp = strtotime('-1 month');
                break;

            case 'alltime':
            default:
                $this->period_string = MODULE_LOYALTY_DISCOUNT_WITHUS;
                $cutoff_timestamp = 0;
                break;
        }
        return $cutoff_timestamp;
    }

    protected function get_date_in_period($cutoff_timestamp, $raw_date)
    {
        if ($raw_date === '0000-00-00 00:00:00' || empty($raw_date)) {
            return false;
        }
        return strtotime($raw_date) >= $cutoff_timestamp;
    }

    public function check()
    {
        global $db;

        if (!isset($this->_check)) {
            $check_query = $db->Execute(
                "SELECT configuration_value
                   FROM " . TABLE_CONFIGURATION . "
                  where configuration_key = 'MODULE_LOYALTY_DISCOUNT_STATUS'"
            );
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    public function keys()
    {
        return [
            'MODULE_LOYALTY_DISCOUNT_STATUS',
            'MODULE_LOYALTY_DISCOUNT_SORT_ORDER',
            'MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD',
            'MODULE_LOYALTY_DISCOUNT_TABLE',
            'MODULE_LOYALTY_DISCOUNT_INC_SHIPPING',
            'MODULE_LOYALTY_DISCOUNT_INC_TAX',
            'MODULE_LOYALTY_DISCOUNT_CALC_TAX',
            'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS',
        ];
    }

    public function install()
    {
        global $db;

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
             VALUES
                ('Enable Discount?', 'MODULE_LOYALTY_DISCOUNT_STATUS', 'false', 'Do you want to enable the Loyalty Discount?', 6, 1, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Sort Order', 'MODULE_LOYALTY_DISCOUNT_SORT_ORDER', '998', 'Sort order of display.', 6, 2, NULL, now()),

                ('Include Shipping?', 'MODULE_LOYALTY_DISCOUNT_INC_SHIPPING', 'true', 'Should an order\'s shipping cost be included in the discount calculation?', 6, 3, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Include Tax?', 'MODULE_LOYALTY_DISCOUNT_INC_TAX', 'true', 'Should an order\'s tax, product and <em>optionally</em> shipping, be included in the discount calculation?', 6, 4, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Recalculate Tax?', 'MODULE_LOYALTY_DISCOUNT_CALC_TAX', 'false', 'Recalculate the order\'s tax based on the discounted amount. <b>Note:</b> This setting is used only if you\'ve also indicated that the discount should apply to an order\'s tax.', 6, 5, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Cumulative Order Period', 'MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD', 'year', 'Set the period over which to calculate the cumulative order total.', 6, 6, 'zen_cfg_select_option([\'alltime\', \'year\', \'quarter\', \'month\'], ', now()),

                ('Discount Percentage', 'MODULE_LOYALTY_DISCOUNT_TABLE', '1000:5,1500:7.5,2000:10', 'Set the cumulative order total breaks for the period set above and discount percentages.<br><br>The default value (<code>1000:5,1500:7.5,2000:10</code>) gives the customer:<ol><li>A 5% discount for a total &gt; 1000.</li><li>A 7.5% discount for a total &gt; 1500.</li><li>A 10% discount for a total &gt; 2000.</li></ol>', 6, 7, NULL, now()),

                ('Qualifying Order Status', 'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS', '3', 'Identify the order-status id(s) for a previously-placed order to be part of the cumulative orders\' totals used to determine the discount percentage.<ol><li>If the entry is empty, then <b>all</b> previously-placed orders are summed.</li><li>If the entry is a <em>single</em> id, than any order with an order-status greater than or equal to that value is included.</li><li>Otherwise, the entry is a comma-separated list of order-status ids and any order with a <em>current</em> order-status in that list is included.</li></ol>', 6, 8, NULL, now())"
        );
    }

    public function remove()
    {
        global $db;

        $db->Execute(
            "DELETE FROM " . TABLE_CONFIGURATION . "
              WHERE configuration_key in ('" . implode("', '", $this->keys()) . "')"
        );
    }
}
