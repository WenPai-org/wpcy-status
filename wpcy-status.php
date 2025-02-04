<?php
/**
 * Plugin Name: WPCY Status
 * Description: 允许用户手动检测多个外部服务状态，从用户端和服务器端分别检测可用性。
 * Version: 1.0
 * Author: WPCY.COM
 */

if (!defined('ABSPATH')) {
    exit;
}

// 允许已登录和未登录用户调用 AJAX 端点
add_action('wp_ajax_wpcy_check_status', 'wpcy_check_status');
add_action('wp_ajax_nopriv_wpcy_check_status', 'wpcy_check_status');

function wpcy_check_status() {
    // 移除登录检查
    // if (!is_user_logged_in()) {
    //     wp_send_json_error(['message' => '请先登录'], 403);
    // }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'wpcy_nonce')) {
        wp_send_json_error(['message' => '无效的安全验证'], 403);
    }

    if (!isset($_POST['url'])) {
        wp_send_json_error(['message' => '缺少 URL 参数'], 400);
    }

    $url = esc_url_raw(wp_unslash($_POST['url']));
    $response = wp_remote_get($url, ['timeout' => 5]);

    if (is_wp_error($response)) {
        wp_send_json_error(['status' => '❌', 'error_message' => $response->get_error_message()]);
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code === 200) {
        wp_send_json_success(['status' => '✅', 'message' => '服务器端访问正常']);
    } else {
        wp_send_json_error(['status' => '❌', 'error_message' => "HTTP 状态码: $http_code"]);
    }
}

// 注册 Shortcode
add_shortcode('wpcy_status', 'wpcy_status_shortcode');

function wpcy_status_shortcode($atts) {
    $atts = shortcode_atts([
        'services' => ''
    ], $atts);

    $services = array_filter(array_map('trim', explode(',', $atts['services'])));
    if (empty($services)) {
        return '<p>请提供至少一个要检测的服务 URL。</p>';
    }

    ob_start();
    ?>
    <table class="wpcy-status-table">
        <thead>
            <tr>
                <th>服务图标</th>
                <th>服务域名</th>
                <th>用户端</th>
                <th>服务器端</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($services as $service) : ?>
                <tr class="wpcy-service" data-url="<?php echo esc_attr($service); ?>">
                    <td><img src="https://cn.cravatar.com/favicon/api/index.php?url=<?php echo esc_url($service); ?>" alt="Favicon" width="25" height="25"></td>
                    <td><?php echo esc_html(preg_replace('/^(https?:\/\/)?(www\.)?/', '', $service)); ?></td>
                    <td class="wpcy-client-status">⏳</td>
                    <td class="wpcy-server-status">⏳</td>
                    <td><button class="wpcy-check-btn">检测</button></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php if (is_user_logged_in()) : ?>
        <button id="wpcy-check-all" class="wpcy-check-btn">一键检测所有服务</button>
    <?php endif; ?>

    <script>
document.addEventListener("DOMContentLoaded", function() {
    async function checkService(row) {
        let url = row.getAttribute("data-url");
        let clientStatus = row.querySelector(".wpcy-client-status");
        let serverStatus = row.querySelector(".wpcy-server-status");

        clientStatus.textContent = "⏳";
        serverStatus.textContent = "⏳";

        // 用户端检测
        try {
            let clientResponse = await fetch(url, { method: "GET", mode: "no-cors" });
            clientStatus.textContent = "✅"; 
        } catch {
            clientStatus.textContent = "❌";
        }

        // 服务器端检测
        try {
            let response = await fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    action: "wpcy_check_status",
                    url: url,
                    nonce: "<?php echo wp_create_nonce('wpcy_nonce'); ?>"
                })
            });

            let result = await response.json();
            serverStatus.textContent = result.success ? "✅" : "❌";
        } catch {
            serverStatus.textContent = "❌";
        }
    }

    function enableButton(button, text) {
        button.textContent = text;
        button.disabled = false;
    }

    document.querySelectorAll(".wpcy-check-btn").forEach(button => {
        if (button.id === "wpcy-check-all") return;

        button.addEventListener("click", async function() {
            this.textContent = "检测中...";
            this.disabled = true;
            let row = this.closest(".wpcy-service");
            await checkService(row);
            enableButton(this, "检测");
        });
    });

    <?php if (is_user_logged_in()) : ?>
    document.getElementById("wpcy-check-all").addEventListener("click", async function() {
        this.textContent = "检测中...";
        this.disabled = true;
        let buttons = document.querySelectorAll(".wpcy-service .wpcy-check-btn");

        for (let button of buttons) {
            button.textContent = "检测中...";
            button.disabled = true;
        }

        let rows = document.querySelectorAll(".wpcy-service");
        await Promise.all(Array.from(rows).map(row => checkService(row)));

        for (let button of buttons) {
            enableButton(button, "检测");
        }

        enableButton(this, "一键检测所有服务");
    });
    <?php endif; ?>
});
    </script>

    <style>
    .wpcy-status-table {
        width: 100%;
        border-collapse: collapse;
        text-align: center;
    }

    .wpcy-status-table th, .wpcy-status-table td {
        border: 1px solid #ddd;
        padding: 10px;
        font-size: 14px;
    }

    .wpcy-check-btn {
        padding: 5px 20px;
        border-radius: 10px;
        cursor: pointer;
    }

    .wpcy-status-table td {
        min-width: 100px;
    }

    .wpcy-client-status, .wpcy-server-status {
        font-weight: bold;
    }

    .wpcy-client-status, .wpcy-server-status {
        color: black;
    }

    .wpcy-client-status:contains('✅'), 
    .wpcy-server-status:contains('✅') {
        color: green;
    }

    .wpcy-client-status:contains('❌'), 
    .wpcy-server-status:contains('❌') {
        color: red;
    }
    </style>
    <?php
    return ob_get_clean();
}
?>