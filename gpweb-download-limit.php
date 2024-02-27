<?php
/*
Plugin Name: WooCommerce Free Download Limit
Description: Hiển thị một popup chứa một selector ngôn ngữ cho người dùng (cần các plugin sau : gtranslate, kadence-blocks, kadence-blocks-pro)
Version: 1.0
Author: Le Ngan
*/

register_activation_hook(__FILE__, 'gpweb_download_limit_activate');
function gpweb_download_limit_activate()
{
    update_option('default_free_downloads_per_day', 2);
}


add_action('woocommerce_checkout_process', 'gpweb_free_download_limit_check', 10, 2);

function gpweb_free_download_limit_check()
{
    $count_free_download = 0;

    $cart = WC()->cart;
    foreach ($cart->cart_contents as $cart_item) {
        if ($cart_item["line_total"] == 0
            && ($cart_item["data"] instanceof WC_Product)
            && $cart_item["data"]->is_downloadable()) {
            $count_free_download++;
        }
    }

    if ($count_free_download > 0) {
        $free_downloads_remaining = gpweb_get_free_downloads_remaining();
        if ($count_free_download > $free_downloads_remaining) {
            $message = sprintf(
                __('Rất tiếc! Mỗi ngày bạn chỉ có thể tải miễn phí %d lần trên 1 tài khoản, vui lòng đợi đến ngày mai để tiếp tục tải.', 'gpweb'),
                gpweb_get_free_downloads_per_day()
            );
            throw new Exception($message);
        }
    }
}

function gpweb_get_free_downloads_per_day()
{
    return get_user_meta(get_current_user_id(), 'free_downloads_per_day', true) ?: get_option('default_free_downloads_per_day');
}

function gpweb_get_free_downloads_remaining()
{
    return max(0, gpweb_get_free_downloads_per_day() - gpweb_get_free_downloads_today());
}

function gpweb_get_free_downloads_today()
{
    $count_free_download = 0;

    $downloads = WC()->customer->get_downloadable_products();
    foreach ($downloads as $download) {
        $product_id = $download["product_id"] ?? 0;
        $order = wc_get_order($download["order_id"]);

        if (!$order || !$product_id) {
            continue;
        }

        $date = $order->get_date_paid();
        if ($date->format('Y-m-d') != date('Y-m-d')) {
            continue;
        }

        $items = $order->get_items();

        foreach ($items as $item) {
            $data = $item->get_data();
            if (!isset($data["product_id"]) || !isset($item["total"])) {
                continue;
            }
            if ($data["product_id"] == $product_id && $item["total"] == 0) {
                $count_free_download++;
            }
        }
    }

    return $count_free_download;
}

add_shortcode('free_downloads_remaining', 'gpweb_get_free_downloads_remaining');

add_action('wc_ajax_download_free_file', 'download_free_file_action');
function download_free_file_action()
{
    if (!isset($_POST['product_id'])) {
        return;
    }

    $product_id = $_POST['product_id'];
    $product = wc_get_product($product_id);
    if (!$product || $product->get_price() != 0) {
        wp_send_json(["message" => "Sản phẩm không hợp lệ."]);
        return;
    }

    if (gpweb_get_free_downloads_remaining() <= 0) {
        wp_send_json(["message" => "Hết lượt tải miễn phí."]);
        return;
    }

    $order = wc_create_order();
    $order->set_customer_id(get_current_user_id());
    $order->add_product($product, 1);
    $order->calculate_totals();
    $order->set_status("completed");
    $order->save();

    $items = $order->get_items();
    $item = reset($items);
    $downloads = $item->get_item_downloads();
    if (!empty($downloads)) {
        $download = reset($downloads);
        wp_send_json([
            "file_name" => $download["name"],
            "download_url" => $download["download_url"],
        ]);
        return;
    }

    wp_send_json(["message" => "Sản phẩm không có tệp tải về."]);

}