<?php

namespace Views;

final class Order
{
    public static function prepArray(array $order, array $optionPricing = [], string $tax = '')
    {
        return [
            'id'            => $order['id'],
            'orderId'       => $order['orderid'],
            'orderNumber'   => $order['order_number'],
            'invoiceId'     => $order['invoiceid'],
            'subtotal'      => $order['subtotal']->toFull(),
            'discount'      => $order['discount']->toFull(),
            'total'         => $order['total']->toFull(),
            'tax'           => $tax,
            'optionPricing' => $optionPricing,
        ];
    }
}