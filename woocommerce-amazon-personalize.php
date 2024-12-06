<?php
ob_start();
session_start();

/*
// Plugin Name: AWS Personalize Recommendations for WooCommerce
// Description: Proporciona recomendaciones de productos utilizando AWS Personalize.
// Version: 1.0
// Author: Sofia Lain
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente.
}



require_once WP_CONTENT_DIR . '/aws/aws-autoloader.php';
// Importa las clases necesarias del AWS SDK
use Aws\PersonalizeEvents\PersonalizeEventsClient;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\PersonalizeRuntime\PersonalizeRuntimeClient;




add_action('admin_menu', 'apr_add_admin_menu');

function apr_add_admin_menu() {
    add_options_page(
        'AWS Personalize Settings',
        'AWS Personalize',
        'manage_options',
        'aws-personalize',
        'apr_options_page'
    );
}


function apr_options_page() {
    ?>
    <form action='options.php' method='post'>
        <?php
        settings_fields('apr_settings_group');
        do_settings_sections('apr_settings_group');
        ?>
        <h2>Configuraci&oacute;n de AWS Personalize</h2>
        <table>
            <tr>
                <th scope="row">AWS Access Key ID</th>
                <td><input type="text" name="apr_access_key_id" value="<?php echo esc_attr(get_option('apr_access_key_id')); ?>" /></td>
            </tr>
            <tr>
                <th scope="row">AWS Secret Access Key</th>
                <td><input type="password" name="apr_secret_access_key" value="<?php echo esc_attr(get_option('apr_secret_access_key')); ?>" /></td>
            </tr>
            <tr>
                <th scope="row">Regi&oacute;n de AWS</th>
                <td><input type="text" name="apr_region" value="<?php echo esc_attr(get_option('apr_region')); ?>" /></td>
            </tr>
            <tr>
                <th scope="row">ID de la campa&ntildea por Producto de AWS Personalize</th>
                <td><input type="text" name="apr_campaign_arn" value="<?php echo esc_attr(get_option('apr_campaign_arn')); ?>" /></td>
            </tr>
            <tr>
                <th scope="row">ID de la campa&ntildea por Usuario de AWS Personalize</th>
                <td><input type="text" name="apr_campaign_user_arn" value="<?php echo esc_attr(get_option('apr_campaign_user_arn')); ?>" /></td>
            </tr>
            <tr>
                <th scope="row">AWS Personalize Tracking ID</th>
                <td><input type="text" name="apr_tracking_id" value="<?php echo esc_attr(get_option('apr_tracking_id')); ?>" /></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

add_action('admin_init', 'apr_settings_init');

function apr_settings_init() {
    register_setting('apr_settings_group', 'apr_access_key_id');
    register_setting('apr_settings_group', 'apr_secret_access_key');
    register_setting('apr_settings_group', 'apr_region');
    register_setting('apr_settings_group', 'apr_campaign_arn');
    register_setting('apr_settings_group', 'apr_tracking_id'); // Registro del Tracking ID
    register_setting('apr_settings_group', 'apr_campaign_user_arn'); //Registro campaña user
}





function apr_get_recommendations($itemId) {
    $client = new PersonalizeRuntimeClient([
        'region' => get_option('apr_region'),
        'version' => 'latest',
        'credentials' => [
            'key' => get_option('apr_access_key_id'),
            'secret' => get_option('apr_secret_access_key'),
        ],
    ]);
         $itemId = (string) $itemId;
         error_log('Item ID: ' . $itemId);
    try {
           $result = $client->getRecommendations([
        'campaignArn' => get_option('apr_campaign_arn'),
        'itemId' => $itemId, 

        ]);


        return $result['itemList'];
    } catch (Exception $e) {
        error_log('Error fetching recommendations: ' . $e->getMessage());
        return [];
    }
}



//Remover los productos relacionados de WooCommerce
function remove_woocommerce_related_products() {
    remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
}
add_action( 'init', 'remove_woocommerce_related_products' );


add_action('woocommerce_after_single_product_summary', 'apr_display_recommendations', 15);

function apr_display_recommendations() {
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
    } else {
        $user_id = session_id();
    }
    
    global $product;
    $item_id = $product->get_id();

    $recommendations = apr_get_recommendations($item_id);

    if (!empty($recommendations)) {
        echo '<h3>Productos Recomendados para Ti</h3>';
        echo '<ul class="products columns-4 related">';
        $i=0;
        foreach ($recommendations as $item) {
            $product_id = $item['itemId'];
            $product = wc_get_product($product_id);
            if ($product) {
                echo '<li class="product"><a href="' . get_permalink($product_id) . '">' . $product->get_image() . '' . $product->get_name() . '</a></li>';
                $i++;
            }
            
            if ($i==4){break;}
        }
        echo '</ul>';
    }
}








// Capturar evento "add_to_cart"
add_action('woocommerce_add_to_cart', 'apr_record_add_to_cart_event', 10, 6);

function apr_record_add_to_cart_event($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
    } else {
        $user_id = 'guest';
    }

    $item_id = $product_id;

    report_event_to_personalize($user_id, $item_id, 'AddToCart');
}


// Capturar evento "view"
add_action('woocommerce_after_single_product', 'apr_record_view_event');

function apr_record_view_event() {
    global $product;

    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
    } else {
        $user_id = 'guest';
    }

    $item_id = $product->get_id();

    report_event_to_personalize($user_id, $item_id, 'View');
}

// Capturar evento "purchase"
add_action('woocommerce_thankyou', 'apr_record_purchase_event', 10, 1);

function apr_record_purchase_event($order_id) {
    error_log("Order completed: " . $order_id); // Log para verificar

    $order = wc_get_order($order_id);
    $user_id = $order->get_user_id();
    if (!$user_id) {
        $user_id = 'guest';
    }

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        report_event_to_personalize($user_id, $product_id, 'purchase');
    }
}

function report_event_to_personalize($user_id, $item_id, $event_type) {
    error_log("Reporting event: $event_type for user $user_id and item $item_id"); // Log para verificar

    $client = new Aws\PersonalizeEvents\PersonalizeEventsClient([
        'region' => get_option('apr_region'),
        'version' => 'latest',
        'credentials' => [
            'key' => get_option('apr_access_key_id'),
            'secret' => get_option('apr_secret_access_key'),
        ],
    ]);

    try {
        $client->putEvents([
            'trackingId' => get_option('apr_tracking_id'),
            'userId'     => (string)$user_id,
            'sessionId'  => session_id(),
            'eventList'  => [
                [
                    'eventType' => $event_type,
                    'properties' =>json_encode(['itemId' => (string)$item_id]),
                    'sentAt'    => time(),
                ],
            ],
        ]);
        error_log("Event reported: $event_type for item $item_id");
    } catch (Aws\Exception\AwsException $e) {
        error_log('Error al reportar evento: ' . $e->getMessage());
    }
}

// Registrar un widget personalizado para recomendaciones
add_action('widgets_init', 'apr_register_recommendations_widget');

function apr_register_recommendations_widget() {
    register_widget('APR_User_Recommendations_Widget');
}

class APR_User_Recommendations_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'apr_user_recommendations_widget',
            __('AWS Recomendaciones Personalizadas por Usuario', 'text_domain'),
            array('description' => __('Muestra productos recomendados basados en el usuario.', 'text_domain'))
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];

        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        try {
            $this->apr_display_recommendations();
        } catch (Exception $e) {
            echo '<p>Hubo un error al cargar las recomendaciones.</p>';
            error_log('Error en widget de recomendaciones: ' . $e->getMessage());
        }

        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : __('Productos Recomendados', 'text_domain');
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Título:'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
        return $instance;
    }


public function apr_get_recommendations_by_user($userId) {
    error_log('entro funcion');
    $client = new PersonalizeRuntimeClient([
        'region' => get_option('apr_region'),
        'version' => 'latest',
        'credentials' => [
            'key' => get_option('apr_access_key_id'),
            'secret' => get_option('apr_secret_access_key'),
        ],
    ]);
    
    $userId = (string) $userId;
    error_log('User ID: ' . $userId);

    // Validate campaign ARN
    $campaignArn = get_option('apr_campaign_user_arn');
    if (!is_string($campaignArn) || empty($campaignArn)) {
        error_log('Invalid or missing Campaign ARN. Ensure it is correctly configured.');
        return []; // Return an empty array if the ARN is invalid
    }

    try {
        $result = $client->getRecommendations([
            'campaignArn' => $campaignArn,
            'userId' => $userId,
        ]);

        error_log("Recommendations fetched successfully");

        // Validate and return itemList
        if (isset($result['itemList']) && is_array($result['itemList'])) {
            return $result['itemList'];
        } else {
            error_log('itemList is missing or invalid in the response');
            return [];
        }

    } catch (Exception $e) {
        error_log('Error describing campaign: ' . $e->getMessage());
        return []; // Return an empty array on exception
    }
}


public function apr_display_recommendations() {
    if (is_user_logged_in()) {
        $user_id = get_current_user_id(); // Fetch current logged-in user ID
    } else {
        $user_id = session_id(); // Use session ID for guests
    }
    $i=0;
    $recommendations = $this->apr_get_recommendations_by_user($user_id); // Fetch recommendations based on user ID

    if (!empty($recommendations)) {
        echo '<ul class="products columns-1 userrelated">';
        foreach ($recommendations as $item) {
            $product_id = $item['itemId']; // Recommended product ID
            $product = wc_get_product($product_id); // Get WooCommerce product
            if ($product) {
                echo '<li class="product">';
                echo '<a href="' . esc_url(get_permalink($product_id)) . '">';
                echo $product->get_image(); // Product image
                echo esc_html($product->get_name()); // Product name
                echo '</a>';
                echo '</li>';
                 $i++;
            }
           
            if($i==4){break;}
        }
        echo '</ul>';
    } else {
        echo '<p>No hay recomendaciones disponibles para ti.</p>'; // Fallback message if no recommendations
    }
    }
}
