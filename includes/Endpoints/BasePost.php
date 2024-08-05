<?php

namespace VPlugins\SMPostConnector\Endpoints;

use WP_REST_Request;
use WP_REST_Response;
use VPlugins\SMPostConnector\Middleware\AuthMiddleware;

abstract class BasePost {
    protected $auth_middleware;

    public function __construct() {
        $this->auth_middleware = new AuthMiddleware();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    abstract public function register_routes();

    protected function handle_post_request(WP_REST_Request $request, $is_update = false) {
        $post_id = $is_update ? $request->get_param('id') : null;

        if ($is_update && !$post_id) {
            return new WP_REST_Response(['status' => 400, 'message' => 'Post ID is required for updating.'], 400);
        }

        if ($is_update) {
            $post = get_post($post_id);
            if (!$post) {
                return new WP_REST_Response(['status' => 404, 'message' => 'Post not found.'], 404);
            }
        }

        $title = $request->get_param('title');
        $content = $request->get_param('content');
        $status = $request->get_param('status');
        $date = $request->get_param('date');
        $author_id = $request->get_param('author');
        $categories = $request->get_param('category');
        $tags = $request->get_param('tag');
        $featured_image_url = $request->get_param('featured_image');

        if (empty($title) || empty($content) || empty($status) || empty($author_id)) {
            return new WP_REST_Response(['status' => 400, 'message' => 'Missing required parameters.'], 400);
        }

        $valid_statuses = ['publish', 'future', 'draft'];
        if (!in_array($status, $valid_statuses)) {
            return new WP_REST_Response(['status' => 400, 'message' => 'Invalid post status.'], 400);
        }

        if ($status === 'future' && empty($date)) {
            return new WP_REST_Response(['status' => 400, 'message' => 'Date is required for future posts.'], 400);
        }

        if ($status === 'publish' && !empty($date) && strtotime($date) > time()) {
            return new WP_REST_Response(['status' => 400, 'message' => 'Date for publish status must be a past date.'], 400);
        }

        if (!$is_update && get_page_by_title($title, OBJECT, 'post')) {
            return new WP_REST_Response(['status' => 400, 'message' => 'A post with this title already exists.'], 400);
        }

        $attachment_id = 0;
        if (!empty($featured_image_url)) {
            $image_data = $this->download_image($featured_image_url);
            if ($image_data['status'] === 'error') {
                return new WP_REST_Response(['status' => 400, 'message' => $image_data['message']], 400);
            }
            $attachment_id = $this->upload_image($image_data['file_path']);
        }

        $post_data = [
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_kses_post($content),
            'post_status'  => $status,
            'post_date'    => ($status === 'future') ? date('Y-m-d H:i:s', strtotime($date)) : current_time('mysql'),
            'post_author'  => (int) $author_id,
            'post_category'=> !empty($categories) ? array_map('intval', $categories) : [],
            'tags_input'   => !empty($tags) ? array_map('sanitize_text_field', $tags) : [],
            'meta_input'   => $is_update ? ['updated_by_sm_plugin' => true] : ['added_by_sm_plugin' => true]
        ];

        if ($is_update) {
            $post_data['ID'] = $post_id;
            $result_post_id = wp_update_post($post_data);
        } else {
            $result_post_id = wp_insert_post($post_data);
        }

        if ($result_post_id && $attachment_id) {
            set_post_thumbnail($result_post_id, $attachment_id);
        }

        if ($result_post_id) {
            $post_url = get_permalink($result_post_id);
            return new WP_REST_Response([
                'status' => 200,
                'data'   => [
                    'post_id'  => $result_post_id,
                    'post_url' => $post_url,
                ]
            ], 200);
        }

        return new WP_REST_Response(['status' => 500, 'message' => 'Failed to ' . ($is_update ? 'update' : 'create') . ' post.'], 500);
    }

    protected function download_image($image_url) {
        $response = wp_remote_get($image_url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return ['status' => 'error', 'message' => 'Failed to download image.'];
        }

        $file_path = wp_upload_dir()['path'] . '/' . basename($image_url);
        file_put_contents($file_path, wp_remote_retrieve_body($response));

        return ['status' => 'success', 'file_path' => $file_path];
    }

    protected function upload_image($file_path) {
        $wp_filetype = wp_check_filetype(basename($file_path), null);
        $attachment = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name(basename($file_path)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attach_id = wp_insert_attachment($attachment, $file_path);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }
}