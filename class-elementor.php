<?php

namespace SLCA\Elementor;

use wpCloud\StatelessMedia\Compatibility;
use wpCloud\StatelessMedia\Utility;

/**
 * Class Elementor 
 */
class Elementor extends Compatibility {
  protected $id = 'elementor';
  protected $title = 'Elementor Website Builder';
  protected $constant = 'WP_STATELESS_COMPATIBILITY_ELEMENTOR';
  protected $description = 'Ensures compatibility with Elementor Website Builder. Sync css files generated by Elementor.';
  protected $plugin_file = 'elementor/elementor.php';
  protected $non_library_sync = true;

  /**
   * @param $sm
   */
  public function module_init($sm) {
    add_filter('set_url_scheme', array($this, 'sync_rewrite_url'), 10, 3);
    add_action('elementor/core/files/clear_cache', array($this, 'delete_elementor_files'));
    add_action('save_post', array($this, 'delete_css_files'), 10, 3);
    add_action('deleted_post', array($this, 'delete_css_files'));
    add_filter("elementor/settings/general/success_response_data", array($this, 'delete_global_css'), 10, 3);
    add_action('sm::pre::sync::nonMediaFiles', array($this, 'filter_css_file'), 10, 2);
  }

  /**
   * Sync local elementor files to GCS.
   * @param $url
   * @param $_ Not used
   * @param $__ Not used
   * @return string
   */
  public function sync_rewrite_url($url, $_, $__) {
    try {
      if (strpos($url, 'elementor/') !== false) {
        $wp_uploads_dir = wp_get_upload_dir();
        $name = str_replace($wp_uploads_dir['baseurl'] . '/', '', $url);

        if ($name != $url) {
          $absolutePath = $wp_uploads_dir['basedir'] . '/' . $name;
          $name = apply_filters('wp_stateless_file_name', $name, 0);
          do_action('sm:sync::syncFile', $name, $absolutePath);

          $mode = ud_get_stateless_media()->get('sm.mode');
          if ($mode && !in_array($mode, ['disabled', 'backup'])) {
            $url = ud_get_stateless_media()->get_gs_host() . '/' . $name;
          }
        }
      }
    } catch (\Exception $e) {
      error_log($e->getMessage());
    }

    return $url;
  }

  /**
   * To regenerate/delete files click Regenerate Files in
   * Elementor >> Tools >> General >> Regenerate CSS
   * All files will be deleted from GCS.
   * And will be copied to GCS again on next page view.
   */
  public function delete_elementor_files() {
    do_action('sm:sync::deleteFiles', 'elementor/');
  }

  /**
   * Delete GCS file on update/delete post.
   * @param $post_ID
   * @param null $post
   * @param null $update
   */
  public function delete_css_files($post_ID, $post = null, $update = null) {
    if ($update || current_action() === 'deleted_post') {
      $post_css = new \Elementor\Core\Files\CSS\Post($post_ID);

      // elementor/ css/ 'post-' . $post_id . '.css'
      $name = $post_css::UPLOADS_DIR . $post_css::DEFAULT_FILES_DIR . $post_css->get_file_name();
      $name = apply_filters('wp_stateless_file_name', $name, 0);

      do_action('sm:sync::deleteFile', $name);
    }
  }

  /**
   * Delete elementor global css file when global style is updated on Elementor Editor.
   * @param $success_response_data
   * @param $id
   * @param $data
   * @return mixed
   */
  public function delete_global_css($success_response_data, $id, $data) {
    try {
      $post_css = new \Elementor\Core\Files\CSS\Global_CSS('global.css');
      // elementor/ css/ 'global.css'
      $name = $post_css::UPLOADS_DIR . $post_css::DEFAULT_FILES_DIR . $post_css->get_file_name();
      $name = apply_filters('wp_stateless_file_name', $name, 0);
      do_action('sm:sync::deleteFile', $name);
    } catch (\Exception $e) {
    }
    // We are in filter so need to return the passed value.
    return $success_response_data;
  }

  /**
   * @param $name
   * @param $absolutePath
   */
  public function filter_css_file($name, $absolutePath) {
    $upload_data = wp_upload_dir();
    if (!empty($upload_data) && file_exists($absolutePath)) {
      try {
        if ( !function_exists('WP_Filesystem') ) {
          require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        WP_Filesystem();
        global $wp_filesystem;

        $content = $wp_filesystem->get_contents($absolutePath);

        if (!empty($upload_data['baseurl']) && !empty($content)) {
          $baseurl = preg_replace('/https?:\/\//', '', $upload_data['baseurl']);
          $root_dir = trim(ud_get_stateless_media()->get('sm.root_dir'), '/ '); // Remove any forward slash and empty space.
          $root_dir = apply_filters("wp_stateless_handle_root_dir", $root_dir);
          $root_dir = !empty($root_dir) ? $root_dir . '/' : '';
          $image_host = ud_get_stateless_media()->get_gs_host() . $root_dir;
          $file_ext = ud_get_stateless_media()->replaceable_file_types();

          preg_match_all('/(https?:\/\/' . str_replace('/', '\/', $baseurl) . ')\/(.+?)(' . $file_ext . ')/i', $content, $matches);

          if (!empty($matches)) {
            foreach ($matches[0] as $key => $match) {
              $id = attachment_url_to_postid($match);
              if (!empty($id)) {
                Utility::add_media(null, $id, true);
              }
            }
          }

          $content = preg_replace('/(https?:\/\/' . str_replace('/', '\/', $baseurl) . ')\/(.+?)(' . $file_ext . ')/i', $image_host . '/$2$3', $content);
         
          $wp_filesystem->put_contents($absolutePath, $content);

          preg_match('/post-(\d+).css/', $name, $match);

          if (!empty($match[1])) {
            $_elementor_css = get_post_meta($match[1], '_elementor_css', true);
            if (!empty($_elementor_css)) {
              $_elementor_css['time'] = time();
            }
          }
        }
      } catch (\Exception $e) {
      }
    }
  }
}
